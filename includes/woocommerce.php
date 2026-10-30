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
 *   regular_price, sale_price    precio normal y rebajado ("" quita la rebaja)
 *   sale_from, sale_to           fechas de la rebaja (AAAA-MM-DD o AAAA-MM-DD HH:MM, hora de la web; "" la quita)
 *   sku, weight, stock_status    SKU, peso (en la unidad de la tienda) e instock/outofstock/onbackorder
 *   status                       publish, draft, private o pending (también saca el producto de la papelera)
 *   image, gallery               imagen principal y galería: ID de la biblioteca, URL o {"url", "alt"}
 * create crea un producto nuevo (en borrador salvo que set diga otro status), vacío o copiado de otro con
 * create.from (ID o SKU), y le aplica set. list devuelve todos los productos con su estado, papelera incluida.
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

	if ( ! empty( $p['list'] ) ) {
		return new WP_REST_Response( array( 'ok' => true, 'products' => eg_pub_product_list() ), 200 );
	}

	$ids     = array_map( 'intval', (array) ( $p['ids'] ?? array() ) );
	$created = null;
	if ( is_array( $p['create'] ?? null ) ) {
		$created = eg_pub_product_create( $p['create'], $dry_run );
		if ( is_wp_error( $created ) ) {
			return eg_pub_error( 'eg_create', $created->get_error_message(), 400 );
		}
		if ( $dry_run ) {
			return new WP_REST_Response( array( 'ok' => true, 'dry_run' => true, 'create' => $created, 'set' => $p['set'] ?? array() ), 200 );
		}
		$ids[] = $created['id'];
	}
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

		// Datos de WooCommerce: precio, SKU, peso, existencias, imágenes y estado.
		$wc     = eg_pub_product_wc_changes( $id, $c, $dry_run, $notes );
		$status = null;
		if ( isset( $c['status'] ) ) {
			$wanted = sanitize_key( (string) $c['status'] );
			if ( ! in_array( $wanted, array( 'publish', 'draft', 'private', 'pending' ), true ) ) {
				$notes[] = 'estado no válido: ' . $wanted;
			} elseif ( $wanted !== $post->post_status ) {
				$status                    = $wanted;
				$row['changes']['status'] = array( 'before' => $post->post_status, 'after' => $wanted );
			}
		}
		foreach ( $wc['changes'] as $key => $change ) {
			$row['changes'][ $key ] = $change;
		}

		if ( ! $dry_run && $row['changes'] ) {
			if ( $wc['product'] ) {
				try {
					// Solo metadatos: WooCommerce no llama a wp_update_post y WPML no relanza la traducción.
					$wc['product']->save();
				} catch ( Exception $e ) {
					$notes[] = $id . ': ' . $e->getMessage();
				}
				$post = get_post( $id );
			}
			if ( $status ) {
				if ( 'trash' === $post->post_status ) {
					wp_untrash_post( $id );
					$post = get_post( $id );
				}
				if ( $status !== $post->post_status ) {
					$fields_status = array( 'post_status' => $status );
					if ( '' === $post->post_name && 'publish' === $status ) {
						// Un borrador nuevo (o copiado) aún no tiene slug: wp_update_post lo pondría al publicar.
						$fields_status['post_name'] = wp_unique_post_slug( sanitize_title( $update['post_title'] ?? $post->post_title ), $id, $status, 'product', $post->post_parent );
					}
					$wpdb->update( $wpdb->posts, $fields_status, array( 'ID' => $id ) );
					clean_post_cache( $id );
					wp_transition_post_status( $status, $post->post_status, get_post( $id ) );
				}
			}
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

	if ( ! $dry_run && function_exists( 'rocket_clean_domain' ) && ( $created || array_filter( $done, function ( $row ) {
		return isset( $row['changes']['status'] );
	} ) ) ) {
		// Un producto que aparece o desaparece cambia también la tienda y las categorías.
		rocket_clean_domain();
	}

	return new WP_REST_Response( array(
		'ok'       => true,
		'dry_run'  => $dry_run,
		'created'  => $created,
		'count'    => count( $done ),
		'products' => $done,
		'message'  => implode( ' | ', $notes ),
	), 200 );
}

/** Todos los productos, también borradores, privados y en la papelera. */
function eg_pub_product_list() {
	$out = array();
	$ids = get_posts( array(
		'post_type'        => 'product',
		'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' ),
		'posts_per_page'   => 500,
		'fields'           => 'ids',
		'orderby'          => 'date',
		'order'            => 'DESC',
		'suppress_filters' => true,
	) );
	foreach ( $ids as $id ) {
		$product = wc_get_product( $id );
		if ( ! $product ) {
			continue;
		}
		$details = apply_filters( 'wpml_post_language_details', null, $id );
		$out[]   = array(
			'id'            => $id,
			'title'         => $product->get_name(),
			'sku'           => $product->get_sku(),
			'status'        => get_post_status( $id ),
			'type'          => $product->get_type(),
			'regular_price' => $product->get_regular_price(),
			'sale_price'    => $product->get_sale_price(),
			'sale_from'     => $product->get_date_on_sale_from() ? $product->get_date_on_sale_from()->date( 'Y-m-d H:i' ) : '',
			'sale_to'       => $product->get_date_on_sale_to() ? $product->get_date_on_sale_to()->date( 'Y-m-d H:i' ) : '',
			'on_sale'       => $product->is_on_sale(),
			'price'         => $product->get_price(),
			'weight'        => $product->get_weight(),
			'stock_status'  => $product->get_stock_status(),
			'categories'    => wp_get_post_terms( $id, 'product_cat', array( 'fields' => 'slugs' ) ),
			'image'         => (int) $product->get_image_id(),
			'gallery'       => array_map( 'intval', $product->get_gallery_image_ids() ),
			'date'          => get_post_field( 'post_date', $id ),
			'lang'          => is_array( $details ) ? (string) ( $details['language_code'] ?? '' ) : '',
			'url'           => get_permalink( $id ),
		);
	}
	return $out;
}

/**
 * Crea un producto simple en borrador, vacío o copiado de create.from (ID o SKU) con el duplicado de WooCommerce,
 * que copia descripción, pestañas, categorías, imágenes y envío. El título va en create.title o en set.title.
 */
function eg_pub_product_create( $create, $dry_run ) {
	$source = null;
	if ( ! empty( $create['from'] ) ) {
		$from   = $create['from'];
		$src_id = is_numeric( $from ) ? (int) $from : wc_get_product_id_by_sku( sanitize_text_field( (string) $from ) );
		$source = $src_id ? wc_get_product( $src_id ) : null;
		if ( ! $source ) {
			return new WP_Error( 'eg_create', 'no encuentro el producto de origen ' . $from );
		}
	}
	$title = sanitize_text_field( (string) ( $create['title'] ?? '' ) );
	if ( ! $source && '' === $title ) {
		return new WP_Error( 'eg_create', 'falta create.title (o create.from para copiar otro producto)' );
	}
	if ( $dry_run ) {
		return array( 'id' => 0, 'from' => $source ? $source->get_id() : 0, 'title' => $title ?: $source->get_name() . ' (copia)' );
	}
	if ( $source ) {
		if ( ! class_exists( 'WC_Admin_Duplicate_Product' ) ) {
			require_once WC()->plugin_path() . '/includes/admin/class-wc-admin-duplicate-product.php';
		}
		$product = ( new WC_Admin_Duplicate_Product() )->product_duplicate( $source );
	} else {
		$product = new WC_Product_Simple();
		$product->set_status( 'draft' );
	}
	if ( '' !== $title ) {
		$product->set_name( $title );
		$product->set_slug( '' );
	}
	try {
		$product->set_sku( '' );
	} catch ( Exception $e ) {
		unset( $e );
	}
	$product->save();
	return array( 'id' => $product->get_id(), 'from' => $source ? $source->get_id() : 0, 'title' => $product->get_name() );
}

/** Aplica al producto (sin guardarlo) los campos de WooCommerce de $c y devuelve el producto y los cambios. */
function eg_pub_product_wc_changes( $id, $c, $dry_run, &$notes ) {
	$keys = array( 'regular_price', 'sale_price', 'sale_from', 'sale_to', 'sku', 'weight', 'stock_status', 'image', 'gallery' );
	if ( ! array_intersect( $keys, array_keys( $c ) ) ) {
		return array( 'product' => null, 'changes' => array() );
	}
	$product = wc_get_product( $id );
	$changes = array();
	foreach ( array( 'regular_price', 'sale_price', 'sku', 'weight', 'stock_status' ) as $key ) {
		if ( ! array_key_exists( $key, $c ) ) {
			continue;
		}
		$value = (string) $c[ $key ];
		if ( in_array( $key, array( 'regular_price', 'sale_price', 'weight' ), true ) ) {
			$value = '' === $value ? '' : wc_format_decimal( str_replace( ',', '.', $value ) );
		} else {
			$value = sanitize_text_field( $value );
		}
		$old = (string) $product->{'get_' . $key}( 'edit' );
		if ( $old === $value ) {
			continue;
		}
		if ( 'sku' === $key && '' !== $value && ! wc_product_has_unique_sku( $id, $value ) ) {
			$notes[] = $id . ': el SKU ' . $value . ' ya lo usa otro producto';
			continue;
		}
		$changes[ $key ] = array( 'before' => $old, 'after' => $value );
		if ( ! $dry_run ) {
			$product->{'set_' . $key}( $value );
		}
	}
	foreach ( array( 'sale_from' => 'date_on_sale_from', 'sale_to' => 'date_on_sale_to' ) as $key => $prop ) {
		if ( ! array_key_exists( $key, $c ) ) {
			continue;
		}
		$date  = $product->{'get_' . $prop}( 'edit' );
		$old   = $date ? $date->date( 'Y-m-d H:i' ) : '';
		$value = trim( sanitize_text_field( (string) $c[ $key ] ) );
		if ( '' !== $value && ! preg_match( '~^\d{4}-\d{2}-\d{2}( \d{2}:\d{2})?$~', $value ) ) {
			$notes[] = $id . ': fecha no válida en ' . $key . ': ' . $value;
			continue;
		}
		if ( '' !== $value && 10 === strlen( $value ) ) {
			// Como el editor de WooCommerce: la rebaja empieza a las 00:00 y acaba a las 23:59 de ese día.
			$value .= 'sale_from' === $key ? ' 00:00' : ' 23:59';
		}
		if ( $old === $value ) {
			continue;
		}
		$changes[ $key ] = array( 'before' => $old, 'after' => $value );
		if ( ! $dry_run ) {
			$product->{'set_' . $prop}( '' === $value ? null : $value );
		}
	}
	if ( array_key_exists( 'image', $c ) ) {
		$old = (int) $product->get_image_id( 'edit' );
		$new = $dry_run || empty( $c['image'] ) ? $c['image'] : eg_pub_item_image( $id, $c['image'], sanitize_title( $product->get_name() ) );
		if ( is_wp_error( $new ) ) {
			$notes[] = $id . ': ' . $new->get_error_message();
		} elseif ( $dry_run || (int) $new !== $old ) {
			$changes['image'] = array( 'before' => $old, 'after' => $new );
			if ( ! $dry_run ) {
				$product->set_image_id( (int) $new );
			}
		}
	}
	if ( array_key_exists( 'gallery', $c ) ) {
		$old = array_map( 'intval', $product->get_gallery_image_ids( 'edit' ) );
		$new = array();
		foreach ( (array) $c['gallery'] as $i => $img ) {
			$att = $dry_run ? $img : eg_pub_item_image( $id, $img, sanitize_title( $product->get_name() ) . '-' . ( $i + 1 ) );
			if ( is_wp_error( $att ) ) {
				$notes[] = $id . ': ' . $att->get_error_message();
			} else {
				$new[] = $dry_run ? $att : (int) $att;
			}
		}
		if ( $dry_run || $new !== $old ) {
			$changes['gallery'] = array( 'before' => $old, 'after' => $new );
			if ( ! $dry_run ) {
				$product->set_gallery_image_ids( $new );
			}
		}
	}
	return array( 'product' => $changes ? $product : null, 'changes' => $changes );
}
