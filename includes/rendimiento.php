<?php
/**
 * Tipología «Gestión de la web»: ajustes de WP Rocket que no se pueden tocar sin entrar en wp-admin.
 *   POST /wp-json/eg/v1/rocket
 * Sin parámetros devuelve los ajustes que gestiona este endpoint y si la licencia parece oficial.
 *   add / remove   {"exclude_lazyload": ["montaje-cookies"], "cache_reject_uri": ["/resumen-de-compra/(.*)"]}
 *                  añade o quita valores de las listas (sin duplicar ni tocar el resto de la lista)
 *   set            {"async_css": 0, "lazyload_iframes": 1}  interruptores (0/1)
 * Como /seo y /term, por defecto solo previsualiza; apply:true guarda y vacía la caché de WP Rocket.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_ROCKET_LISTS    = array( 'exclude_lazyload', 'cache_reject_uri', 'cache_reject_cookies', 'exclude_css', 'exclude_js', 'exclude_defer_js', 'delay_js_exclusions' );
const EG_PUB_ROCKET_SWITCHES = array( 'lazyload', 'lazyload_iframes', 'lazyload_css_bg_img', 'remove_unused_css', 'async_css', 'delay_js', 'defer_all_js', 'minify_css', 'minify_js', 'image_dimensions', 'cache_mobile', 'do_caching_mobile_files' );

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

	$changes = array();
	foreach ( array_merge( EG_PUB_ROCKET_LISTS, EG_PUB_ROCKET_SWITCHES ) as $key ) {
		$before = $opts[ $key ] ?? null;
		$after  = $new[ $key ] ?? null;
		if ( $before !== $after ) {
			$changes[ $key ] = array( 'before' => $before, 'after' => $after );
		}
	}

	if ( $apply && $changes ) {
		// WP Rocket escucha este update_option: rehace su fichero de configuración.
		update_option( 'wp_rocket_settings', $new );
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
	}

	return new WP_REST_Response( array(
		'ok'       => true,
		'applied'  => $apply && (bool) $changes,
		'version'  => WP_ROCKET_VERSION,
		'settings' => eg_pub_rocket_view( $apply ? $new : $opts ),
		'changes'  => $changes,
	), 200 );
}
