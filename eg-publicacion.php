<?php
/**
 * Plugin Name:       EG Publicación
 * Description:       Endpoint /wp-json/eg/v1/publish que usa el escenario de Make de enricgomez Studio para crear o actualizar posts con idioma y traducción (WPML), categoría, SEO de Rank Math e imagen destacada. Opcional: landings de Elementor y redirecciones de Rank Math. La clave de acceso está en Ajustes → EG Publicación. Se actualiza solo desde GitHub.
 * Version:           2.5.4
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            enricgomez Studio
 * Author URI:        https://www.enricgomez.com
 * Update URI:        https://github.com/enricgomez/mcp_wpplugin
 * Text Domain:       eg-publicacion
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_VERSION = '2.5.4';
const EG_PUB_REPO    = 'enricgomez/mcp_wpplugin';
define( 'EG_PUB_BASENAME', plugin_basename( __FILE__ ) );

// Otra copia ya cargada: la versión antigua como mu-plugin o este plugin instalado dos veces.
if ( function_exists( 'eg_pub_get_key' ) ) {
	$eg_pub_other = ( new ReflectionFunction( 'eg_pub_get_key' ) )->getFileName();
	$eg_pub_other = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( (string) $eg_pub_other ) );
	add_action( 'admin_notices', function () use ( $eg_pub_other ) {
		$is_mu = false !== strpos( $eg_pub_other, 'mu-plugins/' );
		echo '<div class="notice notice-error"><p><strong>EG Publicación:</strong> ya hay otra copia cargada en <code>' . esc_html( $eg_pub_other ) . '</code>. '
			. ( $is_mu
				? 'Es la versión antigua: bórrala para que funcione este plugin (la clave y los ajustes se conservan).'
				: 'El plugin está instalado dos veces: desactiva y borra una de las dos copias en Plugins (la clave y los ajustes se conservan).' )
			. '</p></div>';
	} );
	return;
}

require_once __DIR__ . '/includes/publisher.php';
require_once __DIR__ . '/includes/landings.php';
require_once __DIR__ . '/includes/textos.php';
require_once __DIR__ . '/includes/updater.php';
