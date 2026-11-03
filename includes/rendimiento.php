<?php
/**
 * Tipología «Gestión de la web»: ajustes de WP Rocket que no se pueden tocar sin entrar en wp-admin.
 *   POST /wp-json/eg/v1/rocket
 * Sin parámetros devuelve los ajustes que gestiona este endpoint y si la licencia parece oficial.
 *   add / remove   {"exclude_lazyload": ["montaje-cookies"], "cache_reject_uri": ["/resumen-de-compra/(.*)"]}
 *                  añade o quita valores de las listas (sin duplicar ni tocar el resto de la lista)
 *   set            {"async_css": 0, "lazyload_iframes": 1}  interruptores (0/1)
 *   elementor      {"css_print_method": "internal", "font_icon_svg": "active"}  ajustes de rendimiento de Elementor
 *                  (ver EG_PUB_ELEMENTOR_PERF); al guardar se rehace el CSS de Elementor
 *   clear          true vacía la caché de WP Rocket como su botón «Vaciar caché» y purga toda la caché de
 *                  Cloudflare con los datos del complemento de WP Rocket (si está activo y configurado)
 * Como /seo y /term, por defecto solo previsualiza; apply:true guarda y vacía la caché de WP Rocket.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_ROCKET_LISTS    = array( 'exclude_lazyload', 'cache_reject_uri', 'cache_reject_cookies', 'exclude_css', 'exclude_js', 'exclude_defer_js', 'delay_js_exclusions' );
const EG_PUB_ROCKET_SWITCHES = array( 'lazyload', 'lazyload_iframes', 'lazyload_css_bg_img', 'remove_unused_css', 'async_css', 'delay_js', 'defer_all_js', 'minify_css', 'minify_js', 'image_dimensions', 'cache_mobile', 'do_caching_mobile_files' );

// Ajustes de rendimiento de Elementor: clave de /rocket => array( opción de WordPress, valores admitidos ).
const EG_PUB_ELEMENTOR_PERF = array(
	'css_print_method'      => array( 'elementor_css_print_method', array( 'external', 'internal' ) ),
	'font_icon_svg'         => array( 'elementor_experiment-e_font_icon_svg', array( 'active', 'inactive', 'default' ) ),
	'optimized_css_loading' => array( 'elementor_experiment-e_optimized_css_loading', array( 'active', 'inactive', 'default' ) ),
	'fa4_shim'              => array( 'elementor_load_fa4_shim', array( 'yes', '' ) ),
	'font_display'          => array( 'elementor_font_display', array( 'auto', 'block', 'swap', 'fallback', 'optional' ) ),
);

add_filter( 'eg_pub_modules', function ( $modules ) {
	$modules['rendimiento'] = array( 'type' => 'gestion', 'routes' => array( 'rocket' ) );
	return $modules;
} );

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_module_enabled( 'rendimiento' ) ) {
		return;
	}
	register_rest_route( 'eg/v1', '/rocket', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_rocket_handle',
		'permission_callback' => eg_pub_module_permission( 'manage_options' ),
	) );
} );

/** Solo los ajustes que gestiona /rocket, más la cuenta de la licencia (sin las claves). */
function eg_pub_rocket_view( $opts ) {
	$view = array();
	foreach ( array_merge( EG_PUB_ROCKET_LISTS, EG_PUB_ROCKET_SWITCHES ) as $key ) {
		if ( array_key_exists( $key, $opts ) ) {
			$view[ $key ] = $opts[ $key ];
		}
	}
	$email           = (string) ( $opts['consumer_email'] ?? '' );
	$view['license'] = array(
		'email' => $email,
		// Las copias GPL suelen llevar un email inventado: con ellas no funcionan los servicios de WP Rocket (CSS no usado, CSS crítico).
		'likely_official' => '' !== $email && ! preg_match( '/^(noreply|no-reply|test|admin)@/i', $email ),
	);
	return $view;
}

/**
 * Purga toda la caché de Cloudflare con los datos del complemento de WP Rocket (email, clave global y zona).
 * Por la API REST el complemento no purga solo; devuelve null si no está configurado.
 */
function eg_pub_rocket_purge_cloudflare( $opts ) {
	$email = (string) ( $opts['cloudflare_email'] ?? '' );
	$key   = defined( 'WP_ROCKET_CF_API_KEY' ) ? (string) WP_ROCKET_CF_API_KEY : (string) ( $opts['cloudflare_api_key'] ?? '' );
	$zone  = (string) ( $opts['cloudflare_zone_id'] ?? '' );
	if ( empty( $opts['do_cloudflare'] ) || '' === $key || '' === $zone ) {
		return null;
	}
	$headers = array( 'Content-Type' => 'application/json' );
	if ( '' !== $email ) {
		$headers['X-Auth-Email'] = $email;
		$headers['X-Auth-Key']   = $key;
	} else {
		$headers['Authorization'] = 'Bearer ' . $key;
	}
	$res = wp_remote_post( 'https://api.cloudflare.com/client/v4/zones/' . rawurlencode( $zone ) . '/purge_cache', array(
		'timeout' => 20,
		'headers' => $headers,
		'body'    => wp_json_encode( array( 'purge_everything' => true ) ),
	) );
	if ( is_wp_error( $res ) ) {
		return array( 'ok' => false, 'error' => $res->get_error_message() );
	}
	$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
	return array(
		'ok'     => ! empty( $body['success'] ),
		'errors' => array_map( fn( $e ) => $e['message'] ?? '', (array) ( $body['errors'] ?? array() ) ),
	);
}

function eg_pub_rocket_handle( WP_REST_Request $r ) {
	if ( ! defined( 'WP_ROCKET_VERSION' ) ) {
		return eg_pub_error( 'eg_rocket', 'WP Rocket no está activo en esta web.' );
	}
	$p     = $r->get_params();
	$apply = ! empty( $p['apply'] );
	$opts  = get_option( 'wp_rocket_settings', array() );
	$opts  = is_array( $opts ) ? $opts : array();
	$new   = $opts;

	foreach ( array( 'add', 'remove' ) as $op ) {
		foreach ( (array) ( $p[ $op ] ?? array() ) as $key => $values ) {
			if ( ! in_array( $key, EG_PUB_ROCKET_LISTS, true ) ) {
				return eg_pub_error( 'eg_rocket_key', 'Lista no admitida: ' . $key . '. Admitidas: ' . implode( ', ', EG_PUB_ROCKET_LISTS ) . '.' );
			}
			$values = array_values( array_filter( array_map( function ( $v ) {
				return is_scalar( $v ) ? trim( wp_strip_all_tags( (string) $v ) ) : '';
			}, (array) $values ) ) );
			$list   = array_values( (array) ( $new[ $key ] ?? array() ) );
			$list   = 'add' === $op ? array_values( array_unique( array_merge( $list, $values ) ) ) : array_values( array_diff( $list, $values ) );
			$new[ $key ] = $list;
		}
	}
	foreach ( (array) ( $p['set'] ?? array() ) as $key => $value ) {
		if ( ! in_array( $key, EG_PUB_ROCKET_SWITCHES, true ) ) {
			return eg_pub_error( 'eg_rocket_key', 'Interruptor no admitido: ' . $key . '. Admitidos: ' . implode( ', ', EG_PUB_ROCKET_SWITCHES ) . '.' );
		}
		$new[ $key ] = $value ? 1 : 0;
	}

	$elementor = array();
	foreach ( (array) ( $p['elementor'] ?? array() ) as $key => $value ) {
		if ( ! isset( EG_PUB_ELEMENTOR_PERF[ $key ] ) ) {
			return eg_pub_error( 'eg_rocket_key', 'Ajuste de Elementor no admitido: ' . $key . '. Admitidos: ' . implode( ', ', array_keys( EG_PUB_ELEMENTOR_PERF ) ) . '.' );
		}
		list( , $allowed ) = EG_PUB_ELEMENTOR_PERF[ $key ];
		if ( ! in_array( (string) $value, $allowed, true ) ) {
			return eg_pub_error( 'eg_rocket_value', 'Valor no admitido para ' . $key . '. Admitidos: ' . implode( ', ', array_map( fn( $v ) => '' === $v ? '""' : $v, $allowed ) ) . '.' );
		}
		$elementor[ $key ] = (string) $value;
	}

	$changes = array();
	foreach ( $elementor as $key => $value ) {
		$before = (string) get_option( EG_PUB_ELEMENTOR_PERF[ $key ][0], '' );
		if ( $before !== $value ) {
			$changes[ 'elementor.' . $key ] = array( 'before' => $before, 'after' => $value );
		}
	}
	foreach ( array_merge( EG_PUB_ROCKET_LISTS, EG_PUB_ROCKET_SWITCHES ) as $key ) {
		$before = $opts[ $key ] ?? null;
		$after  = $new[ $key ] ?? null;
		if ( $before !== $after ) {
			$changes[ $key ] = array( 'before' => $before, 'after' => $after );
		}
	}

	if ( $apply && $changes ) {
		$elementor_changed = false;
		foreach ( $elementor as $key => $value ) {
			if ( isset( $changes[ 'elementor.' . $key ] ) ) {
				update_option( EG_PUB_ELEMENTOR_PERF[ $key ][0], $value );
				$elementor_changed = true;
			}
		}
		if ( $elementor_changed && class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
		// WP Rocket escucha este update_option: rehace su fichero de configuración.
		update_option( 'wp_rocket_settings', $new );
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
	}

	$cleared = false;
	if ( ! empty( $p['clear'] ) && function_exists( 'rocket_clean_domain' ) && ! ( $apply && $changes ) ) {
		// after_rocket_clean_domain es lo que escucha el complemento de Cloudflare para purgar.
		rocket_clean_domain();
		$cleared = true;
	}
	$cloudflare = null;
	if ( ! empty( $p['clear'] ) ) {
		$cloudflare = eg_pub_rocket_purge_cloudflare( $opts );
	}

	return new WP_REST_Response( array(
		'ok'       => true,
		'applied'  => $apply && (bool) $changes,
		'cleared'  => $cleared || ( $apply && $changes ),
		'cloudflare' => $cloudflare,
		'version'  => WP_ROCKET_VERSION,
		'settings' => eg_pub_rocket_view( $apply ? $new : $opts ),
		'elementor' => array_map( fn( $def ) => get_option( $def[0], null ), EG_PUB_ELEMENTOR_PERF ),
		'changes'  => $changes,
	), 200 );
}
