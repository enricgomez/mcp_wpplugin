<?php
/**
 * Buscar y sustituir textos en toda la web (módulo opcional «Textos»):
 *   POST /wp-json/eg/v1/text
 * Sin "replace" lista dónde aparece cada cadena de "search" (títulos, contenido, extracto, JSON de Elementor,
 * plantillas de cabecera/pie, menús, metas de Rank Math y algunas opciones). Con "replace" sustituye, solo en
 * el texto visible: nunca dentro de etiquetas HTML ni en enlaces, para no romper URLs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_TEXT_META    = array( '_elementor_data', 'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword' );
const EG_PUB_TEXT_OPTIONS = array( 'blogname', 'blogdescription', 'rank-math-options-titles' );
// Claves de Elementor que no son texto visible.
const EG_PUB_TEXT_SKIP_KEYS = array( 'url', 'id', 'elType', 'widgetType', '_element_id', '_css_classes', 'css_classes', 'link', 'custom_css', 'html_tag' );

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_module_enabled( 'textos' ) ) {
		return;
	}
	register_rest_route( 'eg/v1', '/text', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_text_handle',
		'permission_callback' => eg_pub_module_permission( 'manage_options' ),
	) );
} );

/** Sustituye solo fuera de las etiquetas HTML. Devuelve el texto nuevo y suma los cambios en $count. */
function eg_pub_text_swap( $str, $pairs, &$count ) {
	return preg_replace_callback( '/(<[^>]*>)|([^<]+)/u', function ( $m ) use ( $pairs, &$count ) {
		if ( '' !== $m[1] ) {
			return $m[1];
		}
		foreach ( $pairs as $from => $to ) {
			$m[2] = str_replace( $from, $to, $m[2], $n );
			$count += $n;
		}
		return $m[2];
	}, $str );
}

/** Fragmentos de texto alrededor de cada aparición (máximo 5). */
function eg_pub_text_snippets( $str, $needles ) {
	$plain = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( str_replace( '<', ' <', $str ) ) ) );
	$out   = array();
	foreach ( $needles as $needle ) {
		$at = 0;
		while ( count( $out ) < 5 && false !== ( $pos = mb_stripos( $plain, $needle, $at ) ) ) {
			$start = max( 0, $pos - 60 );
			$out[] = ( $start ? '…' : '' ) . mb_substr( $plain, $start, mb_strlen( $needle ) + 120 ) . '…';
			$at    = $pos + mb_strlen( $needle );
		}
		if ( ! $out && false !== mb_stripos( $str, $needle ) ) {
			$out[] = '(solo dentro de una etiqueta o enlace) ' . mb_substr( $str, max( 0, mb_stripos( $str, $needle ) - 60 ), 140 );
		}
	}
	return $out;
}

function eg_pub_text_has( $str, $needles ) {
	foreach ( $needles as $needle ) {
		if ( false !== mb_stripos( $str, $needle ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Recorre un valor (JSON de Elementor u opción en array). En modo búsqueda apunta cada texto que coincide con
 * su ruta (widget y clave); en modo sustitución devuelve el valor cambiado.
 */
function eg_pub_text_walk( $node, $needles, $pairs, &$hits, &$count, $where = '' ) {
	if ( is_string( $node ) ) {
		if ( $pairs ) {
			return eg_pub_text_swap( $node, $pairs, $count );
		}
		if ( eg_pub_text_has( $node, $needles ) ) {
			$hits[] = array( 'where' => $where, 'text' => eg_pub_text_snippets( $node, $needles ) );
		}
		return $node;
	}
	if ( ! is_array( $node ) ) {
		return $node;
	}
	$label = $where;
	if ( isset( $node['elType'] ) ) {
		$label = ( $node['widgetType'] ?? $node['elType'] ) . '#' . ( $node['id'] ?? '' );
	}
	foreach ( $node as $k => $v ) {
		if ( is_string( $k ) && in_array( $k, EG_PUB_TEXT_SKIP_KEYS, true ) ) {
			continue;
		}
		$node[ $k ] = eg_pub_text_walk( $v, $needles, $pairs, $hits, $count, is_string( $k ) && 'elements' !== $k && 'settings' !== $k ? $label . '.' . $k : $label );
	}
	return $node;
}

/** IDs de entradas que contienen alguna cadena (en el formato tal cual o escapado como en el JSON de Elementor). */
function eg_pub_text_candidates( $needles, $post_types ) {
	global $wpdb;
	$likes_p = array();
	$likes_m = array();
	foreach ( $needles as $needle ) {
		foreach ( array_unique( array( $needle, substr( wp_json_encode( $needle ), 1, -1 ) ) ) as $form ) {
			$like      = '%' . $wpdb->esc_like( $form ) . '%';
			$likes_p[] = $wpdb->prepare( '(p.post_title LIKE %s OR p.post_content LIKE %s OR p.post_excerpt LIKE %s)', $like, $like, $like );
			$likes_m[] = $wpdb->prepare( 'm.meta_value LIKE %s', $like );
		}
	}
	$types  = "'" . implode( "','", array_map( 'esc_sql', $post_types ) ) . "'";
	$status = "p.post_status IN ('publish','draft','pending','private','future')";
	$keys   = "'" . implode( "','", array_map( 'esc_sql', EG_PUB_TEXT_META ) ) . "'";
	$ids    = $wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type IN ($types) AND $status AND (" . implode( ' OR ', $likes_p ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL
	$ids2   = $wpdb->get_col( "SELECT DISTINCT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID WHERE p.post_type IN ($types) AND $status AND m.meta_key IN ($keys) AND (" . implode( ' OR ', $likes_m ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL
	$all    = array_map( 'intval', array_unique( array_merge( $ids, $ids2 ) ) );
	sort( $all );
	return $all;
}

function eg_pub_text_handle( WP_REST_Request $r ) {
	$p       = $r->get_params();
	$needles = array_values( array_filter( array_map( 'strval', (array) ( $p['search'] ?? array() ) ), 'strlen' ) );
	$pairs   = array();
	foreach ( (array) ( $p['replace'] ?? array() ) as $from => $to ) {
		if ( is_array( $to ) ) { // Admite [{"from": "...", "to": "..."}] además de {"de": "a"}.
			$from = (string) ( $to['from'] ?? '' );
			$to   = (string) ( $to['to'] ?? '' );
		}
		if ( '' !== (string) $from ) {
			$pairs[ (string) $from ] = (string) $to;
		}
	}
	// Las cadenas largas primero, para que «Reformas Integrales» se cambie antes que «Reformas».
	uksort( $pairs, function ( $a, $b ) {
		return strlen( $b ) - strlen( $a );
	} );
	if ( $pairs ) {
		$needles = array_keys( $pairs );
	}
	if ( ! $needles ) {
		return eg_pub_error( 'eg_missing', 'Falta search (cadenas a buscar) o replace (pares de sustitución).' );
	}
	$dry_run    = ! $pairs || ! empty( $p['dry_run'] );
	$only_ids   = array_map( 'strval', (array) ( $p['ids'] ?? array() ) );
	$post_types = ! empty( $p['post_types'] )
		? array_map( 'sanitize_key', (array) $p['post_types'] )
		: array_values( array_diff( array_merge( get_post_types( array( 'public' => true ) ), array( 'elementor_library', 'nav_menu_item', 'wp_block' ) ), array( 'attachment' ) ) );

	$found   = array();
	$changed = array();
	$total   = 0;
	foreach ( eg_pub_text_candidates( $needles, $post_types ) as $id ) {
		if ( $only_ids && ! in_array( (string) $id, $only_ids, true ) ) {
			continue;
		}
		$post = get_post( $id );
		$lang = apply_filters( 'wpml_post_language_details', null, $id );
		$row  = array(
			'id'     => $id,
			'type'   => $post->post_type,
			'status' => $post->post_status,
			'lang'   => is_array( $lang ) ? ( $lang['language_code'] ?? '' ) : '',
			'title'  => $post->post_title,
			'url'    => 'nav_menu_item' === $post->post_type ? '' : get_permalink( $id ),
			'hits'   => array(),
		);
		$count  = 0;
		$update = array();
		foreach ( array( 'post_title', 'post_content', 'post_excerpt' ) as $field ) {
			if ( ! eg_pub_text_has( $post->$field, $needles ) ) {
				continue;
			}
			if ( $dry_run && ! $pairs ) {
				$row['hits'][] = array( 'where' => $field, 'text' => eg_pub_text_snippets( $post->$field, $needles ) );
			} else {
				$new = eg_pub_text_swap( $post->$field, $pairs, $count );
				if ( $new !== $post->$field ) {
					$update[ $field ] = $new;
				}
			}
		}
		foreach ( EG_PUB_TEXT_META as $key ) {
			$raw = get_post_meta( $id, $key, true );
			if ( '' === $raw || ! is_string( $raw ) ) {
				continue;
			}
			$val = '_elementor_data' === $key ? json_decode( $raw, true ) : $raw;
			if ( null === $val ) {
				continue;
			}
			$hits = array();
			$n    = 0;
			$new  = eg_pub_text_walk( $val, $needles, $dry_run && ! $pairs ? array() : $pairs, $hits, $n, $key );
			foreach ( $hits as $h ) {
				$row['hits'][] = $h;
			}
			if ( $n ) {
				$count += $n;
				if ( ! $dry_run ) {
					update_post_meta( $id, $key, wp_slash( '_elementor_data' === $key ? wp_json_encode( $new ) : $new ) );
				}
			}
		}
		if ( $update && ! $dry_run ) {
			$update['ID'] = $id;
			wp_update_post( wp_slash( $update ) );
		}
		if ( $pairs ) {
			if ( $count ) {
				$changed[] = array( 'id' => $id, 'type' => $post->post_type, 'lang' => $row['lang'], 'title' => $post->post_title, 'changes' => $count );
				$total    += $count;
			}
		} elseif ( $row['hits'] ) {
			$found[] = $row;
		}
	}

	foreach ( EG_PUB_TEXT_OPTIONS as $opt ) {
		if ( $only_ids && ! in_array( 'option:' . $opt, $only_ids, true ) ) {
			continue;
		}
		$val  = get_option( $opt );
		$hits = array();
		$n    = 0;
		$new  = eg_pub_text_walk( $val, $needles, $dry_run && ! $pairs ? array() : $pairs, $hits, $n, 'option:' . $opt );
		if ( $hits ) {
			$found[] = array( 'id' => 'option:' . $opt, 'type' => 'option', 'hits' => $hits );
		}
		if ( $n ) {
			$changed[] = array( 'id' => 'option:' . $opt, 'type' => 'option', 'changes' => $n );
			$total    += $n;
			if ( ! $dry_run ) {
				update_option( $opt, $new );
			}
		}
	}

	if ( $total && ! $dry_run ) {
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
	}

	if ( ! $pairs ) {
		return new WP_REST_Response( array( 'ok' => true, 'search' => $needles, 'count' => count( $found ), 'found' => $found ), 200 );
	}
	return new WP_REST_Response( array( 'ok' => true, 'dry_run' => $dry_run, 'total' => $total, 'changed' => $changed ), 200 );
}
