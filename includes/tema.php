<?php
/**
 * Ajustes del tema y textos fijos de la web (tipología «Gestión de la web»):
 *   POST /wp-json/eg/v1/theme
 * Sin parámetros devuelve el tema activo, los textos cambiados con labels y, si el tema guarda sus ajustes en
 * una opción (The7 y otros con Options Framework), los ajustes cuyo nombre contiene alguna cadena de `find`
 * (p. ej. ["sidebar", "woocommerce"]).
 *   options  {"ajuste": valor} cambia ajustes que ya existen en la opción del tema.
 *   labels   {"Out Of Stock": "Muy pronto"} cambia un texto fijo del tema o de los plugins (el original en
 *            inglés, tal cual sale en el código); "" o null lo devuelve al original. Se aplica con gettext,
 *            así que al desactivar el plugin todo vuelve a como estaba.
 * Como /seo, solo previsualiza; guarda con apply:true y vacía la caché de WP Rocket.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_LABELS_OPT = 'eg_pub_labels';

add_filter( 'eg_pub_modules', function ( $modules ) {
	$modules['tema'] = array( 'type' => 'gestion', 'routes' => array( 'theme' ) );
	return $modules;
} );

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_module_enabled( 'tema' ) ) {
		return;
	}
	register_rest_route( 'eg/v1', '/theme', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_theme_handle',
		'permission_callback' => eg_pub_module_permission( 'manage_options' ),
	) );
} );

/** Textos cambiados: se buscan por el original, en cualquier dominio de traducción. */
function eg_pub_labels_filter( $translation, $text ) {
	static $labels = null;
	if ( null === $labels ) {
		$labels = (array) get_option( EG_PUB_LABELS_OPT, array() );
	}
	return isset( $labels[ $text ] ) ? $labels[ $text ] : $translation;
}
if ( get_option( EG_PUB_LABELS_OPT ) ) {
	add_filter( 'gettext', 'eg_pub_labels_filter', 20, 2 );
	add_filter( 'gettext_with_context', function ( $translation, $text ) {
		return eg_pub_labels_filter( $translation, $text );
	}, 20, 2 );
}

/** Nombre de la opción donde el tema guarda sus ajustes (Options Framework, p. ej. «the7»), o ''. */
function eg_pub_theme_option_name() {
	$of = get_option( 'optionsframework' );
	return is_array( $of ) && ! empty( $of['id'] ) && is_array( get_option( $of['id'] ) ) ? (string) $of['id'] : '';
}

function eg_pub_theme_handle( WP_REST_Request $r ) {
	$p       = $r->get_params();
	$apply   = ! empty( $p['apply'] );
	$name    = eg_pub_theme_option_name();
	$opts    = $name ? (array) get_option( $name ) : array();
	$labels  = (array) get_option( EG_PUB_LABELS_OPT, array() );
	$changes = array();
	$notes   = array();

	foreach ( (array) ( $p['options'] ?? array() ) as $key => $value ) {
		if ( ! array_key_exists( $key, $opts ) ) {
			$notes[] = 'el ajuste ' . $key . ' no existe en la opción del tema' . ( $name ? '' : ' (no se ha encontrado)' );
			continue;
		}
		if ( $opts[ $key ] !== $value ) {
			$changes[]    = 'options.' . $key;
			$opts[ $key ] = $value;
		}
	}
	foreach ( (array) ( $p['labels'] ?? array() ) as $from => $to ) {
		$from = (string) $from;
		if ( null === $to || '' === $to ) {
			if ( isset( $labels[ $from ] ) ) {
				unset( $labels[ $from ] );
				$changes[] = 'labels.' . $from;
			}
		} elseif ( ( $labels[ $from ] ?? null ) !== sanitize_text_field( $to ) ) {
			$labels[ $from ] = sanitize_text_field( $to );
			$changes[]       = 'labels.' . $from;
		}
	}

	if ( $apply && $changes ) {
		if ( $name ) {
			update_option( $name, $opts );
		}
		if ( $labels ) {
			update_option( EG_PUB_LABELS_OPT, $labels, true );
		} else {
			delete_option( EG_PUB_LABELS_OPT );
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
	}

	$found = array();
	foreach ( (array) ( $p['find'] ?? array() ) as $needle ) {
		foreach ( $opts as $key => $value ) {
			if ( '' !== (string) $needle && false !== stripos( (string) $key, (string) $needle ) ) {
				$found[ $key ] = $value;
			}
		}
	}

	$theme = wp_get_theme();
	return new WP_REST_Response( array(
		'ok'           => true,
		'applied'      => $apply && $changes,
		'changes'      => $changes,
		'notes'        => $notes,
		'theme'        => $theme->get( 'Name' ),
		'template'     => get_template(),
		'stylesheet'   => get_stylesheet(),
		'option_name'  => $name,
		'option_count' => count( $opts ),
		'options'      => $found,
		'labels'       => $labels,
	), 200 );
}
