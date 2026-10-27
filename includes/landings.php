<?php
/**
 * Endpoints de las tipologías Elementor y Gestión de la web (se activan en Ajustes → EG Publicación; ver includes/modulos.php):
 *   POST /wp-json/eg/v1/landing   clona una página maestra de Elementor y sustituye sus marcadores {{clave}}
 *                                 (o crea la página con su propio JSON de Elementor en "elements").
 *   POST /wp-json/eg/v1/redirect  crea una redirección en Rank Math.
 *   POST /wp-json/eg/v1/menu      añade páginas a un menú (y quita las que se indiquen).
 *   POST /wp-json/eg/v1/text      busca y sustituye textos en toda la web (includes/textos.php).
 *   POST /wp-json/eg/v1/template  y /append: plantillas de Elementor reutilizables al pie de páginas (includes/plantillas.php).
 * Usan la misma clave X-EG-Key que /publish.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_OPTION_LANDINGS = 'eg_pub_landings_enabled';
const EG_PUB_META_LANDING    = '_eg_landing_id';

/** Hay algún módulo opcional activo (antes de la 2.6.0 había un único interruptor para todos). */
function eg_pub_landings_enabled() {
	return (bool) eg_pub_active_modules();
}

add_action( 'rest_api_init', function () {
	$routes = array(
		'landing'  => array( 'landings', 'eg_pub_landing_handle', 'publish_pages' ),
		'menu'     => array( 'menus', 'eg_pub_menu_handle', 'edit_theme_options' ),
		'redirect' => array( 'redirecciones', 'eg_pub_redirect_handle', 'manage_options' ),
	);
	foreach ( $routes as $route => list( $module, $callback, $cap ) ) {
		if ( eg_pub_module_enabled( $module ) ) {
			register_rest_route( 'eg/v1', '/' . $route, array(
				'methods'             => 'POST',
				'callback'            => $callback,
				'permission_callback' => eg_pub_module_permission( $cap ),
			) );
		}
	}
} );

function eg_pub_find_landing( $external_id ) {
	$external_id = sanitize_text_field( $external_id );
	if ( '' === $external_id ) {
		return 0;
	}
	$ids = get_posts( array(
		'post_type'        => 'page',
		'post_status'      => 'any',
		'meta_key'         => EG_PUB_META_LANDING,
		'meta_value'       => $external_id,
		'fields'           => 'ids',
		'numberposts'      => 1,
		'suppress_filters' => true, // WPML: buscar en todos los idiomas.
	) );
	return $ids ? (int) $ids[0] : 0;
}

/**
 * Recorre el JSON de Elementor: cambia el texto de los widgets indicados, sustituye marcadores,
 * cambia las imágenes (por ID de adjunto de la maestra) y da un ID nuevo a cada elemento.
 */
function eg_pub_landing_walk( $node, $map, $imgs = array(), $widgets = array() ) {
	if ( is_string( $node ) ) {
		return strtr( $node, $map );
	}
	if ( ! is_array( $node ) ) {
		return $node;
	}
	// Control de imagen de Elementor: {"url": "...", "id": 123}.
	if ( isset( $node['id'], $node['url'] ) && ! isset( $node['elType'] ) && isset( $imgs[ (int) $node['id'] ] ) ) {
		$new          = $imgs[ (int) $node['id'] ];
		$node['id']   = $new;
		$node['url']  = (string) wp_get_attachment_url( $new );
		$node['alt']  = (string) get_post_meta( $new, '_wp_attachment_image_alt', true );
		return $node;
	}
	// Texto nuevo para un widget concreto de la maestra (por su ID de elemento).
	if ( isset( $node['id'], $node['elType'], $widgets[ $node['id'] ] ) ) {
		$field = 'text-editor' === ( $node['widgetType'] ?? '' ) ? 'editor' : 'title';
		$node['settings']           = isset( $node['settings'] ) && is_array( $node['settings'] ) ? $node['settings'] : array();
		$node['settings'][ $field ] = $widgets[ $node['id'] ];
	}
	foreach ( $node as $k => $v ) {
		$node[ $k ] = eg_pub_landing_walk( $v, $map, $imgs, $widgets );
	}
	if ( isset( $node['id'], $node['elType'] ) ) {
		$node['id'] = eg_pub_landing_el_id();
	}
	return $node;
}

function eg_pub_landing_el_id() {
	return substr( md5( uniqid( '', true ) . wp_rand() ), 0, 7 );
}

/**
 * Reparte cada widget de texto que lleve <h2> o fotos en widgets de Elementor: un título por cada <h2>,
 * un texto por cada bloque y, donde hay una foto, una sección interior de dos columnas (foto y texto).
 * Si el texto está en un contenedor (flexbox), las dos columnas son contenedores hijos.
 * Dentro de una sección interior no se puede anidar otra: allí la foto va encima del texto.
 */
function eg_pub_landing_split( $elements, $inner = false, $in_container = false ) {
	$out = array();
	foreach ( $elements as $el ) {
		if ( ! is_array( $el ) ) {
			$out[] = $el;
			continue;
		}
		$html = $el['settings']['editor'] ?? '';
		if ( 'text-editor' === ( $el['widgetType'] ?? '' ) && is_string( $html ) && preg_match( '/<h2[\s>]|<!--eg-img:/i', $html ) ) {
			$out = array_merge( $out, eg_pub_landing_split_html( $html, $el['settings'], $inner, $in_container ) );
			continue;
		}
		if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
			$el['elements'] = eg_pub_landing_split( $el['elements'], $inner || ! empty( $el['isInner'] ), 'container' === ( $el['elType'] ?? '' ) );
		}
		$out[] = $el;
	}
	return $out;
}

function eg_pub_landing_split_html( $html, $text_settings, $inner, $in_container = false ) {
	unset( $text_settings['_element_id'] ); // El ID CSS no se puede repetir en la página.
	$text_widget = function ( $content ) use ( $text_settings ) {
		$text_settings['editor'] = $content;
		return array( 'id' => eg_pub_landing_el_id(), 'elType' => 'widget', 'widgetType' => 'text-editor', 'settings' => $text_settings, 'elements' => array() );
	};
	$image_widget = function ( $att ) {
		return array(
			'id'         => eg_pub_landing_el_id(),
			'elType'     => 'widget',
			'widgetType' => 'image',
			'settings'   => array(
				'image'        => array( 'id' => (int) $att, 'url' => (string) wp_get_attachment_url( $att ), 'alt' => (string) get_post_meta( $att, '_wp_attachment_image_alt', true ), 'source' => 'library' ),
				'image_size'   => 'large',
				'_css_classes' => 'eg-landing-photo',
			),
			'elements'   => array(),
		);
	};
	$column = function ( $children ) {
		return array( 'id' => eg_pub_landing_el_id(), 'elType' => 'column', 'isInner' => true, 'settings' => array( '_column_size' => 50, '_inline_size' => null, 'content_position' => 'center' ), 'elements' => $children );
	};

	$out   = array();
	$parts = preg_split( '#(<h2[^>]*>.*?</h2>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
	foreach ( $parts as $part ) {
		if ( preg_match( '#^<h2[^>]*>(.*)</h2>$#is', trim( $part ), $h ) ) {
			// Sin los colores y la tipografía globales por defecto del widget: se ve igual que el <h2> que había en el texto.
			$out[] = array(
				'id'         => eg_pub_landing_el_id(),
				'elType'     => 'widget',
				'widgetType' => 'heading',
				'settings'   => array( 'title' => trim( $h[1] ), 'header_size' => 'h2', '__globals__' => array( 'title_color' => '', 'typography_typography' => '' ) ),
				'elements'   => array(),
			);
			continue;
		}
		$img  = preg_match( '#<!--eg-img:(\d+):(left|right)-->#', $part, $m ) ? $m : null;
		$text = trim( preg_replace( '#<!--eg-img:\d+:(left|right)-->#', '', $part ) );
		$text = trim( preg_replace( '#^(<p>\s*</p>\s*)+|(\s*<p>\s*</p>)+$#i', '', $text ) );
		if ( ! $img ) {
			if ( '' !== $text ) {
				$out[] = $text_widget( $text );
			}
			continue;
		}
		if ( '' === $text || $inner ) {
			$out[] = $image_widget( $img[1] );
			if ( '' !== $text ) {
				$out[] = $text_widget( $text );
			}
			continue;
		}
		$left = 'left' === $img[2];
		if ( $in_container ) {
			// Contenedores (flexbox): fila de dos contenedores al 50 % que pasa a columna en el móvil, con la foto arriba.
			$half  = function ( $child ) {
				return array(
					'id'       => eg_pub_landing_el_id(),
					'elType'   => 'container',
					'isInner'  => true,
					'settings' => array( 'content_width' => 'full', 'width' => array( 'unit' => '%', 'size' => 50 ), 'width_mobile' => array( 'unit' => '%', 'size' => 100 ), 'padding' => array( 'unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0', 'isLinked' => true ) ),
					'elements' => array( $child ),
				);
			};
			$photo = $half( $image_widget( $img[1] ) );
			$copy  = $half( $text_widget( $text ) );
			$out[] = array(
				'id'       => eg_pub_landing_el_id(),
				'elType'   => 'container',
				'isInner'  => true,
				'settings' => array(
					'content_width'         => 'full',
					'flex_direction'        => 'row',
					'flex_direction_mobile' => $left ? 'column' : 'column-reverse',
					'flex_align_items'      => 'center',
					'flex_gap'              => array( 'unit' => 'px', 'size' => 40, 'column' => '40', 'row' => '20', 'isLinked' => false ),
					'padding'               => array( 'unit' => 'px', 'top' => '0', 'right' => '0', 'bottom' => '0', 'left' => '0', 'isLinked' => true ),
				),
				'elements' => $left ? array( $photo, $copy ) : array( $copy, $photo ),
			);
			continue;
		}
		$photo = $column( array( $image_widget( $img[1] ) ) );
		$copy  = $column( array( $text_widget( $text ) ) );
		$out[] = array(
			'id'       => eg_pub_landing_el_id(),
			'elType'   => 'section',
			'isInner'  => true,
			// En el móvil las columnas se apilan con la foto siempre arriba.
			'settings' => $left ? array( 'gap' => 'extended' ) : array( 'gap' => 'extended', 'reverse_order_mobile' => 'reverse-mobile' ),
			'elements' => $left ? array( $photo, $copy ) : array( $copy, $photo ),
		);
	}
	return $out;
}

/**
 * Lista los marcadores y las imágenes de la maestra (para preparar la llamada con dry_run).
 */
function eg_pub_landing_scan( $node, &$found, $widget = '' ) {
	if ( is_string( $node ) ) {
		if ( preg_match_all( '/\{\{([a-z0-9_\-]+)\}\}/', $node, $m ) ) {
			$found['markers'] = array_merge( $found['markers'], $m[1] );
		}
		return;
	}
	if ( ! is_array( $node ) ) {
		return;
	}
	if ( isset( $node['id'], $node['url'] ) && ! isset( $node['elType'] ) && (int) $node['id'] ) {
		$found['images'][ (int) $node['id'] ] = array( 'id' => (int) $node['id'], 'url' => (string) $node['url'], 'widget' => $widget );
		return;
	}
	if ( isset( $node['elType'] ) ) {
		$widget = isset( $node['widgetType'] ) ? $node['widgetType'] : $node['elType'];
		$text   = $node['settings']['editor'] ?? ( $node['settings']['title'] ?? '' );
		if ( 'widget' === $node['elType'] && is_string( $text ) && '' !== trim( $text ) ) {
			$found['widgets'][] = array(
				'id'     => (string) $node['id'],
				'widget' => $widget,
				'text'   => wp_html_excerpt( wp_strip_all_tags( $text ), 160, '…' ),
			);
		}
	}
	foreach ( $node as $v ) {
		eg_pub_landing_scan( $v, $found, $widget );
	}
}

/**
 * Adjunto a partir de un ID de la biblioteca, una URL (se descarga) o {"id"|"url": …, "alt": "…"}.
 */
function eg_pub_landing_attachment( $img, $filename ) {
	$alt = '';
	if ( is_array( $img ) ) {
		$alt = isset( $img['alt'] ) ? (string) $img['alt'] : '';
		$img = isset( $img['id'] ) ? $img['id'] : ( $img['url'] ?? '' );
	}
	if ( is_numeric( $img ) ) {
		if ( 'attachment' !== get_post_type( (int) $img ) ) {
			return new WP_Error( 'eg_img', 'no existe el adjunto ' . $img );
		}
		if ( '' !== $alt ) {
			update_post_meta( (int) $img, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
		}
		return (int) $img;
	}
	if ( '' === trim( (string) $img ) ) {
		return new WP_Error( 'eg_img', 'falta la imagen' );
	}
	return eg_pub_sideload_image( 0, (string) $img, '', array( 'filename' => $filename, 'alt' => $alt ) );
}

function eg_pub_landing_handle( WP_REST_Request $r ) {
	$p = $r->get_params();

	$external_id = isset( $p['landing_id'] ) ? sanitize_text_field( $p['landing_id'] ) : '';
	$template_id = isset( $p['template_id'] ) ? (int) $p['template_id'] : 0;
	$lang        = isset( $p['lang'] ) ? sanitize_key( $p['lang'] ) : '';
	$title       = isset( $p['title'] ) ? wp_strip_all_tags( $p['title'] ) : '';

	if ( ! $template_id || ( empty( $p['dry_run'] ) && ( '' === $external_id || '' === $title ) ) ) {
		return eg_pub_error( 'eg_missing', 'Faltan datos obligatorios: landing_id, template_id o title.' );
	}
	$template = get_post( $template_id );
	$raw      = $template ? get_post_meta( $template_id, '_elementor_data', true ) : '';
	$data     = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
	if ( ! $template || ! is_array( $data ) ) {
		return eg_pub_error( 'eg_template', 'La página maestra ' . $template_id . ' no existe o no está hecha con Elementor.', 404 );
	}

	if ( ! empty( $p['dry_run'] ) ) {
		$found = array( 'markers' => array(), 'images' => array(), 'widgets' => array() );
		eg_pub_landing_scan( $data, $found );
		$out = array(
			'ok'      => true,
			'markers' => array_values( array_unique( $found['markers'] ) ),
			'images'  => array_values( $found['images'] ),
			'widgets' => $found['widgets'],
		);
		// Con "data": true devuelve también el JSON de Elementor completo de la maestra (solo lectura).
		if ( ! empty( $p['data'] ) ) {
			$out['data'] = $data;
		}
		return new WP_REST_Response( $out, 200 );
	}

	// "elements": JSON de Elementor propio para la página nueva. De la maestra solo se copian
	// la plantilla de página y sus ajustes (así una página existente sirve de base para rehacerla).
	if ( isset( $p['elements'] ) ) {
		$elements = is_string( $p['elements'] ) ? json_decode( $p['elements'], true ) : $p['elements'];
		if ( ! is_array( $elements ) || ! $elements ) {
			return eg_pub_error( 'eg_elements', 'elements debe ser el JSON de Elementor de la página (una lista de secciones o contenedores).' );
		}
		$data = array_values( $elements );
	}

	// Imágenes: {"<ID adjunto de la maestra>": <ID de adjunto> | "https://..." | {"url": "...", "alt": "..."}}.
	$imgs  = array();
	$notes = array();
	foreach ( (array) ( $p['images'] ?? array() ) as $old => $new ) {
		$att = eg_pub_landing_attachment( $new, sanitize_title( $p['slug'] ?? $title ) . '-' . ( count( $imgs ) + 1 ) );
		if ( is_wp_error( $att ) ) {
			$notes[] = 'imagen ' . $old . ': ' . $att->get_error_message();
		} else {
			$imgs[ (int) $old ] = (int) $att;
		}
	}

	// Marcadores: {"municipio":"Blanes"} sustituye {{municipio}}.
	$map = array();
	foreach ( (array) ( $p['replacements'] ?? array() ) as $key => $value ) {
		$map[ '{{' . sanitize_key( $key ) . '}}' ] = wp_kses_post( (string) $value );
	}

	// Imágenes dentro del texto: {"img_cocinas": <ID> | "https://..." | {"id"|"url": …, "alt": "…", "align": "left"}}
	// sustituye {{img_cocinas}} por una foto integrada (al lado del texto en escritorio, a todo el ancho en el móvil).
	// Con "layout": "widgets" el texto se reparte en widgets de Elementor (título, texto e imagen en dos columnas).
	$as_widgets = isset( $p['layout'] ) && 'widgets' === $p['layout'];
	$inline_ids = array();
	foreach ( (array) ( $p['inline_images'] ?? array() ) as $key => $img ) {
		$left  = is_array( $img ) && 'left' === ( $img['align'] ?? '' );
		$align = $left ? ' eg-landing-img--left' : '';
		$att   = eg_pub_landing_attachment( $img, sanitize_title( $p['slug'] ?? $title ) . '-' . sanitize_key( $key ) );
		if ( is_wp_error( $att ) ) {
			$notes[] = 'imagen ' . $key . ': ' . $att->get_error_message();
			$map[ '{{' . sanitize_key( $key ) . '}}' ] = '';
			continue;
		}
		$inline_ids[ sanitize_key( $key ) ] = $att;
		if ( $as_widgets ) {
			$map[ '{{' . sanitize_key( $key ) . '}}' ] = '<!--eg-img:' . $att . ':' . ( $left ? 'left' : 'right' ) . '-->';
			continue;
		}
		$map[ '{{' . sanitize_key( $key ) . '}}' ] = '<figure class="eg-landing-img' . $align . '">'
			. wp_get_attachment_image( $att, 'large', false, array( 'loading' => 'lazy', 'sizes' => '(max-width: 767px) 100vw, 440px' ) )
			. '</figure>';
	}

	// Texto completo de widgets concretos: {"<ID elemento>": "<h2>…</h2><p>…</p>"} (los IDs salen con dry_run).
	$widgets = array();
	foreach ( (array) ( $p['widgets'] ?? array() ) as $el_id => $html ) {
		$widgets[ sanitize_key( $el_id ) ] = wp_kses_post( (string) $html );
	}
	$data    = eg_pub_landing_walk( $data, $map, $imgs, $widgets );
	if ( $as_widgets ) {
		$data = eg_pub_landing_split( $data );
	}
	$left    = array();
	$encoded = wp_json_encode( $data );
	if ( preg_match_all( '/\{\{([a-z0-9_\-]+)\}\}/', $encoded, $m ) ) {
		$left = array_values( array_unique( $m[1] ) );
	}

	$has_wpml  = defined( 'ICL_SITEPRESS_VERSION' );
	$source_id = 0;
	if ( ! empty( $p['translation_of'] ) ) {
		$source_id = eg_pub_find_landing( $p['translation_of'] );
		if ( ! $source_id ) {
			return eg_pub_error( 'eg_source', 'La landing original todavía no está en WordPress. Crea primero la del idioma principal.', 409 );
		}
	}
	if ( $has_wpml && $lang ) {
		do_action( 'wpml_switch_language', $lang );
	}

	$status  = isset( $p['status'] ) && 'publish' === $p['status'] ? 'publish' : 'draft';
	$postarr = array(
		'post_type'    => 'page',
		'post_title'   => $title,
		'post_content' => '', // El contenido real está en _elementor_data.
		'post_status'  => $status,
		'post_parent'  => isset( $p['parent_id'] ) ? (int) $p['parent_id'] : 0,
	);
	if ( ! empty( $p['slug'] ) ) {
		$postarr['post_name'] = sanitize_title( $p['slug'] );
	}
	$existing = eg_pub_find_landing( $external_id );
	if ( $existing ) {
		$postarr['ID']            = $existing;
		$postarr['page_template'] = ''; // La plantilla se copia abajo; así WordPress no valida la que ya tenía.
		$post_id                  = wp_update_post( wp_slash( $postarr ), true );
	} else {
		$postarr['post_author'] = get_current_user_id();
		$post_id                = wp_insert_post( wp_slash( $postarr ), true );
	}
	if ( is_wp_error( $post_id ) ) {
		return eg_pub_error( 'eg_insert', 'WordPress no pudo guardar la página: ' . $post_id->get_error_message(), 500 );
	}
	update_post_meta( $post_id, EG_PUB_META_LANDING, $external_id );

	// --- Diseño de Elementor ---------------------------------------------------
	update_post_meta( $post_id, '_elementor_data', wp_slash( $encoded ) );
	foreach ( array( '_elementor_edit_mode', '_elementor_template_type', '_elementor_version', '_elementor_pro_version', '_elementor_page_settings', '_wp_page_template' ) as $meta_key ) {
		$val = get_post_meta( $template_id, $meta_key, true );
		if ( '' !== $val ) {
			update_post_meta( $post_id, $meta_key, wp_slash( $val ) );
		}
	}
	// Elementor regenera el CSS y la caché de elementos al visitar la página.
	delete_post_meta( $post_id, '_elementor_css' );
	delete_post_meta( $post_id, '_elementor_element_cache' );

	// --- Idioma y traducción (WPML) ------------------------------------------
	if ( $has_wpml && $lang ) {
		$trid     = apply_filters( 'wpml_element_trid', null, $source_id ? $source_id : $post_id, 'post_page' );
		$src_lang = null;
		if ( $source_id ) {
			$src      = apply_filters( 'wpml_element_language_details', null, array( 'element_id' => $source_id, 'element_type' => 'page' ) );
			$src_lang = $src ? $src->language_code : null;
		}
		do_action( 'wpml_set_element_language_details', array(
			'element_id'           => $post_id,
			'element_type'         => 'post_page',
			'trid'                 => $trid ? $trid : false,
			'language_code'        => $lang,
			'source_language_code' => $src_lang,
		) );
	}

	// --- SEO del plugin activo (Yoast SEO o Rank Math) --------------------------
	$seo_values = array();
	foreach ( eg_pub_seo_meta_map() as $field => $keys ) {
		if ( isset( $p[ $field ] ) && is_scalar( $p[ $field ] ) ) {
			$seo_values[ $field ] = strtr( (string) $p[ $field ], $map );
		}
	}
	eg_pub_seo_save( $post_id, $seo_values );

	// --- Imagen destacada: ID, URL o el nombre de una de inline_images ------------
	if ( ! empty( $p['featured_image'] ) ) {
		$feat = $p['featured_image'];
		$feat = is_string( $feat ) && isset( $inline_ids[ sanitize_key( $feat ) ] ) ? $inline_ids[ sanitize_key( $feat ) ] : eg_pub_landing_attachment( $feat, sanitize_title( $p['slug'] ?? $title ) );
		if ( is_wp_error( $feat ) ) {
			$notes[] = 'imagen destacada: ' . $feat->get_error_message();
		} else {
			set_post_thumbnail( $post_id, (int) $feat );
		}
	}

	clean_post_cache( $post_id );
	return new WP_REST_Response( array(
		'ok'           => true,
		'id'           => $post_id,
		'status'       => get_post_status( $post_id ),
		'link'         => get_permalink( $post_id ),
		'edit'         => admin_url( 'post.php?post=' . $post_id . '&action=elementor' ),
		'lang'         => $lang,
		'missing_keys' => $left,
		'image_note'   => implode( ' | ', $notes ),
		'message'      => trim( ( $left ? 'Quedan marcadores sin valor: ' . implode( ', ', $left ) . '. ' : '' ) . ( $notes ? 'Imágenes que fallaron: ' . implode( ' | ', $notes ) : '' ) ),
	), 200 );
}

/**
 * Añade páginas a un menú de WordPress (por nombre o ID; con WPML, la traducción del menú en "lang").
 * Si la página ya está en el menú solo cambia su texto. "remove_titles" quita los elementos cuyo texto
 * contenga alguna de esas cadenas. Con "dry_run" lista los menús y los elementos del menú indicado.
 * "labels" ({id de elemento: texto}) cambia solo el texto de elementos que ya están, sin moverlos, y con
 * "keep_place" las páginas que ya están conservan su sitio y las nuevas van al final (o a su "position"/"parent").
 */
function eg_pub_menu_handle( WP_REST_Request $r ) {
	$p    = $r->get_params();
	$lang = isset( $p['lang'] ) ? sanitize_key( $p['lang'] ) : '';

	$menus = array();
	foreach ( wp_get_nav_menus() as $m ) {
		$info    = apply_filters( 'wpml_element_language_details', null, array( 'element_id' => $m->term_taxonomy_id, 'element_type' => 'tax_nav_menu' ) );
		$menus[] = array( 'id' => (int) $m->term_id, 'name' => $m->name, 'lang' => $info ? $info->language_code : '' );
	}
	$menu = isset( $p['menu'] ) ? wp_get_nav_menu_object( is_numeric( $p['menu'] ) ? (int) $p['menu'] : (string) $p['menu'] ) : false;
	if ( $menu && $lang ) {
		$tr   = apply_filters( 'wpml_object_id', $menu->term_id, 'nav_menu', false, $lang );
		$menu = $tr ? wp_get_nav_menu_object( (int) $tr ) : $menu;
	}
	$items_of = function ( $menu_id ) {
		$out = array();
		foreach ( (array) wp_get_nav_menu_items( $menu_id, array( 'post_status' => 'any' ) ) as $it ) {
			$out[] = array( 'id' => (int) $it->ID, 'title' => $it->title, 'object_id' => (int) $it->object_id, 'url' => $it->url, 'parent' => (int) $it->menu_item_parent, 'order' => (int) $it->menu_order );
		}
		return $out;
	};
	if ( ! empty( $p['dry_run'] ) ) {
		return new WP_REST_Response( array( 'ok' => true, 'menus' => $menus, 'menu' => $menu ? (int) $menu->term_id : 0, 'items' => $menu ? $items_of( $menu->term_id ) : array() ), 200 );
	}
	if ( ! $menu && ! empty( $p['create'] ) && isset( $p['menu'] ) && ! is_numeric( $p['menu'] ) ) {
		// Web nueva: crea el menú con ese nombre (y, con `location`, lo asigna a esa ubicación del tema).
		$new_id = wp_create_nav_menu( sanitize_text_field( (string) $p['menu'] ) );
		if ( is_wp_error( $new_id ) ) {
			return eg_pub_error( 'eg_menu', 'No se ha podido crear el menú: ' . $new_id->get_error_message(), 500 );
		}
		$menu = wp_get_nav_menu_object( $new_id );
	}
	if ( ! $menu ) {
		return eg_pub_error( 'eg_menu', 'No existe el menú indicado. Menús: ' . wp_json_encode( $menus ), 404 );
	}
	if ( ! empty( $p['location'] ) ) {
		$locations = (array) get_theme_mod( 'nav_menu_locations', array() );
		$locations[ sanitize_key( $p['location'] ) ] = (int) $menu->term_id;
		set_theme_mod( 'nav_menu_locations', $locations );
	}
	if ( $lang ) {
		do_action( 'wpml_switch_language', $lang );
	}

	$existing = $items_of( $menu->term_id );
	$removed  = array();
	foreach ( (array) ( $p['remove_titles'] ?? array() ) as $needle ) {
		$needle = (string) $needle;
		foreach ( $existing as $k => $it ) {
			if ( '' !== $needle && false !== stripos( $it['title'], $needle ) ) {
				wp_delete_post( $it['id'], true );
				$removed[] = $it['title'];
				unset( $existing[ $k ] );
			}
		}
	}

	$added   = array();
	$notes   = array();
	$labeled = array();
	$own     = array_column( $existing, null, 'id' );
	foreach ( (array) ( $p['labels'] ?? array() ) as $item_id => $label ) {
		$item_id = (int) $item_id;
		if ( ! isset( $own[ $item_id ] ) ) {
			$notes[] = 'el elemento ' . $item_id . ' no es de este menú';
			continue;
		}
		// El texto del elemento es su post_title; vacío, WordPress muestra el título de la página.
		wp_update_post( array( 'ID' => $item_id, 'post_title' => wp_strip_all_tags( (string) $label ) ) );
		$labeled[] = $item_id;
	}

	$keep = ! empty( $p['keep_place'] );
	$last = $existing ? max( array_column( $existing, 'order' ) ) : 0;
	$pos  = 0;
	foreach ( (array) ( $p['items'] ?? array() ) as $item ) {
		++$pos; // Las páginas quedan en el orden de "items" (con WPML el menú puede no listar sus elementos durante la petición).
		$page_id = ! empty( $item['landing_id'] ) ? eg_pub_find_landing( $item['landing_id'] ) : (int) ( $item['page_id'] ?? 0 );
		if ( ! $page_id || 'page' !== get_post_type( $page_id ) ) {
			$notes[] = 'no encuentro la página ' . ( $item['landing_id'] ?? ( $item['page_id'] ?? '' ) );
			continue;
		}
		$title   = isset( $item['title'] ) ? wp_strip_all_tags( $item['title'] ) : get_the_title( $page_id );
		$item_id = 0;
		$place   = array( 'order' => $pos, 'parent' => 0 );
		foreach ( $existing as $it ) {
			if ( $it['object_id'] === $page_id ) {
				$item_id = $it['id'];
				if ( $keep ) {
					$place = $it;
				}
			}
		}
		if ( $keep && ! $item_id ) {
			$place['order'] = ++$last;
		}
		$res = wp_update_nav_menu_item( $menu->term_id, $item_id, array(
			'menu-item-object-id' => $page_id,
			'menu-item-object'    => 'page',
			'menu-item-type'      => 'post_type',
			'menu-item-title'     => $title,
			'menu-item-status'    => 'publish',
			'menu-item-position'  => isset( $item['position'] ) ? (int) $item['position'] : $place['order'],
			'menu-item-parent-id' => isset( $item['parent'] ) ? (int) $item['parent'] : $place['parent'],
		) );
		if ( is_wp_error( $res ) ) {
			$notes[] = $title . ': ' . $res->get_error_message();
		} else {
			$added[] = $title;
		}
	}
	return new WP_REST_Response( array(
		'ok'      => true,
		'menu'    => (int) $menu->term_id,
		'added'   => $added,
		'labeled' => $labeled,
		'removed' => $removed,
		'message' => implode( ' | ', $notes ),
		'items'   => $items_of( $menu->term_id ),
	), 200 );
}

function eg_pub_redirect_handle( WP_REST_Request $r ) {
	$from = trim( (string) $r->get_param( 'from' ) );
	$to   = trim( (string) $r->get_param( 'to' ) );
	$code = (int) ( $r->get_param( 'code' ) ? $r->get_param( 'code' ) : 301 );

	if ( '' === $from || '' === $to ) {
		return eg_pub_error( 'eg_missing', 'Faltan datos obligatorios: from o to.' );
	}
	if ( ! in_array( $code, array( 301, 302, 307, 410, 451 ), true ) ) {
		return eg_pub_error( 'eg_code', 'Código de redirección no válido: ' . $code );
	}
	if ( ! class_exists( '\RankMath\Redirections\Redirection' ) ) {
		return eg_pub_error( 'eg_rankmath', 'Rank Math no está activo o tiene desactivado el módulo de Redirecciones.', 501 );
	}
	// Rank Math guarda la URL de origen sin dominio ni barra inicial.
	$path = trim( (string) wp_parse_url( $from, PHP_URL_PATH ), '/' );
	if ( '' === $path ) {
		return eg_pub_error( 'eg_from', 'La URL de origen no puede ser la portada.' );
	}
	if ( 0 === strpos( $to, '/' ) ) {
		// get_option y no home_url: con WPML, home_url() quita el prefijo de idioma (/ca/) de la ruta.
		$to = untrailingslashit( get_option( 'home' ) ) . $to;
	}

	// Rank Math actualiza la redirección que ya tenga ese mismo origen en lugar de crear otra.
	$id = \RankMath\Redirections\Redirection::from( array(
		'url_to'      => esc_url_raw( $to ),
		'header_code' => $code,
		'status'      => 'active',
		'sources'     => array( array( 'pattern' => $path, 'comparison' => 'exact' ) ),
	) )->save();
	if ( ! $id ) {
		return eg_pub_error( 'eg_redirect', 'Rank Math no ha guardado la redirección de ' . $path . '.', 409 );
	}
	return new WP_REST_Response( array( 'ok' => true, 'id' => (int) $id, 'from' => $path, 'to' => esc_url_raw( $to ), 'code' => $code ), 200 );
}

/* ======================================================================
 * Estilo de las fotos integradas en el texto de las landings.
 * ==================================================================== */
add_filter( 'body_class', function ( $classes ) {
	if ( is_page() && get_post_meta( get_queried_object_id(), EG_PUB_META_LANDING, true ) ) {
		$classes[] = 'eg-landing';
	}
	return $classes;
} );

add_action( 'wp_head', function () {
	if ( ! is_page() || ! get_post_meta( get_queried_object_id(), EG_PUB_META_LANDING, true ) ) {
		return;
	}
	echo '<style id="eg-landing-css">'
		. '.eg-landing-img{float:right;width:42%;max-width:440px;margin:.4em 0 1.2em 2em}'
		. '.eg-landing-img--left{float:left;margin:.4em 2em 1.2em 0}'
		. '.eg-landing-img img{display:block;width:100%;height:auto;aspect-ratio:4/3;object-fit:cover;border-radius:6px}'
		. '.eg-landing-photo img{display:block;width:100%;height:auto;aspect-ratio:4/3;object-fit:cover;border-radius:6px}'
		. '.eg-landing .elementor-widget-text-editor h2{clear:both}'
		. '.eg-landing .elementor-widget-text-editor:after{content:"";display:table;clear:both}'
		. '@media (max-width:767px){.eg-landing-img,.eg-landing-img--left{float:none;width:100%;max-width:none;margin:1em 0 1.2em}}'
		. '</style>' . "\n";
} );
