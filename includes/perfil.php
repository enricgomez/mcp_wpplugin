<?php
/**
 * Tipología «Gestión de la web»: perfil de rendimiento del servidor (qué plugin se come el tiempo de cada página).
 *   POST /wp-json/eg/v1/perf
 *   urls        ["/", "/producto/criar-juntos/", "/carrito/"]  páginas públicas, como un visitante sin sesión (máx. 10)
 *   admin_urls  ["/wp-admin/", "/wp-admin/edit.php?post_type=product"]  pantallas del admin, con la sesión del usuario
 *               de EG Publicación (una sesión temporal que se cierra al acabar)
 *   wait        false lanza las peticiones sin esperarlas (para páginas que tardan más de un minuto); los
 *               resultados se recogen después con results
 *   results     true devuelve (y borra) los perfiles guardados que nadie ha recogido todavía
 *   uninstall   true quita el mu-plugin de perfil y sus opciones
 * La primera llamada instala el mu-plugin `eg-pub-perfil.php` (copia de includes/perfil-mu.php), que no hace nada
 * salvo en las peticiones que lanza este endpoint con un token de un solo uso que caduca a los 5 minutos. Cada página
 * se pide al propio servidor saltándose la caché de WP Rocket y devuelve: tiempo total, momento de cada fase de
 * WordPress, lo que tarda en cargar cada plugin, el tiempo propio de las funciones enganchadas agrupado por plugin,
 * las 25 funciones que más tardan, consultas SQL (número, tiempo y las más lentas), llamadas HTTP externas y memoria.
 * No cambia nada de la web aparte del mu-plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_PERF_MU = 'eg-pub-perfil.php';

add_filter( 'eg_pub_modules', function ( $modules ) {
	$modules['perfil'] = array( 'type' => 'gestion', 'routes' => array( 'perf' ) );
	return $modules;
} );

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_module_enabled( 'perfil' ) ) {
		return;
	}
	register_rest_route( 'eg/v1', '/perf', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_perf_handle',
		'permission_callback' => eg_pub_module_permission( 'manage_options' ),
	) );
} );

/** Copia el mu-plugin si falta o es de otra versión. Devuelve true si está listo. */
function eg_pub_perf_install() {
	$src = __DIR__ . '/perfil-mu.php';
	$dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
	$dst = trailingslashit( $dir ) . EG_PUB_PERF_MU;
	if ( is_file( $dst ) && md5_file( $dst ) === md5_file( $src ) ) {
		return true;
	}
	if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
		return false;
	}
	return (bool) @copy( $src, $dst );
}

function eg_pub_perf_uninstall() {
	$dir = defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
	$dst = trailingslashit( $dir ) . EG_PUB_PERF_MU;
	$ok  = ! is_file( $dst ) || @unlink( $dst );
	delete_option( 'eg_pub_perf_token' );
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'eg\\_pub\\_perf\\_r\\_%'" );
	return $ok;
}

/** Ruta o URL de la propia web a URL completa; null si es de otro dominio. */
function eg_pub_perf_url( $u ) {
	$u = trim( (string) $u );
	if ( '' === $u ) {
		return null;
	}
	if ( '/' === $u[0] ) {
		return home_url( $u );
	}
	$host = wp_parse_url( $u, PHP_URL_HOST );
	return ( $host && strtolower( $host ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) ? esc_url_raw( $u ) : null;
}

/** Junta carga del plugin y tiempo de sus funciones para dar un ranking corto. */
function eg_pub_perf_top( $r ) {
	$sum = array();
	foreach ( array( 'plugin_load_ms', 'hooks_ms' ) as $k ) {
		foreach ( (array) ( $r[ $k ] ?? array() ) as $owner => $ms ) {
			$sum[ $owner ] = ( $sum[ $owner ] ?? 0 ) + $ms;
		}
	}
	arsort( $sum );
	return array_map( function ( $v ) {
		return round( $v );
	}, array_slice( $sum, 0, 12, true ) );
}

function eg_pub_perf_handle( WP_REST_Request $request ) {
	$p = (array) $request->get_json_params();
	if ( ! empty( $p['uninstall'] ) ) {
		return new WP_REST_Response( array( 'ok' => eg_pub_perf_uninstall(), 'uninstalled' => true ), 200 );
	}
	if ( ! empty( $p['results'] ) ) {
		global $wpdb;
		$names = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'eg\\_pub\\_perf\\_r\\_%'" );
		$pages = array();
		foreach ( $names as $name ) {
			wp_cache_delete( $name, 'options' );
			$data = get_option( $name );
			if ( is_array( $data ) ) {
				$pages[] = array( 'url' => $data['url'] ?? '', 'top_ms' => eg_pub_perf_top( $data ), 'profile' => $data );
			}
			delete_option( $name );
		}
		return new WP_REST_Response( array( 'ok' => true, 'pages' => $pages ), 200 );
	}
	$wait = ! array_key_exists( 'wait', $p ) || ! empty( $p['wait'] );
	$jobs = array();
	foreach ( array( 'urls' => false, 'admin_urls' => true ) as $key => $admin ) {
		foreach ( array_slice( (array) ( $p[ $key ] ?? array() ), 0, 10 ) as $u ) {
			$full = eg_pub_perf_url( $u );
			if ( $full ) {
				$jobs[] = array( $full, $admin );
			}
		}
	}
	if ( ! $jobs ) {
		return eg_pub_error( 'eg_perf_urls', 'Indica urls o admin_urls de esta web.' );
	}
	if ( ! eg_pub_perf_install() ) {
		return eg_pub_error( 'eg_perf_mu', 'No se ha podido copiar el mu-plugin de perfil en wp-content/mu-plugins (permisos).', 500 );
	}
	$token = wp_generate_password( 32, false, false );
	update_option( 'eg_pub_perf_token', array( 't' => $token, 'exp' => time() + ( $wait ? 300 : 600 ) ), false );

	$cookies = array();
	$session = '';
	$uid     = get_current_user_id();
	if ( $uid && in_array( true, array_column( $jobs, 1 ), true ) ) {
		$exp     = time() + 600;
		$manager = WP_Session_Tokens::get_instance( $uid );
		$session = $manager->create( $exp );
		$cookies = array(
			SECURE_AUTH_COOKIE => wp_generate_auth_cookie( $uid, $exp, 'secure_auth', $session ),
			AUTH_COOKIE        => wp_generate_auth_cookie( $uid, $exp, 'auth', $session ),
			LOGGED_IN_COOKIE   => wp_generate_auth_cookie( $uid, $exp, 'logged_in', $session ),
		);
	}

	$pages = array();
	foreach ( $jobs as $job ) {
		list( $url, $admin ) = $job;
		$id    = strtolower( wp_generate_password( 12, false, false ) );
		$req   = add_query_arg( array( 'eg_profile' => $token, 'eg_profile_id' => $id, 'nc' => wp_rand() ), $url );
		$start = microtime( true );
		$res   = wp_remote_get( $req, array(
			'timeout'     => $wait ? 60 : 1,
			'blocking'    => $wait,
			'redirection' => 0,
			'cookies'     => $admin ? $cookies : array(),
			'headers'     => array( 'User-Agent' => 'Mozilla/5.0 EG-Publicacion-perfil/' . EG_PUB_VERSION ),
		) );
		$wall = round( microtime( true ) - $start, 2 );
		if ( ! $wait ) {
			$pages[] = array( 'url' => $url, 'admin' => $admin, 'id' => $id, 'launched' => true );
			continue;
		}
		$data = null;
		for ( $i = 0; $i < 20 && ! $data; $i++ ) {
			wp_cache_delete( 'eg_pub_perf_r_' . $id, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			$data = get_option( 'eg_pub_perf_r_' . $id );
			if ( ! $data ) {
				usleep( 250000 );
			}
		}
		delete_option( 'eg_pub_perf_r_' . $id );
		$page = array(
			'url'     => $url,
			'admin'   => $admin,
			'status'  => is_wp_error( $res ) ? $res->get_error_message() : wp_remote_retrieve_response_code( $res ),
			'seconds' => $wall,
		);
		if ( $data ) {
			$page['top_ms']  = eg_pub_perf_top( $data );
			$page['profile'] = $data;
		} else {
			$page['profile'] = null;
		}
		$pages[] = $page;
	}

	if ( $wait ) {
		delete_option( 'eg_pub_perf_token' );
	}
	// Sin esperar, la sesión temporal del admin caduca sola a los 10 minutos.
	if ( $session && $wait ) {
		WP_Session_Tokens::get_instance( $uid )->destroy( $session );
	}
	return new WP_REST_Response( array( 'ok' => true, 'pages' => $pages ), 200 );
}
