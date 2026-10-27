<?php
/**
 * Bloques reutilizables de Elementor (módulo opcional «Plantillas»):
 *   POST /wp-json/eg/v1/template  crea o actualiza una plantilla de Elementor (Plantillas guardadas) por clave e idioma.
 *   POST /wp-json/eg/v1/append    añade al final de varias páginas una sección con un título H2 y esa plantilla
 *                                 (widget «Plantilla» de Elementor Pro). Si la sección ya estaba, la sustituye.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_META_TEMPLATE_KEY = '_eg_template_key';

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_module_enabled( 'plantillas' ) ) {
		return;
	}
	foreach ( array( 'template' => 'eg_pub_template_handle', 'append' => 'eg_pub_append_handle' ) as $route => $callback ) {
		register_rest_route( 'eg/v1', '/' . $route, array(
			'methods'             => 'POST',
			'callback'            => $callback,
			'permission_callback' => eg_pub_module_permission( 'edit_pages' ),
		) );
	}
} );

/** ID de la plantilla con esa clave en ese idioma (0 si no existe). */
function eg_pub_find_template( $key, $lang = '' ) {
	$ids = get_posts( array(
		'post_type'        => 'elementor_library',
		'post_status'      => 'any',
		'meta_key'         => EG_PUB_META_TEMPLATE_KEY,
		'meta_value'       => $key . ( $lang ? '-' . $lang : '' ),
		'fields'           => 'ids',
		'numberposts'      => 1,
		'suppress_filters' => true,
	) );
	return $ids ? (int) $ids[0] : 0;
}

/** Da un ID nuevo de Elementor a cada elemento que no lo tenga. */
function eg_pub_template_ids( $elements ) {
	foreach ( $elements as &$el ) {
		if ( empty( $el['id'] ) ) {
			$el['id'] = eg_pub_landing_el_id();
		}
		if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
			$el['elements'] = eg_pub_template_ids( $el['elements'] );
		}
	}
	return $elements;
}

function eg_pub_template_handle( WP_REST_Request $r ) {
	$p        = $r->get_params();
	$key      = sanitize_key( $p['key'] ?? '' );
	$lang     = sanitize_key( $p['lang'] ?? '' );
	$title    = wp_strip_all_tags( $p['title'] ?? '' );
	$elements = $p['elements'] ?? null;
	$type     = sanitize_key( $p['type'] ?? 'section' );
	$status   = in_array( $p['status'] ?? '', array( 'publish', 'draft', 'private' ), true ) ? $p['status'] : 'publish';
	if ( ! empty( $p['id'] ) && ! empty( $p['copy_from'] ) ) {
		return eg_pub_template_copy( (int) $p['id'], (int) $p['copy_from'], ! empty( $p['copy_title'] ) );
	}
	if ( ! empty( $p['id'] ) && ( isset( $p['patch'] ) || isset( $p['replace'] ) ) ) {
		return eg_pub_template_patch( (int) $p['id'], $p['patch'] ?? array(), $p['replace'] ?? array() );
	}
	if ( '' === $key || '' === $title || ! is_array( $elements ) || ! $elements ) {
		return eg_pub_error( 'eg_missing', 'Faltan datos obligatorios: key, title o elements (JSON de Elementor).' );
	}

	$postarr = array(
		'post_type'   => 'elementor_library',
		'post_status' => $status,
		'post_title'  => $title,
	);
	$id = eg_pub_find_template( $key, $lang );
	if ( $id ) {
		$postarr['ID'] = $id;
		$id            = wp_update_post( wp_slash( $postarr ), true );
	} else {
		$postarr['post_author'] = get_current_user_id();
		$id                     = wp_insert_post( wp_slash( $postarr ), true );
	}
	if ( is_wp_error( $id ) ) {
		return eg_pub_error( 'eg_insert', 'WordPress no pudo guardar la plantilla: ' . $id->get_error_message(), 500 );
	}
	update_post_meta( $id, EG_PUB_META_TEMPLATE_KEY, $key . ( $lang ? '-' . $lang : '' ) );
	update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( eg_pub_template_ids( $elements ) ) ) );
	update_post_meta( $id, '_elementor_edit_mode', 'builder' );
	update_post_meta( $id, '_elementor_template_type', $type );
	if ( defined( 'ELEMENTOR_VERSION' ) ) {
		update_post_meta( $id, '_elementor_version', ELEMENTOR_VERSION );
	}
	wp_set_object_terms( $id, $type, 'elementor_library_type' );
	if ( isset( $p['settings'] ) && is_array( $p['settings'] ) ) {
		update_post_meta( $id, '_elementor_page_settings', $p['settings'] );
	}
	$conditions = isset( $p['conditions'] ) && is_array( $p['conditions'] ) ? eg_pub_template_conditions( $id, $p['conditions'] ) : null;
	delete_post_meta( $id, '_elementor_css' );
	delete_post_meta( $id, '_elementor_element_cache' );

	if ( $lang && has_filter( 'wpml_element_trid' ) ) {
		$source   = ! empty( $p['translation_of'] ) ? eg_pub_find_template( $key, sanitize_key( $p['translation_of'] ) ) : 0;
		$trid     = apply_filters( 'wpml_element_trid', null, $source ? $source : $id, 'post_elementor_library' );
		do_action( 'wpml_set_element_language_details', array(
			'element_id'           => $id,
			'element_type'         => 'post_elementor_library',
			'trid'                 => $trid ? $trid : false,
			'language_code'        => $lang,
			'source_language_code' => $source ? sanitize_key( $p['translation_of'] ) : null,
		) );
	}

	// Avisos útiles para formularios: reCAPTCHA necesita claves en Elementor → Ajustes → Integraciones.
	$recaptcha = array(
		'v2' => '' !== (string) get_option( 'elementor_pro_recaptcha_site_key' ) && '' !== (string) get_option( 'elementor_pro_recaptcha_secret_key' ),
		'v3' => '' !== (string) get_option( 'elementor_pro_recaptcha_v3_site_key' ) && '' !== (string) get_option( 'elementor_pro_recaptcha_v3_secret_key' ),
	);
	return new WP_REST_Response( array(
		'ok'          => true,
		'id'          => (int) $id,
		'key'         => $key,
		'lang'        => $lang,
		'conditions'  => $conditions,
		'elementor_pro' => defined( 'ELEMENTOR_PRO_VERSION' ),
		'recaptcha'   => $recaptcha,
		'admin_email' => get_option( 'admin_email' ),
	), 200 );
}

/**
 * Cambia ajustes de elementos concretos del JSON de Elementor de una plantilla o página existente
 * (p. ej. la lista de enlaces del pie). `patch` = {"<id de elemento>": {ajustes que se sustituyen}}.
 * `replace` = {"<id de elemento>": {elemento completo}} cambia ese elemento entero, con sus hijos
 * (para reestructurar una sección sin reenviar toda la página); se aplica antes que `patch`.
 * Solo toca _elementor_data; el resto del documento queda igual.
 */
function eg_pub_template_patch( $id, $patch, $replace = array() ) {
	$post    = get_post( $id );
	$patch   = is_array( $patch ) ? $patch : array();
	$replace = is_array( $replace ) ? array_filter( $replace, fn( $el ) => is_array( $el ) && ! empty( $el['elType'] ) ) : array();
	if ( ! $post || ( ! $patch && ! $replace ) ) {
		return eg_pub_error( 'eg_missing', 'Faltan datos: id de una plantilla o página y patch {"id de elemento": {ajustes}} o replace {"id de elemento": {elemento}}.' );
	}
	if ( ! current_user_can( 'edit_post', $id ) ) {
		return eg_pub_error( 'eg_forbidden', 'No se puede editar ese contenido.', 403 );
	}
	$data = json_decode( (string) get_post_meta( $id, '_elementor_data', true ), true );
	if ( ! is_array( $data ) ) {
		return eg_pub_error( 'eg_not_elementor', 'Ese contenido no tiene JSON de Elementor.', 400 );
	}
	$found    = array();
	$replaced = array();
	$walk     = function ( $elements ) use ( &$walk, $patch, $replace, &$found, &$replaced ) {
		foreach ( $elements as &$el ) {
			$eid = (string) ( $el['id'] ?? '' );
			if ( isset( $replace[ $eid ] ) ) {
				$el = $replace[ $eid ] + array( 'id' => $eid, 'settings' => array(), 'elements' => array() );
				$replaced[] = $eid;
			}
			if ( isset( $patch[ $eid ] ) && is_array( $patch[ $eid ] ) ) {
				$el['settings'] = array_merge( (array) ( $el['settings'] ?? array() ), $patch[ $eid ] );
				$found[]        = $eid;
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$el['elements'] = $walk( $el['elements'] );
			}
		}
		return $elements;
	};
	$data    = $walk( $data );
	$missing = array_values( array_diff( array_map( 'strval', array_merge( array_keys( $patch ), array_keys( $replace ) ) ), $found, $replaced ) );
	if ( ! $found && ! $replaced ) {
		return eg_pub_error( 'eg_not_found', 'No se encontró ningún elemento con esos ids.', 404 );
	}
	update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
	delete_post_meta( $id, '_elementor_css' );
	delete_post_meta( $id, '_elementor_element_cache' );
	return new WP_REST_Response( array( 'ok' => true, 'id' => $id, 'patched' => $found, 'replaced' => $replaced, 'missing' => $missing ), 200 );
}

/**
 * Pasa el diseño de una página a otra que ya existe (p. ej. de un borrador revisado a la página publicada),
 * sin cambiar su URL, su slug ni su idioma en WPML. Copia el JSON de Elementor, sus ajustes, la plantilla de
 * página y el SEO (título, descripción y frase clave); con `copy_title` también el título. Antes guarda el JSON
 * anterior en `_eg_pub_elementor_backup` para poder deshacerlo.
 */
function eg_pub_template_copy( $id, $from, $copy_title = false ) {
	$post   = get_post( $id );
	$source = get_post( $from );
	if ( ! $post || ! $source || $id === $from ) {
		return eg_pub_error( 'eg_missing', 'Faltan datos: id (página de destino) y copy_from (página de origen), distintas.' );
	}
	if ( ! current_user_can( 'edit_post', $id ) ) {
		return eg_pub_error( 'eg_forbidden', 'No se puede editar ese contenido.', 403 );
	}
	$data = get_post_meta( $from, '_elementor_data', true );
	if ( ! is_array( json_decode( (string) $data, true ) ) ) {
		return eg_pub_error( 'eg_not_elementor', 'La página de origen no tiene JSON de Elementor.', 400 );
	}
	$old = get_post_meta( $id, '_elementor_data', true );
	if ( '' !== $old ) {
		update_post_meta( $id, '_eg_pub_elementor_backup', wp_slash( wp_json_encode( array( 'time' => time(), 'title' => $post->post_title, 'data' => $old ) ) ) );
	}
	update_post_meta( $id, '_elementor_data', wp_slash( $data ) );
	foreach ( array( '_elementor_edit_mode', '_elementor_template_type', '_elementor_version', '_elementor_pro_version', '_elementor_page_settings', '_wp_page_template' ) as $meta_key ) {
		$val = get_post_meta( $from, $meta_key, true );
		if ( '' !== $val ) {
			update_post_meta( $id, $meta_key, wp_slash( $val ) );
		}
	}
	$seo = array();
	foreach ( eg_pub_seo_meta_map() as $field => $keys ) {
		if ( 'canonical_url' === $field ) {
			continue;
		}
		foreach ( $keys as $meta_key ) {
			$val = get_post_meta( $from, $meta_key, true );
			if ( '' !== $val ) {
				$seo[ $field ] = $val;
				break;
			}
		}
	}
	eg_pub_seo_save( $id, $seo );
	if ( $copy_title && $source->post_title !== $post->post_title ) {
		wp_update_post( array( 'ID' => $id, 'post_title' => $source->post_title ) );
	}
	delete_post_meta( $id, '_elementor_css' );
	delete_post_meta( $id, '_elementor_element_cache' );
	if ( function_exists( 'rocket_clean_post' ) ) {
		rocket_clean_post( $id );
	}
	return new WP_REST_Response( array( 'ok' => true, 'id' => $id, 'copied_from' => $from, 'title' => get_the_title( $id ), 'link' => get_permalink( $id ), 'seo' => array_keys( $seo ) ), 200 );
}

/**
 * Condiciones de visualización del Theme Builder de Elementor Pro (p. ej. "include/singular/post",
 * "include/archive/category"). Guarda _elementor_conditions y rehace la caché de condiciones,
 * que es lo que Elementor Pro consulta. Con [] quita las condiciones. Devuelve las guardadas.
 */
function eg_pub_template_conditions( $id, $conditions ) {
	$clean = array();
	foreach ( $conditions as $c ) {
		$c = trim( (string) $c, " /\t\n" );
		if ( preg_match( '#^(include|exclude)(/[a-z0-9_-]+){1,4}$#', $c ) ) {
			$clean[] = $c;
		}
	}
	$clean = array_values( array_unique( $clean ) );
	if ( $clean ) {
		update_post_meta( $id, '_elementor_conditions', $clean );
	} else {
		delete_post_meta( $id, '_elementor_conditions' );
	}

	$regenerated = false;
	if ( class_exists( '\\ElementorPro\\Modules\\ThemeBuilder\\Module' ) ) {
		$manager = \ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager();
		if ( method_exists( $manager, 'get_cache' ) ) {
			$cache = $manager->get_cache();
			if ( method_exists( $cache, 'regenerate' ) ) {
				$cache->regenerate();
				$regenerated = true;
			}
		}
	}
	if ( ! $regenerated ) {
		// Sin la API de Elementor Pro: se escribe la caché a mano (ubicación = tipo sin «-post»/«-page»).
		$location = preg_replace( '/-(post|page)$/', '', (string) get_post_meta( $id, '_elementor_template_type', true ) );
		$cache    = get_option( 'elementor_pro_theme_builder_conditions', array() );
		$cache    = is_array( $cache ) ? $cache : array();
		foreach ( $cache as $loc => $templates ) {
			unset( $cache[ $loc ][ $id ] );
		}
		if ( $clean && $location ) {
			$cache[ $location ][ $id ] = $clean;
		}
		update_option( 'elementor_pro_theme_builder_conditions', $cache );
	}
	return $clean;
}

/** Quita de la lista de elementos los que lleven la clase CSS indicada (en cualquier nivel). */
function eg_pub_append_strip( $elements, $class ) {
	$out = array();
	foreach ( $elements as $el ) {
		$classes = ' ' . ( $el['settings']['_css_classes'] ?? ( $el['settings']['css_classes'] ?? '' ) ) . ' ';
		if ( false !== strpos( $classes, ' ' . $class . ' ' ) ) {
			continue;
		}
		if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
			$el['elements'] = eg_pub_append_strip( $el['elements'], $class );
		}
		$out[] = $el;
	}
	return $out;
}

/** Sección (o contenedor, si la página usa contenedores) con un H2 y el widget Plantilla. */
function eg_pub_append_block( $class, $heading, $template_id, $container ) {
	$widgets = array();
	if ( '' !== $heading ) {
		$widgets[] = array(
			'id'         => eg_pub_landing_el_id(),
			'elType'     => 'widget',
			'widgetType' => 'heading',
			'settings'   => array( 'title' => $heading, 'header_size' => 'h2', 'align' => 'center' ),
			'elements'   => array(),
		);
	}
	$widgets[] = array(
		'id'         => eg_pub_landing_el_id(),
		'elType'     => 'widget',
		'widgetType' => 'template',
		'settings'   => array( 'template_id' => (string) $template_id ),
		'elements'   => array(),
	);
	if ( $container ) {
		return array(
			'id'       => eg_pub_landing_el_id(),
			'elType'   => 'container',
			'settings' => array( 'css_classes' => $class, 'content_width' => 'boxed', 'flex_direction' => 'column' ),
			'elements' => $widgets,
			'isInner'  => false,
		);
	}
	return array(
		'id'       => eg_pub_landing_el_id(),
		'elType'   => 'section',
		'settings' => array( '_css_classes' => $class, 'css_classes' => $class, 'gap' => 'default', 'padding' => array( 'unit' => 'px', 'top' => '40', 'right' => '0', 'bottom' => '60', 'left' => '0', 'isLinked' => false ) ),
		'elements' => array(
			array(
				'id'       => eg_pub_landing_el_id(),
				'elType'   => 'column',
				'settings' => array( '_column_size' => 100 ),
				'elements' => $widgets,
				'isInner'  => false,
			),
		),
		'isInner'  => false,
	);
}

function eg_pub_append_handle( WP_REST_Request $r ) {
	$p      = $r->get_params();
	$key    = sanitize_key( $p['template_key'] ?? '' );
	$lang   = sanitize_key( $p['lang'] ?? '' );
	$class  = 'eg-append-' . ( $key ? $key : 'bloque' );
	$remove = ! empty( $p['remove'] );
	$tpl_id = $key ? eg_pub_find_template( $key, $lang ) : 0;
	if ( ! $remove && ! $tpl_id ) {
		return eg_pub_error( 'eg_template', 'No existe la plantilla ' . $key . ( $lang ? ' (' . $lang . ')' : '' ) . '. Créala antes con /template.', 404 );
	}

	$items = (array) ( $p['pages'] ?? array() );
	// children_of: añade las páginas hijas publicadas de esa página (con el título por defecto).
	if ( ! empty( $p['children_of'] ) ) {
		foreach ( get_pages( array( 'child_of' => (int) $p['children_of'], 'post_status' => 'publish', 'suppress_filters' => true ) ) as $child ) {
			$items[] = array( 'page_id' => $child->ID );
		}
	}
	$pattern = (string) ( $p['heading_pattern'] ?? '' );

	$done  = array();
	$notes = array();
	$seen  = array();
	foreach ( $items as $item ) {
		$page_id = ! empty( $item['landing_id'] ) ? eg_pub_find_landing( $item['landing_id'] ) : (int) ( $item['page_id'] ?? 0 );
		if ( ! $page_id || isset( $seen[ $page_id ] ) ) {
			if ( ! $page_id ) {
				$notes[] = 'no encuentro la página ' . ( $item['landing_id'] ?? ( $item['page_id'] ?? '' ) );
			}
			continue;
		}
		$seen[ $page_id ] = true;
		$raw  = get_post_meta( $page_id, '_elementor_data', true );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		if ( ! is_array( $data ) ) {
			$notes[] = $page_id . ': no está hecha con Elementor';
			continue;
		}
		$heading = isset( $item['heading'] ) ? wp_strip_all_tags( $item['heading'] ) : str_replace( '{title}', get_the_title( $page_id ), $pattern );
		$data    = eg_pub_append_strip( $data, $class );
		if ( ! $remove ) {
			$container = isset( $data[0]['elType'] ) && 'container' === $data[0]['elType'];
			$data[]    = eg_pub_append_block( $class, $heading, $tpl_id, $container );
		}
		if ( empty( $p['dry_run'] ) ) {
			update_post_meta( $page_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
			delete_post_meta( $page_id, '_elementor_css' );
			delete_post_meta( $page_id, '_elementor_element_cache' );
			clean_post_cache( $page_id );
		}
		$done[] = array( 'id' => $page_id, 'title' => get_the_title( $page_id ), 'heading' => $remove ? '' : $heading );
	}

	if ( $done && empty( $p['dry_run'] ) ) {
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
	}
	return new WP_REST_Response( array(
		'ok'          => true,
		'template_id' => $tpl_id,
		'removed'     => $remove,
		'pages'       => $done,
		'message'     => implode( ' | ', $notes ),
	), 200 );
}
