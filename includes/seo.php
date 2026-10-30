<?php
/**
 * SEO para cualquier contenido editable: posts, páginas y custom post types.
 * POST /wp-json/eg/v1/seo. Por defecto solo previsualiza; apply:true guarda.
 * Con "indexar" ({"post": true, "category": true}) cambia si Yoast deja indexar
 * todo un tipo de contenido o taxonomía (opción wpseo_titles, noindex-*).
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'eg_pub_modules', function ( $modules ) {
	$modules['seo'] = array( 'type' => 'gestion', 'routes' => array( 'seo' ) );
	return $modules;
} );

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_module_enabled( 'seo' ) ) {
		return;
	}
	register_rest_route( 'eg/v1', '/seo', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_seo_handle',
		'permission_callback' => 'eg_pub_permission',
	) );
} );

/** Solo los campos SEO permitidos, con claves nativas de cada plugin. */
function eg_pub_seo_meta_map() {
	return array(
		'seo_title'       => array( 'yoast' => '_yoast_wpseo_title', 'rank_math' => 'rank_math_title' ),
		'seo_description' => array( 'yoast' => '_yoast_wpseo_metadesc', 'rank_math' => 'rank_math_description' ),
		'focus_keyword'   => array( 'yoast' => '_yoast_wpseo_focuskw', 'rank_math' => 'rank_math_focus_keyword' ),
		'canonical_url'   => array( 'yoast' => '_yoast_wpseo_canonical', 'rank_math' => 'rank_math_canonical_url' ),
	);
}

/** Reutilizable desde blog y Elementor. No escribe en plugins SEO inactivos. */
function eg_pub_seo_save( $post_id, $values ) {
	$active = array();
	if ( defined( 'WPSEO_VERSION' ) ) {
		$active[] = 'yoast';
	}
	if ( defined( 'RANK_MATH_VERSION' ) ) {
		$active[] = 'rank_math';
	}
	foreach ( eg_pub_seo_meta_map() as $field => $plugins ) {
		if ( ! isset( $values[ $field ] ) || ! is_scalar( $values[ $field ] ) ) {
			continue;
		}
		$value = sanitize_text_field( (string) $values[ $field ] );
		if ( 'canonical_url' === $field ) {
			$value = esc_url_raw( $value );
		}
		foreach ( $active as $plugin ) {
			update_post_meta( $post_id, $plugins[ $plugin ], $value );
		}
	}
}

/** Devuelve diagnóstico orientativo; no simula ni promete puntuaciones de Yoast. */
function eg_pub_seo_audit( $post, $fields ) {
	$title = (string) ( $fields['seo_title'] ?? '' );
	$desc  = (string) ( $fields['seo_description'] ?? '' );
	$focus = (string) ( $fields['focus_keyword'] ?? '' );
	$body  = wp_strip_all_tags( $post->post_title . ' ' . $post->post_content );
	$issues = array();
	if ( '' === $title ) {
		$issues[] = 'Falta título SEO.';
	} elseif ( mb_strlen( $title ) > 60 ) {
		$issues[] = 'Título SEO de más de 60 caracteres; revisar vista previa.';
	}
	if ( '' === $desc ) {
		$issues[] = 'Falta metadescripción.';
	} elseif ( mb_strlen( $desc ) > 160 ) {
		$issues[] = 'Metadescripción de más de 160 caracteres; revisar vista previa.';
	}
	if ( '' === $focus ) {
		$issues[] = 'Falta frase clave objetivo.';
	} elseif ( false === mb_stripos( $body, $focus ) ) {
		$issues[] = 'La frase clave exacta no aparece en el título o contenido de WordPress (puede estar en Elementor).';
	}
	return array( 'issues' => $issues, 'title_length' => mb_strlen( $title ), 'description_length' => mb_strlen( $desc ) );
}

/** Ajuste global de Yoast: qué tipos de contenido y taxonomías salen en buscadores. */
function eg_pub_seo_indexing( $wanted, $apply ) {
	if ( ! defined( 'WPSEO_VERSION' ) ) {
		return eg_pub_error( 'eg_seo_plugin', 'El ajuste de indexación global solo está disponible con Yoast SEO.' );
	}
	$titles  = (array) get_option( 'wpseo_titles', array() );
	$changes = array();
	foreach ( (array) $wanted as $name => $index ) {
		$name = sanitize_key( $name );
		if ( post_type_exists( $name ) ) {
			$key = 'noindex-' . $name;
		} elseif ( taxonomy_exists( $name ) ) {
			$key = 'noindex-tax-' . $name;
		} else {
			return eg_pub_error( 'eg_invalid', 'No existe el tipo de contenido o taxonomía: ' . $name );
		}
		$noindex = ! rest_sanitize_boolean( $index );
		$before  = ! empty( $titles[ $key ] );
		if ( $before !== $noindex ) {
			$changes[ $name ] = array( 'indexable_before' => ! $before, 'indexable_after' => ! $noindex );
			$titles[ $key ]   = $noindex;
		}
	}
	if ( $apply && $changes ) {
		update_option( 'wpseo_titles', $titles );
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
	}
	$state = array();
	foreach ( $titles as $key => $value ) {
		if ( 0 === strpos( $key, 'noindex-' ) ) {
			$state[ substr( $key, 8 ) ] = empty( $value ) ? 'index' : 'noindex';
		}
	}
	return new WP_REST_Response( array( 'ok' => true, 'applied' => $apply, 'changes' => $changes, 'yoast_noindex' => $state ), 200 );
}

function eg_pub_seo_handle( WP_REST_Request $r ) {
	$p = $r->get_params();
	$apply = ! empty( $p['apply'] );
	if ( isset( $p['indexar'] ) ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return eg_pub_error( 'eg_forbidden', 'Cambiar la indexación global requiere permisos de administración.', 403 );
		}
		return eg_pub_seo_indexing( $p['indexar'], $apply );
	}
	$ids = array_map( 'intval', (array) ( $p['ids'] ?? array() ) );
	foreach ( (array) ( $p['paths'] ?? array() ) as $path ) {
		$id = eg_pub_status_find_path( $path );
		if ( $id ) {
			$ids[] = $id;
		}
	}
	$ids = array_values( array_unique( array_filter( $ids ) ) );
	if ( ! $ids ) {
		return eg_pub_error( 'eg_missing', 'Indica ids o paths de contenidos existentes.' );
	}
	if ( count( $ids ) > 100 ) {
		return eg_pub_error( 'eg_limit', 'Máximo 100 contenidos por petición.' );
	}
	if ( ! defined( 'WPSEO_VERSION' ) && ! defined( 'RANK_MATH_VERSION' ) ) {
		return eg_pub_error( 'eg_seo_plugin', 'No hay Yoast SEO ni Rank Math activos.' );
	}
	$rows = array();
	foreach ( $ids as $id ) {
		$post = get_post( $id );
		if ( ! $post || ! in_array( $post->post_type, get_post_types( array( 'public' => true ) ), true ) || ! current_user_can( 'edit_post', $id ) ) {
			return eg_pub_error( 'eg_forbidden', 'Contenido no editable o no encontrado: ' . $id, 403 );
		}
		$lang_details = apply_filters( 'wpml_post_language_details', null, $id );
		$lang = is_array( $lang_details ) ? ( $lang_details['language_code'] ?? '' ) : '';
		$values = array();
		foreach ( eg_pub_seo_meta_map() as $field => $keys ) {
			$key = defined( 'WPSEO_VERSION' ) ? $keys['yoast'] : $keys['rank_math'];
			$values[ $field ] = (string) get_post_meta( $id, $key, true );
		}
		$changes = array();
		$incoming = (array) ( $p['set'] ?? array() );
		if ( $lang && isset( $p['langs'][ $lang ] ) && is_array( $p['langs'][ $lang ] ) ) {
			$incoming = array_merge( $incoming, $p['langs'][ $lang ] );
		}
		foreach ( eg_pub_seo_meta_map() as $field => $keys ) {
			if ( ! array_key_exists( $field, $incoming ) || ! is_scalar( $incoming[ $field ] ) ) {
				continue;
			}
			$new = 'canonical_url' === $field ? esc_url_raw( (string) $incoming[ $field ] ) : sanitize_text_field( (string) $incoming[ $field ] );
			if ( $new !== $values[ $field ] ) {
				$changes[ $field ] = array( 'before' => $values[ $field ], 'after' => $new );
				$values[ $field ] = $new;
			}
		}
		if ( $apply && $changes ) {
			eg_pub_seo_save( $id, array_map( function ( $change ) { return $change['after']; }, $changes ) );
			if ( function_exists( 'rocket_clean_post' ) ) {
				rocket_clean_post( $id );
			}
		}
		$rows[] = array(
			'id' => $id, 'type' => $post->post_type, 'lang' => $lang,
			'title' => $post->post_title, 'status' => $post->post_status,
			'seo' => $values, 'changes' => $changes,
			'audit' => eg_pub_seo_audit( $post, $values ),
		);
	}
	return new WP_REST_Response( array( 'ok' => true, 'applied' => $apply, 'count' => count( $rows ), 'posts' => $rows ), 200 );
}
