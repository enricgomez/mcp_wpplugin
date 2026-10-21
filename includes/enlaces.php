<?php
/**
 * Enlaces por idioma (tipología «Gestión de la web»):
 *   POST /wp-json/eg/v1/lang-links
 * Guarda reglas que cambian un enlace según el idioma de WPML en el que se ve la página, por ejemplo el botón
 * Reservar de la cabecera hacia el motor de reservas en cada idioma. El cambio se hace al servir la página, sobre
 * el HTML final, así funciona igual si el enlace está en una cabecera de Elementor sin traducir, en un menú o en
 * el contenido, y no hay que editar cada traducción ni la traducción de cadenas de WPML.
 *
 * Regla: {"from": "https://engine.witbooking.com/property/campingelfar",
 *         "to": "https://engine.witbooking.com/{lang}/property/campingelfar"}
 * Opcionales: "langs" ({"ca": "ca", "en": "en"}: código del destino para cada idioma de WPML, si no coincide) y
 * "skip" (idiomas en los que no se cambia; por defecto el idioma principal de WPML).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_OPTION_LANG_LINKS = 'eg_pub_lang_links';

add_filter( 'eg_pub_modules', function ( $modules ) {
	$modules['enlaces'] = array( 'type' => 'gestion', 'routes' => array( 'lang-links' ) );
	return $modules;
} );

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_module_enabled( 'enlaces' ) ) {
		return;
	}
	register_rest_route( 'eg/v1', '/lang-links', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_lang_links_handle',
		'permission_callback' => eg_pub_module_permission( 'manage_options' ),
	) );
} );

function eg_pub_lang_links_rules() {
	$rules = get_option( EG_PUB_OPTION_LANG_LINKS, array() );
	return is_array( $rules ) ? $rules : array();
}

/** Pares «de → a» de todas las reglas para ese idioma (también en la forma escapada del JSON de Elementor). */
function eg_pub_lang_links_pairs( $lang, $rules = null ) {
	$default = apply_filters( 'wpml_default_language', null );
	$pairs   = array();
	foreach ( null === $rules ? eg_pub_lang_links_rules() : $rules as $rule ) {
		$skip = isset( $rule['skip'] ) ? (array) $rule['skip'] : array( $default );
		if ( '' === (string) $lang || in_array( $lang, $skip, true ) ) {
			continue;
		}
		$code = isset( $rule['langs'][ $lang ] ) ? (string) $rule['langs'][ $lang ] : $lang;
		if ( '' === $code ) {
			continue;
		}
		$to = str_replace( '{lang}', $code, $rule['to'] );
		$pairs[ $rule['from'] ]                         = $to;
		$pairs[ str_replace( '/', '\/', $rule['from'] ) ] = str_replace( '/', '\/', $to );
	}
	return $pairs;
}

// Al servir una página: cambia los enlaces en el HTML final según el idioma actual.
add_action( 'template_redirect', function () {
	if ( is_admin() || wp_doing_ajax() || is_feed() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ! eg_pub_module_enabled( 'enlaces' ) ) {
		return;
	}
	if ( isset( $_GET['elementor-preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$pairs = eg_pub_lang_links_pairs( (string) apply_filters( 'wpml_current_language', null ) );
	if ( ! $pairs ) {
		return;
	}
	ob_start( function ( $html ) use ( $pairs ) {
		return strtr( $html, $pairs ); // strtr no vuelve a cambiar lo ya cambiado.
	} );
}, 0 );

/** Dónde aparece una cadena en la base de datos (entradas, metas y opciones), para saber qué cubre la regla. */
function eg_pub_lang_links_find( $needle ) {
	global $wpdb;
	$out  = array();
	$like = '%' . $wpdb->esc_like( $needle ) . '%';
	$esc  = '%' . $wpdb->esc_like( str_replace( '/', '\/', $needle ) ) . '%';
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT DISTINCT p.ID, p.post_type, p.post_status, p.post_title FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
		WHERE p.post_type <> 'revision' AND p.post_status NOT IN ('trash','auto-draft','inherit')
		AND ( p.post_content LIKE %s OR p.post_content LIKE %s OR m.meta_value LIKE %s OR m.meta_value LIKE %s ) LIMIT 50",
		$like, $esc, $like, $esc
	) );
	foreach ( $rows as $r ) {
		$lang  = apply_filters( 'wpml_element_language_code', null, array( 'element_id' => $r->ID, 'element_type' => $r->post_type ) );
		$out[] = array(
			'id'       => (int) $r->ID,
			'type'     => $r->post_type,
			'template' => (string) get_post_meta( $r->ID, '_elementor_template_type', true ),
			'status'   => $r->post_status,
			'title'    => $r->post_title,
			'lang'     => (string) $lang,
		);
	}
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_value LIKE %s OR option_value LIKE %s LIMIT 20", $like, $esc ) ) as $name ) {
		$out[] = array( 'id' => 'option:' . $name, 'type' => 'option' );
	}
	return $out;
}

function eg_pub_lang_links_handle( WP_REST_Request $r ) {
	$p       = $r->get_params();
	$dry_run = ! empty( $p['dry_run'] );
	$rules   = eg_pub_lang_links_rules();

	if ( ! empty( $p['clear'] ) ) {
		$rules = array();
	} elseif ( isset( $p['rules'] ) ) {
		$rules = array();
		foreach ( (array) $p['rules'] as $rule ) {
			$from = esc_url_raw( trim( (string) ( $rule['from'] ?? '' ) ) );
			$to   = trim( (string) ( $rule['to'] ?? '' ) );
			if ( '' === $from || false === strpos( $to, '{lang}' ) || '' === esc_url_raw( str_replace( '{lang}', 'xx', $to ) ) ) {
				return eg_pub_error( 'eg_rule', 'Cada regla necesita from (URL) y to (URL con {lang}).' );
			}
			$clean = array( 'from' => $from, 'to' => $to );
			if ( isset( $rule['langs'] ) && is_array( $rule['langs'] ) ) {
				$clean['langs'] = array_map( 'sanitize_text_field', $rule['langs'] );
			}
			if ( isset( $rule['skip'] ) ) {
				$clean['skip'] = array_values( array_map( 'sanitize_key', (array) $rule['skip'] ) );
			}
			$rules[] = $clean;
		}
	}

	if ( ! $dry_run && ( isset( $p['rules'] ) || ! empty( $p['clear'] ) ) ) {
		update_option( EG_PUB_OPTION_LANG_LINKS, $rules, false );
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
	}

	// Cómo queda el enlace en cada idioma activo.
	$langs   = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
	$langs   = is_array( $langs ) ? array_keys( $langs ) : array();
	$preview = array();
	foreach ( $rules as $i => $rule ) {
		foreach ( $langs as $lang ) {
			$pairs                    = eg_pub_lang_links_pairs( $lang, array( $rule ) );
			$preview[ $i ][ $lang ] = $pairs[ $rule['from'] ] ?? $rule['from'];
		}
	}
	$found = array();
	foreach ( $rules as $rule ) {
		$found[ $rule['from'] ] = eg_pub_lang_links_find( $rule['from'] );
	}

	return new WP_REST_Response( array(
		'ok'               => true,
		'dry_run'          => $dry_run,
		'rules'            => $rules,
		'default_language' => apply_filters( 'wpml_default_language', null ),
		'languages'        => $langs,
		'preview'          => $preview,
		'found'            => $found,
		'wp_rocket'        => function_exists( 'rocket_clean_domain' ),
	), 200 );
}
