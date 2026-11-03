<?php
/**
 * Copias de seguridad con UpdraftPlus desde Make (antes de cambios grandes en una web):
 *   POST /wp-json/eg/v1/backup
 * Está siempre activo, sea cual sea la tipología. Sin parámetros devuelve el estado: la última copia (fecha, si
 * terminó bien y sus errores), las últimas copias del historial, si hay una en curso y el destino remoto.
 *   run: true  lanza ya una copia completa (base de datos y archivos) con los ajustes de UpdraftPlus de la web
 *              (destino remoto incluido), igual que «Hacer una copia ahora». Se ejecuta en segundo plano con el
 *              cron de WordPress; se sigue con nuevas llamadas sin parámetros.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'eg/v1', '/backup', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_backup_handle',
		'permission_callback' => 'eg_pub_permission',
	) );
} );

/** Estado de UpdraftPlus: última copia, historial reciente y copia en curso. */
function eg_pub_backup_state() {
	$last    = (array) get_option( 'updraft_last_backup', array() );
	$history = (array) get_option( 'updraft_backup_history', array() );
	krsort( $history );
	$recent = array();
	foreach ( array_slice( $history, 0, 5, true ) as $time => $set ) {
		$set      = (array) $set;
		$recent[] = array(
			'time'     => wp_date( 'Y-m-d H:i', (int) $time ),
			'database' => ! empty( $set['db'] ),
			'files'    => array_values( array_intersect( array( 'plugins', 'themes', 'uploads', 'others' ), array_keys( $set ) ) ),
			'remote'   => array_values( (array) ( $set['service'] ?? array() ) ),
		);
	}
	$running = false;
	foreach ( (array) _get_cron_array() as $events ) {
		foreach ( array_keys( (array) $events ) as $hook ) {
			if ( in_array( $hook, array( 'updraft_backup_all', 'updraft_backup_resume', 'updraft_backupnow_backup_all' ), true ) ) {
				$running = true;
			}
		}
	}
	return array(
		'updraftplus' => defined( 'UPDRAFTPLUS_DIR' ),
		'last'        => $last ? array(
			'time'    => ! empty( $last['backup_time'] ) ? wp_date( 'Y-m-d H:i', (int) $last['backup_time'] ) : null,
			'success' => ! empty( $last['success'] ),
			'errors'  => array_values( array_map( 'wp_strip_all_tags', array_map( 'strval', (array) ( $last['errors'] ?? array() ) ) ) ),
		) : null,
		'recent'      => $recent,
		'in_progress' => $running,
		'remote'      => array_values( array_filter( (array) get_option( 'updraft_service', array() ), fn( $s ) => is_string( $s ) && '' !== $s && 'none' !== $s ) ),
	);
}

function eg_pub_backup_handle( WP_REST_Request $r ) {
	if ( ! defined( 'UPDRAFTPLUS_DIR' ) ) {
		return eg_pub_error( 'eg_updraft', 'UpdraftPlus no está activo en esta web.' );
	}
	$out = array( 'ok' => true, 'site' => home_url( '/' ), 'started' => false );
	if ( ! empty( $r['run'] ) ) {
		$state = eg_pub_backup_state();
		if ( $state['in_progress'] ) {
			$out['message'] = 'Ya hay una copia en curso.';
		} else {
			// El mismo evento que usa UpdraftPlus para sus copias programadas: base de datos y archivos.
			wp_schedule_single_event( time(), 'updraft_backup_all' );
			spawn_cron();
			$out['started'] = true;
			$out['message'] = 'Copia completa lanzada en segundo plano. Llama sin parámetros para ver cuándo termina.';
		}
	}
	$out['state'] = eg_pub_backup_state();
	return new WP_REST_Response( $out, 200 );
}
