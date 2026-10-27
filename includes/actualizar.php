<?php
/**
 * Actualizar el plugin al momento desde Make, sin esperar a la actualización automática de WordPress
 * (que mira GitHub cada 6 horas y se aplica en el cron, unas dos veces al día):
 *   POST /wp-json/eg/v1/update
 * Está siempre activo, sea cual sea la tipología de la web. Lee la última release de GitHub sin caché y, si es
 * más nueva que la instalada, la instala con el actualizador de WordPress y deja el plugin activo.
 * dry_run: true solo dice la versión instalada y la última, sin instalar nada. Respeta «Mantener esta web en la versión».
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'eg/v1', '/update', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_update_handle',
		'permission_callback' => 'eg_pub_permission',
	) );
} );

function eg_pub_update_handle( WP_REST_Request $r ) {
	$release = eg_pub_gh_release( true );
	if ( ! $release ) {
		return eg_pub_error( 'eg_github', 'No se ha podido leer la última release de GitHub.', 502 );
	}
	$out = array(
		'ok'        => true,
		'site'      => home_url( '/' ),
		'installed' => EG_PUB_VERSION,
		'latest'    => $release['version'],
		'held'      => eg_pub_update_held(),
		'updated'   => false,
	);
	if ( ! version_compare( $release['version'], EG_PUB_VERSION, '>' ) ) {
		$out['message'] = 'Ya está en la última versión.';
		return new WP_REST_Response( $out );
	}
	if ( $out['held'] ) {
		$out['message'] = 'Esta web está marcada para mantenerse en la versión ' . EG_PUB_VERSION . ': no se actualiza.';
		return new WP_REST_Response( $out );
	}
	if ( ! empty( $r['dry_run'] ) ) {
		$out['message'] = 'Se instalaría la versión ' . $release['version'] . '.';
		return new WP_REST_Response( $out );
	}
	if ( ! current_user_can( 'update_plugins' ) ) {
		return eg_pub_error( 'eg_cap', 'El usuario del plugin no puede actualizar plugins (¿DISALLOW_FILE_MODS en wp-config?).', 403 );
	}

	require_once ABSPATH . 'wp-admin/includes/admin.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

	// Guardar el transient de actualizaciones: el filtro de updater.php le añade la release recién leída.
	$transient = get_site_transient( 'update_plugins' );
	set_site_transient( 'update_plugins', is_object( $transient ) ? $transient : new stdClass() );

	// Fuera del cron, WordPress desactiva el plugin antes de actualizarlo (sin sus hooks) y no lo reactiva:
	// se guardan los plugins activos para dejarlos igual después.
	$active  = get_option( 'active_plugins', array() );
	$network = is_multisite() ? get_site_option( 'active_sitewide_plugins', array() ) : null;

	$skin     = new WP_Ajax_Upgrader_Skin();
	$upgrader = new Plugin_Upgrader( $skin );
	$result   = $upgrader->upgrade( EG_PUB_BASENAME );

	if ( ! is_plugin_active( EG_PUB_BASENAME ) ) {
		update_option( 'active_plugins', $active );
		if ( null !== $network ) {
			update_site_option( 'active_sitewide_plugins', $network );
		}
	}

	if ( ! $result || is_wp_error( $result ) ) {
		$errors = is_wp_error( $result ) ? $result->get_error_messages() : $skin->get_error_messages();
		return eg_pub_error( 'eg_update', 'No se ha podido actualizar: ' . ( $errors ? implode( ' ', $errors ) : 'error desconocido.' ), 500 );
	}

	// En esta petición sigue cargado el código anterior: la versión instalada se lee del fichero nuevo.
	$data            = get_plugin_data( WP_PLUGIN_DIR . '/' . EG_PUB_BASENAME, false, false );
	$out['updated']  = true;
	$out['version']  = $data['Version'];
	$out['active']   = is_plugin_active( EG_PUB_BASENAME );
	$out['message']  = 'Actualizado de la ' . EG_PUB_VERSION . ' a la ' . $data['Version'] . '.';
	return new WP_REST_Response( $out );
}
