<?php
/**
 * Tipología «Gestión de la web»: pasar los pedidos de WooCommerce a HPOS (tablas propias de pedidos).
 *   POST /wp-json/eg/v1/hpos
 * Sin parámetros devuelve el estado: si HPOS es el almacenamiento principal, si la sincronización (modo compatible)
 * está activa, cuántos pedidos faltan por sincronizar y qué plugins activos declaran ser compatibles, incompatibles
 * o no lo dicen.
 *   sync      true/false: activa o desactiva la sincronización entre las tablas antiguas y las nuevas.
 *   batches   número de lotes (máx. 20, de 200 pedidos) que se sincronizan ahora mismo, sin esperar al cron.
 *   enable    true/false: HPOS como almacenamiento principal (true) o volver a las entradas de WordPress (false).
 *             WooCommerce lo rechaza si quedan pedidos por sincronizar o hay plugins incompatibles.
 * Solo previsualiza; con apply:true hace los cambios. Requiere WooCommerce.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'eg_pub_modules', function ( $modules ) {
	$modules['hpos'] = array( 'type' => 'gestion', 'routes' => array( 'hpos' ) );
	return $modules;
} );

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_module_enabled( 'hpos' ) ) {
		return;
	}
	register_rest_route( 'eg/v1', '/hpos', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_hpos_handle',
		'permission_callback' => eg_pub_module_permission( 'manage_woocommerce' ),
	) );
} );

/** Estado de HPOS en esta web. */
function eg_pub_hpos_state() {
	$sync_class = 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\DataSynchronizer';
	$feat_class = 'Automattic\\WooCommerce\\Internal\\Features\\FeaturesController';
	$state      = array(
		'enabled'        => 'yes' === get_option( 'woocommerce_custom_orders_table_enabled' ),
		'sync'           => 'yes' === get_option( 'woocommerce_custom_orders_table_data_sync_enabled' ),
		'tables_created' => 'yes' === get_option( 'woocommerce_custom_orders_table_created' ),
		'pending'        => null,
		'plugins'        => null,
	);
	try {
		$sync = wc_get_container()->get( $sync_class );
		if ( method_exists( $sync, 'get_current_orders_pending_sync_count' ) && $state['tables_created'] ) {
			$state['pending'] = (int) $sync->get_current_orders_pending_sync_count();
		}
		$feat = wc_get_container()->get( $feat_class );
		if ( method_exists( $feat, 'get_compatible_plugins_for_feature' ) ) {
			$c                = (array) $feat->get_compatible_plugins_for_feature( 'custom_order_tables', true );
			$state['plugins'] = array(
				'compatible'   => array_values( (array) ( $c['compatible'] ?? array() ) ),
				'incompatible' => array_values( (array) ( $c['incompatible'] ?? array() ) ),
				'uncertain'    => array_values( (array) ( $c['uncertain'] ?? array() ) ),
			);
		}
	} catch ( Throwable $e ) {
		$state['error'] = $e->getMessage();
	}
	return $state;
}

function eg_pub_hpos_handle( WP_REST_Request $r ) {
	if ( ! function_exists( 'wc_get_container' ) ) {
		return new WP_Error( 'eg_no_wc', 'WooCommerce no está activo.', array( 'status' => 400 ) );
	}
	$p     = (array) $r->get_json_params();
	$apply = ! empty( $p['apply'] );
	$out   = array( 'ok' => true, 'applied' => $apply, 'before' => eg_pub_hpos_state(), 'changes' => array() );

	if ( array_key_exists( 'sync', $p ) ) {
		$want = ! empty( $p['sync'] ) ? 'yes' : 'no';
		$out['changes']['sync'] = $want;
		if ( $apply ) {
			update_option( 'woocommerce_custom_orders_table_data_sync_enabled', $want );
		}
	}

	if ( ! empty( $p['batches'] ) ) {
		$batches = max( 1, min( 20, (int) $p['batches'] ) );
		$out['changes']['batches'] = $batches;
		if ( $apply ) {
			try {
				$sync = wc_get_container()->get( 'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\DataSynchronizer' );
				$done = 0;
				for ( $i = 0; $i < $batches; $i++ ) {
					$batch = (array) $sync->get_next_batch_to_process( 200 );
					if ( ! $batch ) {
						break;
					}
					$sync->process_batch( $batch );
					$done += count( $batch );
				}
				$out['synced'] = $done;
			} catch ( Throwable $e ) {
				$out['ok']    = false;
				$out['error'] = $e->getMessage();
			}
		}
	}

	if ( array_key_exists( 'enable', $p ) ) {
		$want = ! empty( $p['enable'] );
		$out['changes']['enable'] = $want;
		if ( $apply ) {
			try {
				$feat = wc_get_container()->get( 'Automattic\\WooCommerce\\Internal\\Features\\FeaturesController' );
				$feat->change_feature_enable( 'custom_order_tables', $want );
			} catch ( Throwable $e ) {
				$out['ok']    = false;
				$out['error'] = $e->getMessage();
			}
		}
	}

	$out['state'] = eg_pub_hpos_state();
	if ( isset( $out['changes']['enable'] ) && $apply && $out['state']['enabled'] !== $out['changes']['enable'] ) {
		$out['ok']      = false;
		$out['refused'] = 'WooCommerce no ha cambiado el almacenamiento (pedidos sin sincronizar o plugins incompatibles).';
	}
	return rest_ensure_response( $out );
}
