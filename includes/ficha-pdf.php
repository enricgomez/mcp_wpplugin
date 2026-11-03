<?php
/**
 * Ficha técnica en PDF de los productos de WooCommerce, en un campo propio en lugar de un enlace en la descripción.
 *
 * - Campo `ficha_tecnica_pdf` (URL del PDF) en la ficha del producto, con un recuadro en wp-admin para elegirlo
 *   de la biblioteca o pegar la URL. Es un metadato normal: Elementor Pro lo lee con la etiqueta dinámica
 *   «Campo personalizado» (clave `ficha_tecnica_pdf`) y JetEngine puede registrarlo con el mismo nombre.
 * - Visibilidad condicional en Elementor: un contenedor, sección o widget con la clase CSS `eg-si-meta-<clave>`
 *   (p. ej. `eg-si-meta-ficha_tecnica_pdf`) solo se pinta si la entrada actual tiene ese metadato con valor.
 * - POST /wp-json/eg/v1/ficha-pdf: pasa los PDF de la descripción al campo.
 *     Sin parámetros: estado (productos con campo, productos con un enlace .pdf aún en la descripción).
 *     migrate: true   en cada producto busca el primer enlace .pdf de la descripción, lo guarda en el campo y quita
 *                     de la descripción el bloque (p, h1-h6, li) que lo contiene. `copy: true` sube antes el PDF a
 *                     la biblioteca de la web (p. ej. si está en el dominio antiguo) y guarda la URL nueva.
 *                     `ids` limita a esos productos; `force` sobrescribe un campo que ya tiene valor.
 *     Solo previsualiza; guarda con `apply: true`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_FICHA_PDF_META = 'ficha_tecnica_pdf';

add_action( 'rest_api_init', function () {
	register_rest_route( 'eg/v1', '/ficha-pdf', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_ficha_pdf_handle',
		'permission_callback' => 'eg_pub_permission',
	) );
} );

/* ---------- Recuadro en la ficha del producto ---------- */

add_action( 'add_meta_boxes_product', function () {
	add_meta_box( 'eg-ficha-pdf', 'Ficha técnica (PDF)', 'eg_pub_ficha_pdf_box', 'product', 'side' );
} );

function eg_pub_ficha_pdf_box( $post ) {
	wp_enqueue_media();
	wp_nonce_field( 'eg_ficha_pdf', 'eg_ficha_pdf_nonce' );
	$url = (string) get_post_meta( $post->ID, EG_PUB_FICHA_PDF_META, true );
	echo '<p><input type="url" id="eg-ficha-pdf" name="eg_ficha_pdf" value="' . esc_attr( $url ) . '" class="widefat" placeholder="https://…/ficha.pdf"></p>';
	echo '<p><button type="button" class="button" id="eg-ficha-pdf-pick">Elegir de la biblioteca</button></p>';
	echo '<p class="description">Si está vacío, la ficha del producto no muestra el bloque de descarga.</p>';
	?>
	<script>
	jQuery( function ( $ ) {
		var frame;
		$( '#eg-ficha-pdf-pick' ).on( 'click', function ( e ) {
			e.preventDefault();
			if ( ! frame ) {
				frame = wp.media( { title: 'Ficha técnica (PDF)', library: { type: 'application/pdf' }, multiple: false } );
				frame.on( 'select', function () {
					$( '#eg-ficha-pdf' ).val( frame.state().get( 'selection' ).first().get( 'url' ) );
				} );
			}
			frame.open();
		} );
	} );
	</script>
	<?php
}

add_action( 'save_post_product', function ( $post_id ) {
	if ( ! isset( $_POST['eg_ficha_pdf_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['eg_ficha_pdf_nonce'] ), 'eg_ficha_pdf' ) ) {
		return;
	}
	if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	$url = esc_url_raw( wp_unslash( (string) ( $_POST['eg_ficha_pdf'] ?? '' ) ) );
	if ( '' === $url ) {
		delete_post_meta( $post_id, EG_PUB_FICHA_PDF_META );
	} else {
		update_post_meta( $post_id, EG_PUB_FICHA_PDF_META, $url );
	}
} );

/* ---------- Visibilidad condicional en Elementor ---------- */

/** true si el elemento no lleva la clase eg-si-meta-<clave> o si la entrada actual tiene ese metadato con valor. */
function eg_pub_meta_condition_ok( $element ) {
	$settings = $element->get_settings();
	$classes  = trim( (string) ( $settings['_css_classes'] ?? '' ) . ' ' . (string) ( $settings['css_classes'] ?? '' ) );
	if ( false === strpos( $classes, 'eg-si-meta-' ) || ! preg_match_all( '/(?:^|\s)eg-si-meta-([A-Za-z0-9_-]+)/', $classes, $m ) ) {
		return true;
	}
	$post_id = get_the_ID();
	if ( ! $post_id ) {
		return true; // Fuera de una entrada (p. ej. vista previa de la plantilla): se pinta.
	}
	foreach ( $m[1] as $key ) {
		$value = get_post_meta( $post_id, $key, true );
		if ( '' === $value || null === $value || false === $value || array() === $value ) {
			return false;
		}
	}
	return true;
}

foreach ( array( 'widget', 'container', 'section', 'column' ) as $eg_pub_type ) {
	add_filter( "elementor/frontend/{$eg_pub_type}/should_render", function ( $should, $element ) {
		if ( ! $should || ( class_exists( '\Elementor\Plugin' ) && \Elementor\Plugin::$instance->editor->is_edit_mode() ) ) {
			return $should;
		}
		return eg_pub_meta_condition_ok( $element );
	}, 10, 2 );
}
unset( $eg_pub_type );

/* ---------- Migración desde la descripción ---------- */

/** Primer enlace .pdf del contenido y el bloque (p, h1-h6, li) que lo contiene. */
function eg_pub_ficha_pdf_find( $content ) {
	if ( ! preg_match( '/<a\s[^>]*href=(["\'])([^"\']+\.pdf)\1[^>]*>/i', $content, $a, PREG_OFFSET_CAPTURE ) ) {
		return null;
	}
	$url   = html_entity_decode( $a[2][0] );
	$pos   = $a[0][1];
	$block = '';
	if ( preg_match_all( '/<(p|h[1-6]|li)\b[^>]*>.*?<\/\1>/is', $content, $blocks, PREG_OFFSET_CAPTURE ) ) {
		foreach ( $blocks[0] as $b ) {
			if ( $b[1] <= $pos && $pos < $b[1] + strlen( $b[0] ) ) {
				$block = $b[0];
				break;
			}
		}
	}
	if ( '' === $block ) {
		// Línea suelta sin etiqueta de bloque.
		$start = strrpos( substr( $content, 0, $pos ), "\n" );
		$start = false === $start ? 0 : $start + 1;
		$end   = strpos( $content, "\n", $pos );
		$block = substr( $content, $start, false === $end ? null : $end - $start );
	}
	return array( 'url' => $url, 'block' => $block );
}

/** Sube un PDF a la biblioteca (una sola vez por URL) y devuelve la URL nueva o WP_Error. */
function eg_pub_ficha_pdf_sideload( $url, $product_id ) {
	$found = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_eg_pub_source_url', 'meta_value' => $url, 'fields' => 'ids', 'numberposts' => 1 ) );
	if ( $found ) {
		return wp_get_attachment_url( $found[0] );
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$tmp = download_url( $url, 60 );
	if ( is_wp_error( $tmp ) ) {
		return $tmp;
	}
	$name = sanitize_file_name( remove_accents( rawurldecode( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) ) ) );
	if ( ! preg_match( '/\.pdf$/i', $name ) ) {
		$name .= '.pdf';
	}
	$id = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), $product_id );
	if ( is_wp_error( $id ) ) {
		@unlink( $tmp );
		return $id;
	}
	update_post_meta( $id, '_eg_pub_source_url', $url );
	return wp_get_attachment_url( $id );
}

function eg_pub_ficha_pdf_handle( WP_REST_Request $r ) {
	if ( ! post_type_exists( 'product' ) ) {
		return eg_pub_error( 'eg_woocommerce', 'WooCommerce no está activo.' );
	}
	$p     = (array) $r->get_json_params();
	$ids   = array_map( 'absint', (array) ( $p['ids'] ?? array() ) );
	$query = array( 'post_type' => 'product', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' );
	if ( $ids ) {
		$query['post__in'] = $ids;
	}
	$products = get_posts( $query );

	if ( empty( $p['migrate'] ) ) {
		$with_field = array();
		$in_text    = array();
		foreach ( $products as $id ) {
			$value = (string) get_post_meta( $id, EG_PUB_FICHA_PDF_META, true );
			if ( '' !== $value ) {
				$with_field[] = array( 'id' => $id, 'pdf' => $value );
			}
			if ( eg_pub_ficha_pdf_find( (string) get_post_field( 'post_content', $id ) ) ) {
				$in_text[] = $id;
			}
		}
		return new WP_REST_Response( array( 'ok' => true, 'products' => count( $products ), 'with_field' => $with_field, 'pdf_in_text' => $in_text ), 200 );
	}

	$apply = ! empty( $p['apply'] );
	$copy  = ! empty( $p['copy'] );
	$force = ! empty( $p['force'] );
	$out   = array();
	foreach ( $products as $id ) {
		$content = (string) get_post_field( 'post_content', $id );
		$found   = eg_pub_ficha_pdf_find( $content );
		if ( ! $found ) {
			continue;
		}
		$row     = array( 'id' => $id, 'title' => get_the_title( $id ), 'pdf' => $found['url'], 'removed' => wp_strip_all_tags( $found['block'] ) );
		$current = (string) get_post_meta( $id, EG_PUB_FICHA_PDF_META, true );
		if ( '' !== $current && ! $force ) {
			$row['field'] = $current;
			$row['note']  = 'El campo ya tenía valor: solo se quita el enlace de la descripción.';
		}
		if ( $apply ) {
			if ( '' === $current || $force ) {
				$url = $found['url'];
				if ( $copy ) {
					$new = eg_pub_ficha_pdf_sideload( $url, $id );
					if ( is_wp_error( $new ) ) {
						$row['error'] = 'No se ha podido copiar el PDF: ' . $new->get_error_message();
						$out[]        = $row;
						continue;
					}
					$url = $new;
				}
				update_post_meta( $id, EG_PUB_FICHA_PDF_META, esc_url_raw( $url ) );
				$row['field'] = $url;
			}
			$new_content = preg_replace( '/\n{3,}/', "\n\n", str_replace( $found['block'], '', $content ) );
			global $wpdb;
			$wpdb->update( $wpdb->posts, array( 'post_content' => trim( $new_content ) ), array( 'ID' => $id ) );
			clean_post_cache( $id );
			if ( function_exists( 'wc_delete_product_transients' ) ) {
				wc_delete_product_transients( $id );
			}
			$row['saved'] = true;
		}
		$out[] = $row;
	}
	if ( $apply && function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
	}
	return new WP_REST_Response( array( 'ok' => true, 'applied' => $apply, 'count' => count( $out ), 'items' => $out ), 200 );
}
