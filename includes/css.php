<?php
/**
 * Tipología «Gestión de la web», módulo «css»: hojas de estilo propias por clave, con historial y vuelta atrás.
 *   POST /wp-json/eg/v1/css
 * Sin parámetros lista las hojas guardadas (clave, activa, páginas, tamaño, fecha y versiones anteriores).
 *   key       clave de la hoja (p. ej. "roiback"). Con solo key devuelve su CSS y su historial.
 *   css       CSS nuevo. La versión anterior pasa al historial (se guardan las 10 últimas).
 *   enabled   true/false: la pinta o no en la web. Una hoja nueva nace apagada salvo que se diga lo contrario.
 *   pages     [ids] solo en esas páginas (con WPML, también en sus traducciones); [] = en toda la web.
 *   label     descripción corta.
 *   rollback  true: vuelve a la versión anterior (la actual pasa al historial); o un número = versiones atrás.
 *   delete    true: borra la hoja y su historial.
 * Solo previsualiza; guarda con apply:true. Al guardar vacía la caché de WP Rocket.
 * Prueba sin publicar: con ?eg_css=<clave> en la URL la hoja se pinta aunque esté apagada (solo en esa visita);
 * ?eg_css=off quita todas las hojas en esa visita, para comparar con la web sin ellas.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_CSS_OPT     = 'eg_pub_css';
const EG_PUB_CSS_HISTORY = 10;

add_filter( 'eg_pub_modules', function ( $modules ) {
	$modules['css'] = array( 'type' => 'gestion', 'routes' => array( 'css' ) );
	return $modules;
} );

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_module_enabled( 'css' ) ) {
		return;
	}
	register_rest_route( 'eg/v1', '/css', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_css_handle',
		'permission_callback' => eg_pub_module_permission( 'manage_options' ),
	) );
} );

function eg_pub_css_all() {
	$all = get_option( EG_PUB_CSS_OPT, array() );
	return is_array( $all ) ? $all : array();
}

function eg_pub_css_clean( $css ) {
	// Nada de cerrar la etiqueta <style> desde dentro.
	return trim( str_ireplace( array( '</style', '<style' ), '', (string) $css ) );
}

function eg_pub_css_summary( $key, $s ) {
	return array(
		'key'      => $key,
		'label'    => isset( $s['label'] ) ? $s['label'] : '',
		'enabled'  => ! empty( $s['enabled'] ),
		'pages'    => isset( $s['pages'] ) ? array_values( $s['pages'] ) : array(),
		'bytes'    => strlen( isset( $s['css'] ) ? $s['css'] : '' ),
		'updated'  => isset( $s['updated'] ) ? $s['updated'] : '',
		'versions' => isset( $s['history'] ) ? count( $s['history'] ) : 0,
		'test_url' => add_query_arg( 'eg_css', $key, home_url( '/' ) ),
	);
}

function eg_pub_css_handle( WP_REST_Request $r ) {
	$all   = eg_pub_css_all();
	$key   = sanitize_key( (string) $r->get_param( 'key' ) );
	$apply = (bool) $r->get_param( 'apply' );

	if ( '' === $key ) {
		$list = array();
		foreach ( $all as $k => $s ) {
			$list[] = eg_pub_css_summary( $k, $s );
		}
		return array( 'sheets' => $list );
	}
	if ( 'off' === $key ) {
		return new WP_Error( 'eg_css_key', 'La clave «off» está reservada.', array( 'status' => 400 ) );
	}

	$exists  = isset( $all[ $key ] );
	$sheet   = $exists ? $all[ $key ] : array( 'css' => '', 'enabled' => false, 'pages' => array(), 'label' => '', 'history' => array() );
	$changed = array();
	$now     = current_time( 'mysql' );

	if ( $r->get_param( 'delete' ) ) {
		if ( ! $exists ) {
			return new WP_Error( 'eg_css_missing', 'No hay ninguna hoja con esa clave.', array( 'status' => 404 ) );
		}
		if ( $apply ) {
			unset( $all[ $key ] );
			update_option( EG_PUB_CSS_OPT, $all, true );
			eg_pub_css_purge();
		}
		return array( 'key' => $key, 'deleted' => $apply, 'applied' => $apply );
	}

	$rollback = $r->get_param( 'rollback' );
	if ( $rollback ) {
		$steps = true === $rollback || ! is_numeric( $rollback ) ? 1 : max( 1, (int) $rollback );
		$hist  = isset( $sheet['history'] ) ? $sheet['history'] : array();
		if ( count( $hist ) < $steps ) {
			return new WP_Error( 'eg_css_history', 'No hay tantas versiones anteriores (hay ' . count( $hist ) . ').', array( 'status' => 400 ) );
		}
		$target = $hist[ $steps - 1 ];
		// La actual y las que se saltan siguen en el historial, por si hay que volver a ellas.
		$rest = array_merge(
			array( array( 'css' => $sheet['css'], 'date' => isset( $sheet['updated'] ) ? $sheet['updated'] : $now ) ),
			array_slice( $hist, 0, $steps - 1 ),
			array_slice( $hist, $steps )
		);
		$sheet['css']     = $target['css'];
		$sheet['history'] = array_slice( $rest, 0, EG_PUB_CSS_HISTORY );
		$sheet['updated'] = $now;
		$changed[]        = 'rollback';
	}

	if ( null !== $r->get_param( 'css' ) ) {
		$css = eg_pub_css_clean( $r->get_param( 'css' ) );
		if ( $css !== $sheet['css'] ) {
			if ( '' !== $sheet['css'] ) {
				$hist = isset( $sheet['history'] ) ? $sheet['history'] : array();
				array_unshift( $hist, array( 'css' => $sheet['css'], 'date' => isset( $sheet['updated'] ) ? $sheet['updated'] : $now ) );
				$sheet['history'] = array_slice( $hist, 0, EG_PUB_CSS_HISTORY );
			}
			$sheet['css']     = $css;
			$sheet['updated'] = $now;
			$changed[]        = 'css';
		}
	}
	if ( null !== $r->get_param( 'enabled' ) ) {
		$sheet['enabled'] = rest_sanitize_boolean( $r->get_param( 'enabled' ) );
		$changed[]        = 'enabled';
	}
	if ( null !== $r->get_param( 'pages' ) ) {
		$sheet['pages'] = array_values( array_filter( array_map( 'absint', (array) $r->get_param( 'pages' ) ) ) );
		$changed[]      = 'pages';
	}
	if ( null !== $r->get_param( 'label' ) ) {
		$sheet['label'] = sanitize_text_field( (string) $r->get_param( 'label' ) );
		$changed[]      = 'label';
	}

	if ( ! $exists && ! $changed ) {
		return new WP_Error( 'eg_css_missing', 'No hay ninguna hoja con esa clave.', array( 'status' => 404 ) );
	}

	if ( $apply && $changed ) {
		$all[ $key ] = $sheet;
		update_option( EG_PUB_CSS_OPT, $all, true );
		eg_pub_css_purge();
	}

	$out            = eg_pub_css_summary( $key, $sheet );
	$out['css']     = $sheet['css'];
	$out['history'] = array_map( function ( $h ) {
		return array( 'date' => $h['date'], 'bytes' => strlen( $h['css'] ) );
	}, isset( $sheet['history'] ) ? $sheet['history'] : array() );
	$out['changed'] = $changed;
	$out['applied'] = $apply && $changed;
	return $out;
}

function eg_pub_css_purge() {
	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
	}
}

/** ¿Va esta hoja en la página actual? */
function eg_pub_css_on_page( $s ) {
	if ( empty( $s['pages'] ) ) {
		return true;
	}
	$id = (int) get_queried_object_id();
	if ( ! $id ) {
		return false;
	}
	$ids = array_map( 'intval', $s['pages'] );
	if ( in_array( $id, $ids, true ) ) {
		return true;
	}
	// WPML: la traducción de una página de la lista también cuenta.
	$type = get_post_type( $id );
	foreach ( $ids as $page ) {
		$tr = apply_filters( 'wpml_object_id', $page, $type ? $type : 'page', false );
		if ( $tr && (int) $tr === $id ) {
			return true;
		}
	}
	return false;
}

/* Las hojas se pintan aunque el módulo se apague después: se quitan apagándolas o borrándolas. */
add_action( 'wp_head', function () {
	$all = eg_pub_css_all();
	if ( ! $all ) {
		return;
	}
	$test = isset( $_GET['eg_css'] ) ? sanitize_key( wp_unslash( $_GET['eg_css'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	if ( 'off' === $test ) {
		return;
	}
	foreach ( $all as $key => $s ) {
		if ( empty( $s['css'] ) ) {
			continue;
		}
		$show = $key === $test || ( ! empty( $s['enabled'] ) && eg_pub_css_on_page( $s ) );
		if ( $show ) {
			echo "\n<style id=\"eg-css-" . esc_attr( $key ) . "\">\n" . $s['css'] . "\n</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}
}, 100 );
