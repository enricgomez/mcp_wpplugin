<?php
/**
 * Actualizaciones desde las releases de GitHub (enricgomez/mcp_wpplugin).
 *
 * Cada release lleva adjunto eg-publicacion.zip (lo genera la GitHub Action al
 * subir a main una versión nueva). Si el repositorio es privado, definir en wp-config.php:
 *   define( 'EG_PUB_GITHUB_TOKEN', 'github_pat_...' );  // permiso de solo lectura de Contents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_SLUG          = 'eg-publicacion';
const EG_PUB_RELEASE_CACHE = 'eg_pub_gh_release';
const EG_PUB_OPTION_HOLD   = 'eg_pub_hold_version';

/**
 * Mantener esta web en la versión instalada: se marca en Ajustes → EG Publicación,
 * o en wp-config.php con define( 'EG_PUB_HOLD_VERSION', true ).
 */
function eg_pub_update_held() {
	if ( defined( 'EG_PUB_HOLD_VERSION' ) ) {
		return (bool) EG_PUB_HOLD_VERSION;
	}
	return (bool) get_option( EG_PUB_OPTION_HOLD, false );
}

function eg_pub_gh_token() {
	return defined( 'EG_PUB_GITHUB_TOKEN' ) ? (string) EG_PUB_GITHUB_TOKEN : '';
}

/**
 * Última release publicada: array( version, package, url, notes, published ) o null.
 */
function eg_pub_gh_release( $force = false ) {
	$cached = get_site_transient( EG_PUB_RELEASE_CACHE );
	if ( ! $force && is_array( $cached ) ) {
		return $cached['version'] ? $cached : null;
	}

	$res = wp_remote_get( 'https://api.github.com/repos/' . EG_PUB_REPO . '/releases/latest', array(
		'timeout' => 10,
		'headers' => array( 'Accept' => 'application/vnd.github+json' ),
	) );
	$data = ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) )
		? json_decode( wp_remote_retrieve_body( $res ), true )
		: null;

	$release = array( 'version' => '' );
	if ( is_array( $data ) && ! empty( $data['tag_name'] ) ) {
		$package = '';
		foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
			if ( EG_PUB_SLUG . '.zip' === ( $asset['name'] ?? '' ) ) {
				// Con token (repo privado) hay que descargar por la API.
				$package = eg_pub_gh_token() ? $asset['url'] : $asset['browser_download_url'];
				break;
			}
		}
		$release = array(
			'version'   => ltrim( (string) $data['tag_name'], 'vV' ),
			'package'   => $package ? $package : (string) $data['zipball_url'],
			'url'       => (string) $data['html_url'],
			'notes'     => (string) ( $data['body'] ?? '' ),
			'published' => (string) ( $data['published_at'] ?? '' ),
		);
	}
	// Si GitHub falla, no volver a preguntar hasta dentro de una hora.
	set_site_transient( EG_PUB_RELEASE_CACHE, $release, $release['version'] ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
	return $release['version'] ? $release : null;
}

// Cabeceras para las peticiones a GitHub (token si el repo es privado).
add_filter( 'http_request_args', function ( $args, $url ) {
	$api = 'https://api.github.com/repos/' . EG_PUB_REPO . '/';
	if ( 0 !== strpos( $url, $api ) ) {
		return $args;
	}
	$args['headers'] = isset( $args['headers'] ) ? (array) $args['headers'] : array();
	$args['headers']['User-Agent'] = 'EG-Publicacion/' . EG_PUB_VERSION . '; ' . home_url( '/' );
	if ( eg_pub_gh_token() ) {
		$args['headers']['Authorization'] = 'Bearer ' . eg_pub_gh_token();
	}
	if ( false !== strpos( $url, '/releases/assets/' ) ) {
		$args['headers']['Accept'] = 'application/octet-stream';
	}
	return $args;
}, 10, 2 );

// Ofrecer la actualización a WordPress.
add_filter( 'pre_set_site_transient_update_plugins', function ( $transient ) {
	if ( ! is_object( $transient ) ) {
		return $transient;
	}
	$release = eg_pub_gh_release();
	if ( ! $release ) {
		return $transient;
	}
	$item = (object) array(
		'id'          => 'github.com/' . EG_PUB_REPO,
		'slug'        => EG_PUB_SLUG,
		'plugin'      => EG_PUB_BASENAME,
		'new_version' => $release['version'],
		'url'         => $release['url'],
		'package'     => $release['package'],
	);
	if ( ! eg_pub_update_held() && version_compare( $release['version'], EG_PUB_VERSION, '>' ) ) {
		$transient->response[ EG_PUB_BASENAME ] = $item;
		unset( $transient->no_update[ EG_PUB_BASENAME ] );
	} else {
		$transient->no_update[ EG_PUB_BASENAME ] = $item;
		unset( $transient->response[ EG_PUB_BASENAME ] );
	}
	return $transient;
} );

// Ventana "Ver detalles" en Plugins.
add_filter( 'plugins_api', function ( $result, $action, $args ) {
	if ( 'plugin_information' !== $action || EG_PUB_SLUG !== ( $args->slug ?? '' ) ) {
		return $result;
	}
	$release = eg_pub_gh_release();
	return (object) array(
		'name'          => 'EG Publicación',
		'slug'          => EG_PUB_SLUG,
		'version'       => $release ? $release['version'] : EG_PUB_VERSION,
		'author'        => '<a href="https://www.enricgomez.com">enricgomez Studio</a>',
		'homepage'      => 'https://github.com/' . EG_PUB_REPO,
		'last_updated'  => $release ? $release['published'] : '',
		'download_link' => $release ? $release['package'] : '',
		'sections'      => array(
			'description' => 'Publicación de posts desde Make (Notion → WordPress) para las webs de clientes de enricgomez Studio.',
			'changelog'   => $release && $release['notes'] ? wpautop( esc_html( $release['notes'] ) ) : 'Sin notas.',
		),
	);
}, 10, 3 );

// El zip automático de GitHub trae una carpeta "enricgomez-eg-publicacion-<hash>": renombrarla.
add_filter( 'upgrader_source_selection', function ( $source, $remote_source, $upgrader, $hook_extra = array() ) {
	global $wp_filesystem;
	if ( ( $hook_extra['plugin'] ?? '' ) !== EG_PUB_BASENAME ) {
		return $source;
	}
	$wanted = trailingslashit( $remote_source ) . EG_PUB_SLUG . '/';
	if ( trailingslashit( $source ) === $wanted ) {
		return $source;
	}
	return $wp_filesystem->move( $source, $wanted, true ) ? $wanted : $source;
}, 10, 4 );

// Actualizar solo, sin tener que pulsar el botón en cada web.
add_filter( 'auto_update_plugin', function ( $update, $item ) {
	return ( isset( $item->plugin ) && EG_PUB_BASENAME === $item->plugin ) ? ! eg_pub_update_held() : $update;
}, 10, 2 );

// Tras actualizar, olvidar la release cacheada.
add_action( 'upgrader_process_complete', function () {
	delete_site_transient( EG_PUB_RELEASE_CACHE );
} );

// Enlaces "Ajustes" (el primero) y "Buscar actualizaciones" en la fila del plugin.
add_filter( 'plugin_action_links_' . EG_PUB_BASENAME, function ( $links ) {
	if ( current_user_can( 'manage_options' ) ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=eg-publicacion' ) ) . '">Ajustes</a>' );
	}
	$url = wp_nonce_url( admin_url( 'plugins.php?eg_pub_check=1' ), 'eg_pub_check' );
	$links[] = '<a href="' . esc_url( $url ) . '">Buscar actualizaciones</a>';
	return $links;
} );

add_action( 'admin_init', function () {
	if ( empty( $_GET['eg_pub_check'] ) || ! current_user_can( 'update_plugins' ) ) {
		return;
	}
	check_admin_referer( 'eg_pub_check' );
	delete_site_transient( EG_PUB_RELEASE_CACHE );
	delete_site_transient( 'update_plugins' );
	wp_update_plugins();
	wp_safe_redirect( admin_url( 'plugins.php' ) );
	exit;
} );
