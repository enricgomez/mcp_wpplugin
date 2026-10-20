<?php
/**
 * Plugin Name:       EG Publicación
 * Description:       Endpoint /wp-json/eg/v1/publish que usa el escenario de Make de enricgomez Studio para crear o actualizar posts con idioma y traducción (WPML), categoría, SEO de Rank Math e imagen destacada. Opcional: landings de Elementor y redirecciones de Rank Math. La clave de acceso está en Ajustes → EG Publicación. Se actualiza solo desde GitHub.
 * Version:           2.5.0
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

const EG_PUB_VERSION = '2.5.0';
const EG_PUB_REPO    = 'enricgomez/mcp_wpplugin';
define( 'EG_PUB_BASENAME', plugin_basename( __FILE__ ) );

// La versión antigua instalada como mu-plugin se carga antes y declara las mismas funciones.
if ( function_exists( 'eg_pub_get_key' ) ) {
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-error"><p><strong>EG Publicación:</strong> sigue instalada la versión antigua en <code>wp-content/mu-plugins/eg-studio-rest-publisher.php</code>. Bórrala para que funcione este plugin (la clave y los ajustes se conservan).</p></div>';
	} );
	return;
}

require_once __DIR__ . '/includes/publisher.php';
require_once __DIR__ . '/includes/landings.php';
require_once __DIR__ . '/includes/updater.php';
