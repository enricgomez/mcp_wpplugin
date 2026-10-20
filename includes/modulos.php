<?php
/**
 * Módulos opcionales: cada web activa solo lo que usa (Ajustes → EG Publicación).
 *
 * La publicación de posts (/publish) va siempre. El resto se enciende por módulo; un módulo apagado no
 * registra sus endpoints ni sus abilities. Los módulos de un cliente concreto van en includes/clientes/*.php:
 * se cargan en todas las webs, pero solo funcionan donde se activan, y se añaden con el filtro eg_pub_modules.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_OPTION_MODULES = 'eg_pub_modules';

/** Módulos disponibles: slug => array( label, description, routes, client ). */
function eg_pub_modules() {
	return apply_filters( 'eg_pub_modules', array(
		'landings'      => array(
			'label'       => 'Landings',
			'description' => 'Clona una página maestra de Elementor y rellena sus marcadores {{clave}}.',
			'routes'      => array( 'landing' ),
		),
		'textos'        => array(
			'label'       => 'Textos',
			'description' => 'Busca y sustituye textos visibles en toda la web.',
			'routes'      => array( 'text' ),
		),
		'plantillas'    => array(
			'label'       => 'Plantillas',
			'description' => 'Plantillas de Elementor reutilizables y añadirlas al pie de páginas.',
			'routes'      => array( 'template', 'append' ),
		),
		'menus'         => array(
			'label'       => 'Menús',
			'description' => 'Añade, ordena y quita páginas de los menús.',
			'routes'      => array( 'menu' ),
		),
		'redirecciones' => array(
			'label'       => 'Redirecciones',
			'description' => 'Crea redirecciones en Rank Math.',
			'routes'      => array( 'redirect' ),
		),
	) );
}

/** Slugs de los módulos activos en esta web. */
function eg_pub_active_modules() {
	$saved = get_option( EG_PUB_OPTION_MODULES, null );
	if ( is_array( $saved ) ) {
		return array_values( array_intersect( array_keys( eg_pub_modules() ), $saved ) );
	}
	// Webs que vienen de la 2.5.x: el interruptor único de landings encendía todo lo general.
	if ( get_option( EG_PUB_OPTION_LANDINGS, false ) ) {
		return array_keys( array_filter( eg_pub_modules(), function ( $m ) {
			return empty( $m['client'] );
		} ) );
	}
	return array();
}

function eg_pub_module_enabled( $slug ) {
	return in_array( $slug, eg_pub_active_modules(), true );
}

function eg_pub_save_modules( $slugs ) {
	$slugs = array_values( array_intersect( array_keys( eg_pub_modules() ), array_map( 'sanitize_key', (array) $slugs ) ) );
	update_option( EG_PUB_OPTION_MODULES, $slugs, false );
	// Si se vuelve a una versión 2.5.x, que el interruptor antiguo refleje si hay algo encendido.
	update_option( EG_PUB_OPTION_LANDINGS, (bool) $slugs, false );
}

/** Permiso de los endpoints de módulos: la clave X-EG-Key y además esta capacidad del autor configurado. */
function eg_pub_module_permission( $cap ) {
	return function ( WP_REST_Request $r ) use ( $cap ) {
		$ok = eg_pub_permission( $r );
		return ( true === $ok && ! current_user_can( $cap ) ) ? false : $ok;
	};
}

foreach ( glob( __DIR__ . '/clientes/*.php' ) ?: array() as $eg_pub_client_file ) {
	require_once $eg_pub_client_file;
}
unset( $eg_pub_client_file );
