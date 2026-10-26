<?php
/**
 * Qué hace el plugin en cada web, por tipología (Ajustes → EG Publicación): Blog, Elementor, WooCommerce y
 * Gestión de la web. Cada tipología agrupa módulos (slug => endpoints); un módulo de una tipología apagada no
 * registra sus endpoints ni sus abilities. Lo nuevo se añade como módulo de su tipología con el filtro
 * eg_pub_modules ('type' => 'woocommerce', etc.).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_OPTION_MODULES = 'eg_pub_modules'; // Hasta la 2.7.x: módulos sueltos. Se sigue escribiendo por si se vuelve atrás.
const EG_PUB_OPTION_TYPES   = 'eg_pub_types';

/** Tipologías: slug => array( label, description, requires ). requires = constante del plugin que hace falta. */
function eg_pub_types() {
	return array(
		'blog'        => array(
			'label'       => 'Blog',
			'description' => 'Publica y actualiza posts desde Make (Notion), con idiomas de WPML, categoría, SEO e imágenes, y avisa por email al publicarse.',
		),
		'elementor'   => array(
			'label'       => 'Elementor',
			'description' => 'Landings a partir de una página maestra y plantillas reutilizables al pie de las páginas.',
			'requires'    => 'ELEMENTOR_VERSION',
		),
		'woocommerce' => array(
			'label'       => 'WooCommerce',
			'description' => 'Productos en todos sus idiomas: textos, campos de JetEngine, SEO y categorías.',
			'requires'    => 'WC_VERSION',
		),
		'gestion'     => array(
			'label'       => 'Gestión de la web',
			'description' => 'Buscar y sustituir textos, menús, redirecciones de Rank Math, textos y SEO de categorías, publicar o pasar a borrador páginas y entradas, y poner el enlace de cada idioma (p. ej. el motor de reservas) en las traducciones.',
		),
	);
}

/** Módulos: slug => array( type, routes ). */
function eg_pub_modules() {
	return apply_filters( 'eg_pub_modules', array(
		'blog'          => array( 'type' => 'blog', 'routes' => array( 'publish' ) ),
		'landings'      => array( 'type' => 'elementor', 'routes' => array( 'landing' ) ),
		'plantillas'    => array( 'type' => 'elementor', 'routes' => array( 'template', 'append' ) ),
		'textos'        => array( 'type' => 'gestion', 'routes' => array( 'text' ) ),
		'menus'         => array( 'type' => 'gestion', 'routes' => array( 'menu' ) ),
		'redirecciones' => array( 'type' => 'gestion', 'routes' => array( 'redirect' ) ),
	) );
}

/** Tipologías activas en esta web. */
function eg_pub_active_types() {
	$saved = get_option( EG_PUB_OPTION_TYPES, null );
	if ( is_array( $saved ) ) {
		return array_values( array_intersect( array_keys( eg_pub_types() ), $saved ) );
	}
	// Webs que vienen de la 2.7.x o antes: el blog estaba siempre activo y cada módulo suelto pasa a su tipología.
	$old = get_option( EG_PUB_OPTION_MODULES, null );
	if ( ! is_array( $old ) ) {
		// 2.5.x: el interruptor único de landings encendía todo lo general.
		$old = get_option( EG_PUB_OPTION_LANDINGS, false ) ? array( 'landings', 'plantillas', 'textos', 'menus', 'redirecciones', 'contenido' ) : array();
	}
	$map   = wp_list_pluck( eg_pub_modules(), 'type' );
	$types = array( 'blog' );
	foreach ( $old as $slug ) {
		if ( isset( $map[ $slug ] ) ) {
			$types[] = $map[ $slug ];
		}
	}
	return array_values( array_intersect( array_keys( eg_pub_types() ), $types ) );
}

function eg_pub_type_enabled( $type ) {
	return in_array( $type, eg_pub_active_types(), true );
}

/** Slugs de los módulos activos en esta web. */
function eg_pub_active_modules() {
	return array_keys( array_filter( eg_pub_modules(), function ( $m ) {
		return eg_pub_type_enabled( $m['type'] );
	} ) );
}

function eg_pub_module_enabled( $slug ) {
	$modules = eg_pub_modules();
	return isset( $modules[ $slug ] ) && eg_pub_type_enabled( $modules[ $slug ]['type'] );
}

function eg_pub_save_types( $types ) {
	$types = array_values( array_intersect( array_keys( eg_pub_types() ), array_map( 'sanitize_key', (array) $types ) ) );
	update_option( EG_PUB_OPTION_TYPES, $types, false );
	// Si se vuelve a una versión 2.7.x o anterior, que encuentre los mismos módulos encendidos.
	$old = array_diff( eg_pub_active_modules(), array( 'blog' ) );
	update_option( EG_PUB_OPTION_MODULES, array_values( $old ), false );
	update_option( EG_PUB_OPTION_LANDINGS, (bool) $old, false );
}

/** Permiso de los endpoints de módulos: la clave X-EG-Key y además esta capacidad del autor configurado. */
function eg_pub_module_permission( $cap ) {
	return function ( WP_REST_Request $r ) use ( $cap ) {
		$ok = eg_pub_permission( $r );
		return ( true === $ok && ! current_user_can( $cap ) ) ? false : $ok;
	};
}
