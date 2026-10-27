<?php
/**
 * Tipología «WooCommerce»: editar productos en todos sus idiomas.
 *   POST /wp-json/eg/v1/product
 * Elige los productos por SKU (sku), ID (ids) o ruta (paths) y, salvo with_translations: false, también sus
 * traducciones de WPML. Los cambios van en set (todos los idiomas) y en langs (por idioma, {"ca": {...}}):
 *   title, excerpt, content      texto completo nuevo
 *   slug                         URL nueva del producto; la vieja redirige (301) a la nueva
 *   remove_blocks                quita del extracto y del contenido los bloques (h1-h6, p, li, div o líneas
 *                                sueltas) cuyo texto visible contiene alguna de estas cadenas
 *   replace                      {"de": "a"}: sustituye solo en el texto visible del extracto y el contenido
 *   meta                         campos de JetEngine del producto y rank_math_title/description/focus_keyword
 *   add_terms, remove_terms      {"product_cat": ["preventa"]}: slugs en cualquier idioma; se usa el término
 *                                del idioma de cada producto
 * Con dry_run devuelve lo que cambiaría sin guardar. Los textos se guardan sin wp_update_post para que WPML no
 * lance la traducción automática del resto de idiomas. Vacía la caché del producto (WooCommerce y WP Rocket).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'eg_pub_modules', function ( $modules ) {
	$modules['productos'] = array( 'type' => 'woocommerce', 'routes' => array( 'product' ) );
	return $modules;
} );

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_module_enabled( 'productos' ) ) {
		return;
	}
	register_rest_route( 'eg/v1', '/product', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_product_handle',
		'permission_callback' => eg_pub_module_permission( 'edit_others_products' ),
	) );
} );

/** Campos de meta que se pueden cambiar: los de JetEngine para productos y los de SEO de Rank Math. */
function eg_pub_product_meta_keys() {
	$keys  = array( 'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword', '_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw', '_yoast_wpseo_canonical' );
	$boxes = get_option( 'jet_engine_meta_boxes', array() );
	foreach ( is_array( $boxes ) ? $boxes : array() as $box ) {
		if ( in_array( 'product', (array) ( $box['args']['allowed_post_type'] ?? array() ), true ) ) {
			foreach ( (array) ( $box['meta_fields'] ?? array() ) as $field ) {
				if ( ! empty( $field['name'] ) ) {
					$keys[] = $field['name'];
				}
			}
		}
	}
	return array_values( array_unique( $keys ) );
}

/** IDs de los términos con esos slugs y sus traducciones, en el idioma $lang si WPML lo sabe. */
function eg_pub_product_terms( $tax, $slugs, $lang ) {
	global $wpdb;
	$ids = array();
	foreach ( (array) $slugs as $slug ) {
		$found = $wpdb->get_col( $wpdb->prepare(
			"SELECT t.term_id FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s AND t.slug = %s",
			$tax,
			sanitize_title( $slug )
		) );
		foreach ( $found as $term_id ) {
			$ids[]   = (int) $term_id;
			$in_lang = $lang ? apply_filters( 'wpml_object_id', (int) $term_id, $tax, false, $lang ) : null;
			if ( $in_lang ) {
				$ids[] = (int) $in_lang;
			}
		}
	}
	return array_values( array_unique( $ids ) );
}

/** Quita los bloques cuyo texto visible contenga alguna de las cadenas. */
function eg_pub_product_remove_blocks( $html, $needles ) {
	$has = function ( $text ) use ( $needles ) {
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
		foreach ( $needles as $needle ) {
			if ( '' !== $needle && false !== mb_stripos( $text, $needle ) ) {
				return true;
			}
		}
		return false;
	};
	$html = preg_replace_callback( '~<(h[1-6]|p|li|div)\b[^>]*>.*?</\1>[ \t]*(\r?\n)*~is', function ( $m ) use ( $has ) {
		return $has( $m[0] ) ? '' : $m[0];
	}, $html );
	// Líneas sueltas, sin etiqueta de bloque (el editor clásico separa párrafos con saltos de línea).
	$lines = preg_split( '~(\r?\n)~', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
	$out   = '';
	for ( $i = 0; $i < count( $lines ); $i += 2 ) {
		$line = $lines[ $i ];
		$eol  = $lines[ $i + 1 ] ?? '';
		if ( '' !== trim( $line ) && ! preg_match( '~<(h[1-6]|p|li|div|ul|ol)\b~i', $line ) && $has( $line ) ) {
			continue;
		}
		$out .= $line . $eol;
	}
	return ltrim( $out, "\r\n" );
}

/** Sustituye solo en el texto visible, nunca dentro de etiquetas. */
function eg_pub_product_replace( $html, $pairs ) {
	$parts = preg_split( '~(<[^>]*>)~', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
	foreach ( $parts as $i => $part ) {
		if ( '' !== $part && '<' !== $part[0] ) {
			$parts[ $i ] = strtr( $part, $pairs );
		}
	}
	return implode( '', $parts );
}

function eg_pub_product_handle( WP_REST_Request $r ) {
	global $wpdb;
	if ( ! function_exists( 'wc_get_product' ) ) {
		return eg_pub_error( 'eg_woo', 'WooCommerce no está activo en esta web.', 500 );
	}
	$p       = $r->get_params();
	$dry_run = ! empty( $p['dry_run'] );
	$notes   = array();

	$ids = array_map( 'intval', (array) ( $p['ids'] ?? array() ) );
	foreach ( (array) ( $p['sku'] ?? array() ) as $sku ) {
		// Todos los productos con ese SKU (con WPML, la traducción comparte el SKU del original).
		$found = $wpdb->get_col( $wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} po ON po.ID = pm.post_id WHERE pm.meta_key = '_sku' AND pm.meta_value = %s AND po.post_type = 'product' AND po.post_status NOT IN ('trash', 'auto-draft')",
			sanitize_text_field( $sku )
		) );
		if ( ! $found ) {
			$notes[] = 'no hay ningún producto con el SKU ' . $sku;
		}
		$ids = array_merge( $ids, array_map( 'intval', $found ) );
	}
	foreach ( (array) ( $p['paths'] ?? array() ) as $path ) {
		$id = eg_pub_status_find_path( $path );
		if ( $id ) {
			$ids[] = $id;
		} else {
			$notes[] = 'no encuentro ' . $path;
		}
	}
	if ( ! isset( $p['with_translations'] ) || $p['with_translations'] ) {
		foreach ( $ids as $id ) {
			$trid = apply_filters( 'wpml_element_trid', null, $id, 'post_product' );
			foreach ( (array) apply_filters( 'wpml_get_element_translations', null, $trid, 'post_product' ) as $tr ) {
				if ( ! empty( $tr->element_id ) ) {
					$ids[] = (int) $tr->element_id;
				}
			}
		}
	}
	$ids = array_values( array_unique( array_filter( $ids ) ) );
	if ( ! $ids ) {
		return eg_pub_error( 'eg_missing', 'No hay ningún producto: indica sku, ids o paths. ' . implode( ' | ', $notes ), 404 );
	}

	$set       = is_array( $p['set'] ?? null ) ? $p['set'] : array();
	$langs     = is_array( $p['langs'] ?? null ) ? $p['langs'] : array();
	$meta_keys = eg_pub_product_meta_keys();
	$done      = array();

	foreach ( $ids as $id ) {
		$post = get_post( $id );
		if ( ! $post || 'product' !== $post->post_type ) {
			$notes[] = $id . ' no es un producto';
			continue;
		}
		$details = apply_filters( 'wpml_post_language_details', null, $id );
		$lang    = is_array( $details ) ? (string) ( $details['language_code'] ?? '' ) : '';
		$c       = array_merge( $set, is_array( $langs[ $lang ] ?? null ) ? $langs[ $lang ] : array() );
		$row     = array( 'id' => $id, 'lang' => $lang, 'title' => $post->post_title, 'url' => get_permalink( $id ), 'changes' => array() );

		// Textos: título, extracto y contenido.
		$fields = array( 'post_title' => $post->post_title, 'post_excerpt' => $post->post_excerpt, 'post_content' => $post->post_content );
		foreach ( array( 'title' => 'post_title', 'excerpt' => 'post_excerpt', 'content' => 'post_content' ) as $key => $field ) {
			if ( isset( $c[ $key ] ) ) {
				$fields[ $field ] = wp_kses_post( (string) $c[ $key ] );
			}
		}
		if ( ! empty( $c['remove_blocks'] ) ) {
			$needles = array_map( 'strval', (array) $c['remove_blocks'] );
			foreach ( array( 'post_excerpt', 'post_content' ) as $field ) {
				$fields[ $field ] = eg_pub_product_remove_blocks( $fields[ $field ], $needles );
			}
		}
		if ( ! empty( $c['replace'] ) && is_array( $c['replace'] ) ) {
			foreach ( array( 'post_excerpt', 'post_content' ) as $field ) {
				$fields[ $field ] = eg_pub_product_replace( $fields[ $field ], array_map( 'strval', $c['replace'] ) );
			}
		}
		$update = array();
		foreach ( $fields as $field => $value ) {
			if ( $value !== $post->$field ) {
				$update[ $field ]          = $value;
				$row['changes'][ $field ] = array( 'before' => $post->$field, 'after' => $value );
			}
		}
		// Slug: la URL vieja queda en _wp_old_slug y WordPress la redirige (301) a la nueva.
		if ( isset( $c['slug'] ) ) {
			$slug = sanitize_title( (string) $c['slug'] );
			if ( '' !== $slug && $slug !== $post->post_name ) {
				$slug                         = wp_unique_post_slug( $slug, $id, $post->post_status, 'product', $post->post_parent );
				$update['post_name']          = $slug;
				$row['changes']['post_name'] = array( 'before' => $post->post_name, 'after' => $slug );
			}
		}

		// Campos de JetEngine y SEO.
		$meta = array();
		foreach ( (array) ( $c['meta'] ?? array() ) as $key => $value ) {
			if ( ! in_array( $key, $meta_keys, true ) ) {
				$notes[] = 'campo no permitido: ' . $key;
				continue;
			}
			$old = get_post_meta( $id, $key, true );
			if ( (string) $old !== (string) $value ) {
				$meta[ $key ]                    = wp_kses_post( (string) $value );
				$row['changes'][ 'meta:' . $key ] = array( 'before' => $old, 'after' => $meta[ $key ] );
			}
		}

		// Categorías y etiquetas.
		$terms = array();
		foreach ( array( 'add_terms' => 'add', 'remove_terms' => 'remove' ) as $key => $op ) {
			foreach ( (array) ( $c[ $key ] ?? array() ) as $tax => $slugs ) {
				$tax = sanitize_key( $tax );
				if ( ! taxonomy_exists( $tax ) ) {
					$notes[] = 'no existe la taxonomía ' . $tax;
					continue;
				}
				$current = wp_get_object_terms( $id, $tax, array( 'fields' => 'ids', 'suppress_filters' => true ) );
				$current = is_wp_error( $current ) ? array() : array_map( 'intval', $current );
				$wanted  = eg_pub_product_terms( $tax, $slugs, $lang );
				if ( 'add' === $op ) {
					// Solo el término del idioma del producto.
					$wanted = array_values( array_filter( $wanted, function ( $t ) use ( $tax, $lang ) {
						$t_lang = apply_filters( 'wpml_element_language_code', null, array( 'element_id' => $t, 'element_type' => $tax ) );
						return ! $t_lang || ! $lang || $t_lang === $lang;
					} ) );
					$wanted = array_values( array_diff( $wanted, $current ) );
				} else {
					$wanted = array_values( array_intersect( $wanted, $current ) );
				}
				if ( $wanted ) {
					$terms[]                            = array( $op, $tax, $wanted );
					$row['changes'][ $op . ':' . $tax ] = array_map( function ( $t ) {
						$term = get_term( $t );
						return $term && ! is_wp_error( $term ) ? $term->slug : $t;
					}, $wanted );
				}
			}
		}

		if ( ! $dry_run && $row['changes'] ) {
			if ( $update ) {
				$update['post_modified']     = current_time( 'mysql' );
				$update['post_modified_gmt'] = current_time( 'mysql', true );
				$wpdb->update( $wpdb->posts, $update, array( 'ID' => $id ) );
			}
			if ( isset( $update['post_name'] ) ) {
				// Lo mismo que hace wp_check_for_changed_slugs() al guardar desde el editor.
				delete_post_meta( $id, '_wp_old_slug', $update['post_name'] );
				if ( '' !== $post->post_name && ! in_array( $post->post_name, (array) get_post_meta( $id, '_wp_old_slug' ), true ) ) {
					add_post_meta( $id, '_wp_old_slug', $post->post_name );
				}
			}
			foreach ( $meta as $key => $value ) {
				update_post_meta( $id, $key, $value );
			}
			foreach ( $terms as list( $op, $tax, $term_ids ) ) {
				'add' === $op ? wp_add_object_terms( $id, $term_ids, $tax ) : wp_remove_object_terms( $id, $term_ids, $tax );
			}
			if ( $terms && class_exists( 'WC_Cache_Helper' ) ) {
				// wc_get_product_terms() guarda los términos del producto en la caché de objetos, que WooCommerce
				// solo invalida al guardar el producto: sin esto, con Redis la ficha sigue mostrando los atributos de antes.
				WC_Cache_Helper::invalidate_cache_group( 'product_' . $id );
			}
		}
		$done[] = $row;
	}

	if ( ! $dry_run ) {
		// Volver a leer: WPML puede copiar campos entre idiomas al guardar y así se ve lo que ha quedado.
		foreach ( $done as $i => $row ) {
			$id = $row['id'];
			clean_post_cache( $id );
			wc_delete_product_transients( $id );
			if ( function_exists( 'rocket_clean_post' ) ) {
				rocket_clean_post( $id );
			}
			foreach ( $row['changes'] as $key => $change ) {
				if ( 0 === strpos( $key, 'meta:' ) ) {
					$now = get_post_meta( $id, substr( $key, 5 ), true );
					if ( (string) $now !== (string) $change['after'] ) {
						$notes[] = $id . ': ' . $key . ' ha quedado como «' . $now . '»';
					}
				}
			}
		}
	}

	return new WP_REST_Response( array(
		'ok'       => true,
		'dry_run'  => $dry_run,
		'count'    => count( $done ),
		'products' => $done,
		'message'  => implode( ' | ', $notes ),
	), 200 );
}
