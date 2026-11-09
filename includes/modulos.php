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
const EG_PUB_OPTION_TYPES   = 'eg_pub_types'; // Hasta la 2.91.x: tipologías marcadas. Se sigue escribiendo por si se vuelve atrás.
const EG_PUB_OPTION_TYPES_OFF = 'eg_pub_types_off'; // Desde la 2.92.0: tipologías apagadas a mano en esta web.

/** Tipologías: slug => array( label, description, requires ). requires = constante del plugin que hace falta. */
function eg_pub_types() {
	return array(
		'blog'        => array(
			'label'       => 'Blog',
			'description' => 'Publica y actualiza posts desde Make (Notion), con idiomas de WPML, categoría, SEO e imágenes, y avisa por email al publicarse.',
		),
		'elementor'   => array(
			'label'       => 'Elementor',
			'description' => 'Landings a partir de una página maestra, plantillas reutilizables al pie de las páginas, estilos globales del kit, fuentes propias e imágenes.',
			'requires'    => 'ELEMENTOR_VERSION',
		),
		'woocommerce' => array(
			'label'       => 'WooCommerce',
			'description' => 'Productos en todos sus idiomas: textos, campos de JetEngine, SEO y categorías.',
			'requires'    => 'WC_VERSION',
		),
		'gestion'     => array(
			'label'       => 'Gestión de la web',
			'description' => 'Buscar y sustituir textos, menús, redirecciones de Rank Math, textos y SEO de categorías, textos de los formularios de Forminator, ajustes de WP Rocket, publicar o pasar a borrador páginas y entradas, y poner el enlace de cada idioma (p. ej. el motor de reservas) en las traducciones.',
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

/**
 * Tipologías activas en esta web. Desde la 2.92.0 están activas siempre todas las que la web puede usar (Blog y
 * Gestión siempre, Elementor y WooCommerce si esos plugins están activos), salvo las que se apaguen a mano en
 * Ajustes → EG Publicación. Las listas de tipologías marcadas que guardaban las versiones anteriores ya no
 * cuentan: al actualizar, cada web pasa a tener todo lo que puede usar sin ir web a web.
 */
function eg_pub_active_types() {
	$off = get_option( EG_PUB_OPTION_TYPES_OFF, array() );
	return array_values( array_diff( eg_pub_available_types(), is_array( $off ) ? $off : array() ) );
}

/** Tipologías cuyos requisitos cumple la web (p. ej. Elementor solo si Elementor está activo). */
function eg_pub_available_types() {
	return array_keys( array_filter( eg_pub_types(), function ( $t ) {
		return empty( $t['requires'] ) || defined( $t['requires'] );
	} ) );
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
	// Solo se guarda como apagado lo que la web podría usar y se ha desmarcado: si Elementor o WooCommerce se
	// activan más tarde, su tipología se enciende sola.
	update_option( EG_PUB_OPTION_TYPES_OFF, array_values( array_diff( eg_pub_available_types(), $types ) ), false );
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
