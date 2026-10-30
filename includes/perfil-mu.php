<?php
/**
 * Plugin Name: EG Publicación · perfil de rendimiento
 * Description: Lo instala /wp-json/eg/v1/perf de EG Publicación. No hace nada salvo en las peticiones que lanza ese endpoint (con un token de un solo uso que caduca en minutos): mide cuánto tarda en cargar cada plugin, cada función enganchada, las consultas SQL y las llamadas HTTP externas, y guarda el resultado.
 * Version: 1
 */

if ( ! defined( 'ABSPATH' ) || empty( $_GET['eg_profile'] ) || ! is_string( $_GET['eg_profile'] ) ) {
	return;
}

$eg_perf_token = get_option( 'eg_pub_perf_token' );
if ( ! is_array( $eg_perf_token ) || empty( $eg_perf_token['t'] ) || ( $eg_perf_token['exp'] ?? 0 ) < time()
	|| ! hash_equals( (string) $eg_perf_token['t'], substr( (string) $_GET['eg_profile'], 0, 64 ) ) ) {
	return;
}

if ( ! defined( 'SAVEQUERIES' ) ) {
	define( 'SAVEQUERIES', true );
}
if ( ! defined( 'DONOTCACHEPAGE' ) ) {
	define( 'DONOTCACHEPAGE', true );
}

final class EG_Pub_Perfil {
	public static $id      = '';
	public static $t0      = 0.0;
	public static $phases  = array();
	public static $plugins = array();
	public static $own     = array();   // dueño => ms (tiempo propio, sin las funciones anidadas)
	public static $cbs     = array();   // función => array( ms, llamadas, dueño )
	public static $http    = array();
	public static $stack   = array();   // tiempos de los hijos de cada nivel abierto
	public static $owners  = array();
	public static $last    = 0.0;
	public static $hooks   = 0;

	public static function now() {
		return hrtime( true ) / 1e6;
	}

	/** Plugin, tema o núcleo al que pertenece un fichero. */
	public static function owner_of_file( $file ) {
		$file = wp_normalize_path( (string) $file );
		if ( preg_match( '#/wp-content/plugins/([^/]+)#', $file, $m ) ) {
			return $m[1];
		}
		if ( preg_match( '#/wp-content/mu-plugins/([^/]+)#', $file, $m ) ) {
			return 'mu:' . preg_replace( '/\.php$/', '', $m[1] );
		}
		if ( preg_match( '#/wp-content/themes/([^/]+)#', $file, $m ) ) {
			return 'tema:' . $m[1];
		}
		return 'núcleo';
	}

	public static function describe( $fn ) {
		try {
			if ( is_string( $fn ) && false !== strpos( $fn, '::' ) ) {
				$fn = explode( '::', $fn, 2 );
			}
			if ( is_array( $fn ) ) {
				$class = is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0];
				$r     = new ReflectionMethod( $class, (string) $fn[1] );
				return array( $class . '::' . $fn[1], $r->getFileName() );
			}
			if ( $fn instanceof Closure ) {
				$r = new ReflectionFunction( $fn );
				return array( 'closure ' . basename( (string) $r->getFileName() ) . ':' . $r->getStartLine(), $r->getFileName() );
			}
			if ( is_object( $fn ) ) {
				$r = new ReflectionMethod( $fn, '__invoke' );
				return array( get_class( $fn ) . '::__invoke', $r->getFileName() );
			}
			if ( is_string( $fn ) && function_exists( $fn ) ) {
				$r = new ReflectionFunction( $fn );
				return array( $fn, $r->getFileName() );
			}
		} catch ( Throwable $e ) {
			// Sin reflexión posible: se queda como desconocido.
		}
		return array( is_string( $fn ) ? $fn : 'desconocida', '' );
	}

	/** Envuelve una vez las funciones del gancho que va a ejecutarse para medirlas. */
	public static function wrap_hook( $tag ) {
		global $wp_filter;
		if ( 'all' === $tag || empty( $wp_filter[ $tag ] ) || ! ( $wp_filter[ $tag ] instanceof WP_Hook ) ) {
			return;
		}
		self::$hooks++;
		foreach ( $wp_filter[ $tag ]->callbacks as $prio => $list ) {
			foreach ( $list as $idx => $cb ) {
				if ( $cb['function'] instanceof EG_Pub_Perfil_Cb ) {
					continue;
				}
				list( $name, $file ) = self::describe( $cb['function'] );
				$wp_filter[ $tag ]->callbacks[ $prio ][ $idx ]['function'] = new EG_Pub_Perfil_Cb( $cb['function'], $name, self::owner_of_file( $file ) );
			}
		}
	}

	public static function enter() {
		self::$stack[] = 0.0;
	}

	public static function leave( $inclusive, $name, $owner ) {
		$children = array_pop( self::$stack );
		if ( self::$stack ) {
			self::$stack[ count( self::$stack ) - 1 ] += $inclusive;
		}
		$self = max( 0.0, $inclusive - $children );
		self::$own[ $owner ] = ( self::$own[ $owner ] ?? 0 ) + $self;
		if ( ! isset( self::$cbs[ $name ] ) ) {
			self::$cbs[ $name ] = array( 0.0, 0, $owner );
		}
		self::$cbs[ $name ][0] += $self;
		self::$cbs[ $name ][1]++;
	}

	public static function phase( $name ) {
		self::$phases[ $name ] = round( self::now() - self::$t0, 1 );
	}

	public static function save() {
		global $wpdb;
		$total = self::now() - self::$t0;
		arsort( self::$own );
		uasort( self::$cbs, function ( $a, $b ) {
			return $b[0] <=> $a[0];
		} );
		arsort( self::$plugins );
		$sql_ms = 0.0;
		$slow   = array();
		foreach ( (array) $wpdb->queries as $q ) {
			$ms      = (float) $q[1] * 1000;
			$sql_ms += $ms;
			$slow[]  = array( round( $ms, 1 ), mb_substr( preg_replace( '/\s+/', ' ', (string) $q[0] ), 0, 220 ), mb_substr( (string) $q[2], -160 ) );
		}
		usort( $slow, function ( $a, $b ) {
			return $b[0] <=> $a[0];
		} );
		$cbs = array();
		foreach ( array_slice( self::$cbs, 0, 25, true ) as $name => $v ) {
			$cbs[] = array( 'ms' => round( $v[0], 1 ), 'calls' => $v[1], 'owner' => $v[2], 'fn' => $name );
		}
		foreach ( self::$http as $i => $h ) {
			unset( self::$http[ $i ]['start'] );
		}
		$tracked = array_sum( self::$own );
		$result  = array(
			'url'          => ( is_ssl() ? 'https://' : 'http://' ) . ( $_SERVER['HTTP_HOST'] ?? '' ) . strtok( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '?' ),
			'user'         => get_current_user_id(),
			'total_ms'     => round( $total, 1 ),
			'phases_ms'    => self::$phases,
			'plugin_load_ms' => array_map( function ( $v ) {
				return round( $v, 1 );
			}, array_slice( self::$plugins, 0, 30, true ) ),
			'hooks_ms'     => array_map( function ( $v ) {
				return round( $v, 1 );
			}, array_slice( self::$own, 0, 30, true ) ),
			'outside_hooks_ms' => round( max( 0, $total - $tracked - array_sum( self::$plugins ) ), 1 ),
			'callbacks'    => $cbs,
			'sql'          => array( 'count' => count( (array) $wpdb->queries ), 'ms' => round( $sql_ms, 1 ), 'slowest' => array_slice( $slow, 0, 12 ) ),
			'http'         => self::$http,
			'memory_mb'    => round( memory_get_peak_usage( true ) / 1048576, 1 ),
			'hooks_fired'  => self::$hooks,
		);
		update_option( 'eg_pub_perf_r_' . self::$id, $result, false );
	}
}

final class EG_Pub_Perfil_Cb {
	private $fn;
	private $name;
	private $owner;

	public function __construct( $fn, $name, $owner ) {
		$this->fn    = $fn;
		$this->name  = $name;
		$this->owner = $owner;
	}

	public function __invoke( ...$args ) {
		EG_Pub_Perfil::enter();
		$s = EG_Pub_Perfil::now();
		try {
			return call_user_func_array( $this->fn, $args );
		} finally {
			EG_Pub_Perfil::leave( EG_Pub_Perfil::now() - $s, $this->name, $this->owner );
		}
	}
}

EG_Pub_Perfil::$id   = preg_replace( '/[^a-z0-9]/', '', strtolower( (string) ( $_GET['eg_profile_id'] ?? 'x' ) ) );
EG_Pub_Perfil::$t0   = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? EG_Pub_Perfil::now() - ( microtime( true ) - (float) $_SERVER['REQUEST_TIME_FLOAT'] ) * 1000 : EG_Pub_Perfil::now();
EG_Pub_Perfil::phase( 'mu_plugin' );

add_action( 'muplugins_loaded', function () {
	EG_Pub_Perfil::phase( 'muplugins_loaded' );
	EG_Pub_Perfil::$last = EG_Pub_Perfil::now();
}, PHP_INT_MAX );
add_action( 'plugin_loaded', function ( $file ) {
	$now = EG_Pub_Perfil::now();
	$key = EG_Pub_Perfil::owner_of_file( $file );
	EG_Pub_Perfil::$plugins[ $key ] = ( EG_Pub_Perfil::$plugins[ $key ] ?? 0 ) + ( $now - EG_Pub_Perfil::$last );
	EG_Pub_Perfil::$last = $now;
}, PHP_INT_MIN );
add_action( 'all', array( 'EG_Pub_Perfil', 'wrap_hook' ) );
foreach ( array( 'plugins_loaded', 'setup_theme', 'after_setup_theme', 'init', 'wp_loaded', 'admin_init', 'parse_request', 'wp', 'template_redirect', 'wp_head', 'wp_footer', 'admin_footer', 'rest_api_init', 'shutdown' ) as $eg_perf_phase ) {
	add_action( $eg_perf_phase, function () use ( $eg_perf_phase ) {
		EG_Pub_Perfil::phase( $eg_perf_phase );
	}, PHP_INT_MIN );
}
add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	EG_Pub_Perfil::$http[] = array( 'url' => mb_substr( (string) $url, 0, 160 ), 'start' => EG_Pub_Perfil::now() );
	return $pre;
}, PHP_INT_MAX, 3 );
add_action( 'http_api_debug', function ( $response, $context, $class, $args, $url ) {
	for ( $i = count( EG_Pub_Perfil::$http ) - 1; $i >= 0; $i-- ) {
		if ( isset( EG_Pub_Perfil::$http[ $i ]['start'] ) && EG_Pub_Perfil::$http[ $i ]['url'] === mb_substr( (string) $url, 0, 160 ) ) {
			EG_Pub_Perfil::$http[ $i ]['ms'] = round( EG_Pub_Perfil::now() - EG_Pub_Perfil::$http[ $i ]['start'], 1 );
			EG_Pub_Perfil::$http[ $i ]['code'] = is_wp_error( $response ) ? $response->get_error_code() : wp_remote_retrieve_response_code( $response );
			unset( EG_Pub_Perfil::$http[ $i ]['start'] );
			break;
		}
	}
}, 10, 5 );
register_shutdown_function( array( 'EG_Pub_Perfil', 'save' ) );
