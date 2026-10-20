<?php
/**
 * Módulo opcional de landings (desactivado por defecto, se activa en Ajustes → EG Publicación):
 *   POST /wp-json/eg/v1/landing   clona una página maestra de Elementor y sustituye sus marcadores {{clave}}.
 *   POST /wp-json/eg/v1/redirect  crea una redirección en Rank Math.
 * Usan la misma clave X-EG-Key que /publish.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_OPTION_LANDINGS = 'eg_pub_landings_enabled';
const EG_PUB_META_LANDING    = '_eg_landing_id';

function eg_pub_landings_enabled() {
	return (bool) get_option( EG_PUB_OPTION_LANDINGS, false );
}

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_landings_enabled() ) {
		return;
	}
	register_rest_route( 'eg/v1', '/landing', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_landing_handle',
		'permission_callback' => function ( WP_REST_Request $r ) {
			$ok = eg_pub_permission( $r );
			return ( true === $ok && ! current_user_can( 'publish_pages' ) ) ? false : $ok;
		},
	) );
	register_rest_route( 'eg/v1', '/redirect', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_redirect_handle',
		'permission_callback' => function ( WP_REST_Request $r ) {
			$ok = eg_pub_permission( $r );
			return ( true === $ok && ! current_user_can( 'manage_options' ) ) ? false : $ok;
		},
	) );
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
 * Recorre el JSON de Elementor: sustituye marcadores en los textos y da un ID nuevo a cada elemento.
 */
function eg_pub_landing_walk( $node, $map ) {
	if ( is_string( $node ) ) {
		return strtr( $node, $map );
	}
	if ( ! is_array( $node ) ) {
		return $node;
	}
	foreach ( $node as $k => $v ) {
		$node[ $k ] = eg_pub_landing_walk( $v, $map );
	}
	if ( isset( $node['id'], $node['elType'] ) ) {
		$node['id'] = substr( md5( uniqid( '', true ) . wp_rand() ), 0, 7 );
	}
	return $node;
}

function eg_pub_landing_handle( WP_REST_Request $r ) {
	$p = $r->get_params();

	$external_id = isset( $p['landing_id'] ) ? sanitize_text_field( $p['landing_id'] ) : '';
	$template_id = isset( $p['template_id'] ) ? (int) $p['template_id'] : 0;
	$lang        = isset( $p['lang'] ) ? sanitize_key( $p['lang'] ) : '';
	$title       = isset( $p['title'] ) ? wp_strip_all_tags( $p['title'] ) : '';

	if ( '' === $external_id || ! $template_id || '' === $title ) {
		return eg_pub_error( 'eg_missing', 'Faltan datos obligatorios: landing_id, template_id o title.' );
	}
	$template = get_post( $template_id );
	$raw      = $template ? get_post_meta( $template_id, '_elementor_data', true ) : '';
	$data     = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
	if ( ! $template || ! is_array( $data ) ) {
		return eg_pub_error( 'eg_template', 'La página maestra ' . $template_id . ' no existe o no está hecha con Elementor.', 404 );
	}

	// Marcadores: {"municipio":"Blanes"} sustituye {{municipio}}.
	$map = array();
	foreach ( (array) ( $p['replacements'] ?? array() ) as $key => $value ) {
		$map[ '{{' . sanitize_key( $key ) . '}}' ] = wp_kses_post( (string) $value );
	}
	$data    = eg_pub_landing_walk( $data, $map );
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
		'post_content' => strtr( (string) $template->post_content, $map ),
		'post_status'  => $status,
		'post_parent'  => isset( $p['parent_id'] ) ? (int) $p['parent_id'] : 0,
	);
	if ( ! empty( $p['slug'] ) ) {
		$postarr['post_name'] = sanitize_title( $p['slug'] );
	}
	$existing = eg_pub_find_landing( $external_id );
	if ( $existing ) {
		$postarr['ID'] = $existing;
		$post_id       = wp_update_post( wp_slash( $postarr ), true );
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

	// --- SEO de Rank Math --------------------------------------------------------
	$seo = array(
		'seo_title'       => 'rank_math_title',
		'seo_description' => 'rank_math_description',
		'focus_keyword'   => 'rank_math_focus_keyword',
	);
	foreach ( $seo as $param => $meta_key ) {
		if ( isset( $p[ $param ] ) && '' !== trim( $p[ $param ] ) ) {
			update_post_meta( $post_id, $meta_key, sanitize_text_field( strtr( $p[ $param ], $map ) ) );
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
		'message'      => $left ? 'Página guardada, pero quedan marcadores sin valor: ' . implode( ', ', $left ) : '',
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
		$to = home_url( $to );
	}

	$redirection = \RankMath\Redirections\Redirection::from( array(
		'url_to'      => esc_url_raw( $to ),
		'header_code' => $code,
		'status'      => 'active',
		'sources'     => array( array( 'pattern' => $path, 'comparison' => 'exact' ) ),
	) );
	$id = $redirection->save();
	if ( ! $id ) {
		return eg_pub_error( 'eg_redirect', 'Rank Math no ha guardado la redirección (¿ya existe una para ' . $path . '?).', 409 );
	}
	return new WP_REST_Response( array( 'ok' => true, 'id' => (int) $id, 'from' => $path, 'to' => $to, 'code' => $code ), 200 );
}
