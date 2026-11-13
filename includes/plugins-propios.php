<?php
/**
 * Plugins propios de enricgomez Studio que se instalan desde EG Publicación (el servidor solo deja instalar por
 * WP-CLI plugins del repositorio oficial):
 *   POST /wp-json/eg/v1/plugins
 * Está siempre activo, sea cual sea la tipología de la web.
 *   (sin parámetros)        lista los plugins propios: instalado, versión, activo y última release.
 *   install: "eg-cookies"   instala la última release (o la reinstala encima si ya está) y la activa.
 *   activate: false         con install, lo deja instalado pero sin activar.
 *   deactivate: "eg-cookies" lo desactiva (no lo borra).
 * Solo se pueden instalar los plugins de la lista (filtro eg_pub_own_plugins), siempre desde sus releases de GitHub.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function eg_pub_own_plugins() {
	return apply_filters( 'eg_pub_own_plugins', array(
		'eg-cookies' => array(
			'name' => 'EG Cookies',
			'repo' => 'enricgomez/eg-cookies',
			'file' => 'eg-cookies/eg-cookies.php',
			'zip'  => 'eg-cookies.zip',
		),
	) );
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'eg/v1', '/plugins', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_plugins_handle',
		'permission_callback' => 'eg_pub_permission',
	) );
} );

/** Estado de un plugin propio. $latest: consulta la última release en GitHub. */
function eg_pub_plugin_status( $slug, $p, $latest = true ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$path = WP_PLUGIN_DIR . '/' . $p['file'];
	$data = file_exists( $path ) ? get_plugin_data( $path, false, false ) : null;
	$out  = array(
		'slug'      => $slug,
		'name'      => $p['name'],
		'installed' => (bool) $data,
		'version'   => $data ? $data['Version'] : null,
		'active'    => $data ? is_plugin_active( $p['file'] ) : false,
	);
	if ( $latest ) {
		$res = wp_remote_get( 'https://api.github.com/repos/' . $p['repo'] . '/releases/latest', array(
			'timeout' => 10,
			'headers' => array( 'Accept' => 'application/vnd.github+json', 'User-Agent' => 'EG-Publicacion/' . EG_PUB_VERSION ),
		) );
		$body          = ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) ? json_decode( wp_remote_retrieve_body( $res ), true ) : null;
		$out['latest'] = is_array( $body ) && ! empty( $body['tag_name'] ) ? ltrim( (string) $body['tag_name'], 'vV' ) : null;
	}
	return $out;
}

function eg_pub_plugins_handle( WP_REST_Request $r ) {
	$list = eg_pub_own_plugins();

	if ( ! empty( $r['deactivate'] ) ) {
		$slug = sanitize_key( (string) $r['deactivate'] );
		if ( ! isset( $list[ $slug ] ) ) {
			return eg_pub_error( 'eg_plugin', 'Plugin desconocido: ' . $slug . '.', 404 );
		}
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return eg_pub_error( 'eg_cap', 'El usuario del plugin no puede desactivar plugins.', 403 );
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		deactivate_plugins( $list[ $slug ]['file'] );
		return new WP_REST_Response( array( 'ok' => true, 'plugin' => eg_pub_plugin_status( $slug, $list[ $slug ], false ) ) );
	}

	if ( empty( $r['install'] ) ) {
		$out = array();
		foreach ( $list as $slug => $p ) {
			$out[] = eg_pub_plugin_status( $slug, $p );
		}
		return new WP_REST_Response( array( 'ok' => true, 'plugins' => $out ) );
	}

	$slug = sanitize_key( (string) $r['install'] );
	if ( ! isset( $list[ $slug ] ) ) {
		return eg_pub_error( 'eg_plugin', 'Plugin desconocido: ' . $slug . '. Se pueden instalar: ' . implode( ', ', array_keys( $list ) ) . '.', 404 );
	}
	if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'activate_plugins' ) ) {
		return eg_pub_error( 'eg_cap', 'El usuario del plugin no puede instalar plugins (¿DISALLOW_FILE_MODS en wp-config?).', 403 );
	}
	$p       = $list[ $slug ];
	$before  = eg_pub_plugin_status( $slug, $p, false );
	$package = 'https://github.com/' . $p['repo'] . '/releases/latest/download/' . $p['zip'];

	require_once ABSPATH . 'wp-admin/includes/admin.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	$skin     = new WP_Ajax_Upgrader_Skin();
	$upgrader = new Plugin_Upgrader( $skin );
	$result   = $upgrader->install( $package, array( 'overwrite_package' => true ) );
	if ( ! $result || is_wp_error( $result ) ) {
		$errors = is_wp_error( $result ) ? $result->get_error_messages() : $skin->get_error_messages();
		return eg_pub_error( 'eg_install', 'No se ha podido instalar ' . $p['name'] . ': ' . ( $errors ? implode( ' ', $errors ) : 'error desconocido.' ), 500 );
	}
	wp_clean_plugins_cache();

	$activated = null;
	if ( ! isset( $r['activate'] ) || rest_sanitize_boolean( $r['activate'] ) ) {
		$act       = activate_plugin( $p['file'] );
		$activated = ! is_wp_error( $act );
		if ( is_wp_error( $act ) ) {
			return eg_pub_error( 'eg_activate', 'Instalado, pero no se ha podido activar: ' . $act->get_error_message(), 500 );
		}
	}
	return new WP_REST_Response( array(
		'ok'        => true,
		'before'    => $before,
		'plugin'    => eg_pub_plugin_status( $slug, $p, false ),
		'activated' => $activated,
		'message'   => ( $before['installed'] ? 'Reinstalado ' : 'Instalado ' ) . $p['name'] . '.',
	) );
}
