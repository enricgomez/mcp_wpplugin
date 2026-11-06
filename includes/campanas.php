<?php
/**
 * Tipología WooCommerce: campañas de la tienda (descuentos con fechas y cierres por vacaciones).
 *   POST /wp-json/eg/v1/promo
 *   rules        true lista las reglas de Woo Discount Rules (id, título, activa, fechas, descuento, filtros, insignia)
 *   clone        {"from": 6, "title": "…", "date_from": "2026-11-23 00:00", "date_to": "2026-12-23 23:59",
 *                 "percent": 5, "cart_label": "…", "badge_text": "…", "badge_display": true, "exclusive": false,
 *                 "exclude_products": [60703], "enabled": true}
 *                copia una regla existente (filtros, condiciones, ajustes) en una nueva con esos cambios. Las fechas
 *                van en la hora de la web
 *   update       {"id": 13, …los mismos campos…} cambia una regla existente
 *   closure      {"notice_from": "2026-12-01 00:00", "notice_to": "2027-01-06 23:59", "closed_from": "2026-12-24 00:00",
 *                 "closed_to": "2027-01-06 23:59", "notice": {"es": "…", "ca": "…"}}
 *                aviso de la tienda de WooCommerce (la barra de «Aviso de la tienda», en el idioma de cada página)
 *                entre notice_from y notice_to, y tienda cerrada (nada se puede comprar ni pagar) entre closed_from y
 *                closed_to. `false` lo quita. Sin `closure` ni el resto, la respuesta enseña el cierre guardado
 * Como /seo, solo previsualiza; apply:true guarda. En cada fecha de inicio o fin (de una regla guardada aquí o del
 * cierre) se vacía la caché de WP Rocket con el cron de WordPress, para que las páginas en caché cambien de precio o
 * de aviso a su hora.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_PROMO_CLOSURE = 'eg_pub_shop_closure';
const EG_PUB_PROMO_PURGE   = 'eg_pub_promo_purge';

add_filter( 'eg_pub_modules', function ( $modules ) {
	$modules['campanas'] = array( 'type' => 'woocommerce', 'routes' => array( 'promo' ) );
	return $modules;
} );

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_module_enabled( 'campanas' ) ) {
		return;
	}
	register_rest_route( 'eg/v1', '/promo', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_promo_handle',
		'permission_callback' => eg_pub_module_permission( 'manage_woocommerce' ),
	) );
} );

/** «2026-11-23 00:00» en la hora de la web a timestamp; null si no se entiende. */
function eg_pub_promo_time( $s ) {
	if ( ! is_string( $s ) || '' === trim( $s ) ) {
		return null;
	}
	try {
		return ( new DateTimeImmutable( $s, wp_timezone() ) )->getTimestamp();
	} catch ( Exception $e ) {
		return null;
	}
}

function eg_pub_promo_local( $ts ) {
	return $ts ? wp_date( 'Y-m-d H:i', (int) $ts ) : null;
}

// Woo Discount Rules guarda las fechas como la hora local escrita en UTC (00:00 de la web = 00:00 UTC).
function eg_pub_promo_wdr_time( $s ) {
	$ts = eg_pub_promo_time( $s );
	return null === $ts ? null : $ts + (int) wp_timezone()->getOffset( new DateTime( '@' . $ts ) );
}

function eg_pub_promo_wdr_local( $ts ) {
	return $ts ? gmdate( 'Y-m-d H:i', (int) $ts ) : null;
}

function eg_pub_promo_table() {
	global $wpdb;
	$table = $wpdb->prefix . 'wdr_rules';
	return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ? $table : null;
}

function eg_pub_promo_rule_view( $row ) {
	$json  = function ( $v ) {
		$d = json_decode( (string) $v, true );
		return null === $d ? $v : $d;
	};
	$badge = $json( $row['advanced_discount_message'] ?? '' );
	return array(
		'id'            => (int) $row['id'],
		'title'         => $row['title'],
		'enabled'       => (bool) $row['enabled'],
		'deleted'       => (bool) $row['deleted'],
		'exclusive'     => (bool) $row['exclusive'],
		'priority'      => (int) $row['priority'],
		'type'          => $row['discount_type'],
		'date_from'     => eg_pub_promo_wdr_local( $row['date_from'] ),
		'date_to'       => eg_pub_promo_wdr_local( $row['date_to'] ),
		'adjustments'   => $json( $row['product_adjustments'] ),
		'filters'       => $json( $row['filters'] ),
		'conditions'    => $json( $row['conditions'] ),
		'badge'         => is_array( $badge ) ? array( 'display' => ! empty( $badge['display'] ), 'text' => $badge['badge_text'] ?? '' ) : null,
		'used'          => (int) $row['used_limits'],
	);
}

/** Aplica a una fila de regla los cambios pedidos. Devuelve la fila o WP_Error. */
function eg_pub_promo_rule_patch( $row, $c ) {
	if ( isset( $c['title'] ) ) {
		$row['title'] = sanitize_text_field( $c['title'] );
	}
	foreach ( array( 'date_from', 'date_to' ) as $key ) {
		if ( array_key_exists( $key, $c ) ) {
			$ts = '' === $c[ $key ] || null === $c[ $key ] ? null : eg_pub_promo_wdr_time( $c[ $key ] );
			if ( null === $ts && ! empty( $c[ $key ] ) ) {
				return new WP_Error( 'eg_promo_date', 'Fecha no válida en ' . $key . '.' );
			}
			$row[ $key ] = $ts;
		}
	}
	if ( isset( $c['enabled'] ) ) {
		$row['enabled'] = $c['enabled'] ? 1 : 0;
	}
	if ( isset( $c['exclusive'] ) ) {
		$row['exclusive'] = $c['exclusive'] ? 1 : 0;
	}
	if ( isset( $c['percent'] ) || isset( $c['cart_label'] ) ) {
		$adj = json_decode( (string) $row['product_adjustments'], true );
		$adj = is_array( $adj ) ? $adj : array();
		if ( isset( $c['percent'] ) ) {
			$adj['type']  = 'percentage';
			$adj['value'] = (string) (float) $c['percent'];
		}
		if ( isset( $c['cart_label'] ) ) {
			$adj['cart_label'] = sanitize_text_field( $c['cart_label'] );
		}
		$row['product_adjustments'] = wp_json_encode( $adj );
	}
	if ( isset( $c['badge_text'] ) || isset( $c['badge_display'] ) ) {
		$msg = json_decode( (string) $row['advanced_discount_message'], true );
		$msg = is_array( $msg ) ? $msg : array( 'badge_color_picker' => '#c20000', 'badge_text_color_picker' => '#ffffff' );
		if ( isset( $c['badge_text'] ) ) {
			$msg['badge_text'] = wp_kses_post( $c['badge_text'] );
		}
		if ( isset( $c['badge_display'] ) ) {
			$msg['display'] = $c['badge_display'] ? '1' : '0';
		}
		$row['advanced_discount_message'] = wp_json_encode( $msg );
	}
	if ( ! empty( $c['exclude_products'] ) ) {
		$filters = json_decode( (string) $row['filters'], true );
		$filters = is_array( $filters ) ? $filters : array();
		$ids     = array_map( 'strval', array_map( 'intval', (array) $c['exclude_products'] ) );
		$done    = false;
		foreach ( $filters as &$f ) {
			if ( 'products' === ( $f['type'] ?? '' ) && 'not_in_list' === ( $f['method'] ?? '' ) ) {
				$f['value'] = array_values( array_unique( array_merge( array_map( 'strval', (array) $f['value'] ), $ids ) ) );
				$done       = true;
			}
		}
		unset( $f );
		if ( ! $done ) {
			$filters[ (string) ( count( $filters ) + 1 ) ] = array( 'type' => 'products', 'method' => 'not_in_list', 'value' => $ids, 'product_variants' => array(), 'product_variants_for_sale_badge' => array() );
		}
		$row['filters'] = wp_json_encode( $filters );
	}
	return $row;
}

/** Vacía la caché de WP Rocket en esos momentos (los que ya han pasado se ignoran). */
function eg_pub_promo_schedule_purges( $times ) {
	$out = array();
	foreach ( array_unique( array_filter( array_map( 'intval', $times ) ) ) as $ts ) {
		$ts += 60; // Un minuto después del cambio, para que la página nueva ya salga con el precio o el aviso nuevos.
		if ( $ts > time() && ! wp_next_scheduled( EG_PUB_PROMO_PURGE, array( $ts ) ) ) {
			wp_schedule_single_event( $ts, EG_PUB_PROMO_PURGE, array( $ts ) );
		}
		if ( $ts > time() ) {
			$out[] = eg_pub_promo_local( $ts );
		}
	}
	return $out;
}

add_action( EG_PUB_PROMO_PURGE, function () {
	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
	}
	if ( function_exists( 'wc_delete_product_transients' ) ) {
		wc_delete_product_transients();
	}
} );

function eg_pub_promo_handle( WP_REST_Request $request ) {
	global $wpdb;
	$p     = (array) $request->get_json_params();
	$apply = ! empty( $p['apply'] );

	if ( array_key_exists( 'closure', $p ) ) {
		return eg_pub_promo_closure_handle( $p['closure'], $apply );
	}
	if ( empty( $p['rules'] ) && empty( $p['clone'] ) && empty( $p['update'] ) ) {
		return new WP_REST_Response( array( 'ok' => true, 'closure' => eg_pub_promo_closure_view( get_option( EG_PUB_PROMO_CLOSURE ) ) ), 200 );
	}
	$table = eg_pub_promo_table();
	if ( ! $table ) {
		return eg_pub_error( 'eg_promo_wdr', 'No está la tabla de Woo Discount Rules en esta web.', 404 );
	}
	if ( ! empty( $p['rules'] ) ) {
		$rows = $wpdb->get_results( "SELECT * FROM {$table} WHERE deleted = 0 ORDER BY id DESC", ARRAY_A );
		return new WP_REST_Response( array( 'ok' => true, 'rules' => array_map( 'eg_pub_promo_rule_view', (array) $rows ) ), 200 );
	}

	$clone  = is_array( $p['clone'] ?? null ) ? $p['clone'] : null;
	$change = $clone ? $clone : ( is_array( $p['update'] ?? null ) ? $p['update'] : array() );
	$id     = (int) ( $clone ? ( $clone['from'] ?? 0 ) : ( $change['id'] ?? 0 ) );
	$row    = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ) : null;
	if ( ! $row ) {
		return eg_pub_error( 'eg_promo_rule', 'No existe la regla ' . $id . '.', 404 );
	}
	$before = eg_pub_promo_rule_view( $row );
	$new    = eg_pub_promo_rule_patch( $row, $change );
	if ( is_wp_error( $new ) ) {
		return eg_pub_error( $new->get_error_code(), $new->get_error_message() );
	}
	if ( $clone ) {
		unset( $new['id'] );
		$new['used_limits'] = 0;
		$new['deleted']     = 0;
		$new['priority']    = 1 + (int) $wpdb->get_var( "SELECT MAX(priority) FROM {$table}" );
		$new['created_on']  = current_time( 'mysql' );
		$new['created_by']  = get_current_user_id();
	}
	$new['modified_on'] = current_time( 'mysql' );
	$new['modified_by'] = get_current_user_id();
	$out = array( 'ok' => true, 'applied' => $apply, 'before' => $clone ? null : $before, 'after' => eg_pub_promo_rule_view( $new + array( 'id' => $clone ? 0 : $id ) ) );
	if ( ! $apply ) {
		return new WP_REST_Response( $out, 200 );
	}
	if ( $clone ) {
		$ok = $wpdb->insert( $table, $new );
		$id = (int) $wpdb->insert_id;
	} else {
		$ok = false !== $wpdb->update( $table, $new, array( 'id' => $id ) );
	}
	if ( ! $ok ) {
		return eg_pub_error( 'eg_promo_save', 'No se ha podido guardar la regla: ' . $wpdb->last_error, 500 );
	}
	$saved          = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
	$out['after']   = eg_pub_promo_rule_view( $saved );
	$out['purges']  = $saved['enabled'] ? eg_pub_promo_schedule_purges( array(
		$saved['date_from'] ? eg_pub_promo_time( gmdate( 'Y-m-d H:i:s', (int) $saved['date_from'] ) ) : 0,
		$saved['date_to'] ? eg_pub_promo_time( gmdate( 'Y-m-d H:i:s', (int) $saved['date_to'] ) ) : 0,
	) ) : array();
	if ( function_exists( 'rocket_clean_domain' ) && ( ! $saved['date_from'] || eg_pub_promo_time( gmdate( 'Y-m-d H:i:s', (int) $saved['date_from'] ) ) <= time() ) ) {
		rocket_clean_domain(); // La regla ya está en vigor: los precios cambian ahora.
		$out['cache_cleared'] = true;
	}
	return new WP_REST_Response( $out, 200 );
}

/* ----- Cierre de la tienda ----- */

function eg_pub_promo_closure_view( $c ) {
	if ( ! is_array( $c ) ) {
		return null;
	}
	$view = array( 'notice' => $c['notice'] ?? array() );
	foreach ( array( 'notice_from', 'notice_to', 'closed_from', 'closed_to' ) as $key ) {
		$view[ $key ] = eg_pub_promo_local( $c[ $key ] ?? 0 );
	}
	$now                 = time();
	$view['notice_now']  = eg_pub_promo_in( $c, 'notice', $now );
	$view['closed_now']  = eg_pub_promo_in( $c, 'closed', $now );
	return $view;
}

function eg_pub_promo_in( $c, $which, $now ) {
	$from = (int) ( $c[ $which . '_from' ] ?? 0 );
	$to   = (int) ( $c[ $which . '_to' ] ?? 0 );
	return $from && $to && $now >= $from && $now <= $to;
}

function eg_pub_promo_closure_handle( $set, $apply ) {
	$before = get_option( EG_PUB_PROMO_CLOSURE );
	if ( false === $set || null === $set ) {
		if ( $apply ) {
			delete_option( EG_PUB_PROMO_CLOSURE );
			if ( function_exists( 'rocket_clean_domain' ) ) {
				rocket_clean_domain();
			}
		}
		return new WP_REST_Response( array( 'ok' => true, 'applied' => $apply, 'before' => eg_pub_promo_closure_view( $before ), 'closure' => null ), 200 );
	}
	$c = array( 'notice' => array() );
	foreach ( array( 'notice_from', 'notice_to', 'closed_from', 'closed_to' ) as $key ) {
		$ts = eg_pub_promo_time( $set[ $key ] ?? '' );
		if ( ! empty( $set[ $key ] ) && null === $ts ) {
			return eg_pub_error( 'eg_promo_date', 'Fecha no válida en ' . $key . '.' );
		}
		$c[ $key ] = (int) $ts;
	}
	foreach ( (array) ( $set['notice'] ?? array() ) as $lang => $text ) {
		$c['notice'][ sanitize_key( $lang ) ] = wp_kses_post( $text );
	}
	if ( $c['notice_from'] && ! $c['notice'] ) {
		return eg_pub_error( 'eg_promo_notice', 'Falta el texto del aviso (notice) en algún idioma.' );
	}
	$out = array( 'ok' => true, 'applied' => $apply, 'before' => eg_pub_promo_closure_view( $before ), 'closure' => eg_pub_promo_closure_view( $c ) );
	if ( $apply ) {
		update_option( EG_PUB_PROMO_CLOSURE, $c, true );
		$out['purges'] = eg_pub_promo_schedule_purges( array( $c['notice_from'], $c['notice_to'], $c['closed_from'], $c['closed_to'] ) );
		if ( function_exists( 'rocket_clean_domain' ) && ( eg_pub_promo_in( $c, 'notice', time() ) || eg_pub_promo_in( $c, 'closed', time() ) ) ) {
			rocket_clean_domain();
		}
	}
	return new WP_REST_Response( $out, 200 );
}

/** Texto del aviso en el idioma de la página (o el primero que haya). */
function eg_pub_promo_notice_text( $c ) {
	$lang = (string) apply_filters( 'wpml_current_language', null );
	$all  = (array) ( $c['notice'] ?? array() );
	return $all[ $lang ] ?? ( $all ? reset( $all ) : '' );
}

add_action( 'plugins_loaded', function () {
	$c = get_option( EG_PUB_PROMO_CLOSURE );
	if ( ! is_array( $c ) || ! eg_pub_module_enabled( 'campanas' ) ) {
		return;
	}
	$now = time();
	if ( eg_pub_promo_in( $c, 'notice', $now ) ) {
		add_filter( 'pre_option_woocommerce_demo_store', function () {
			return 'yes';
		} );
		add_filter( 'pre_option_woocommerce_demo_store_notice', function () use ( $c ) {
			return eg_pub_promo_notice_text( $c );
		} );
	}
	if ( eg_pub_promo_in( $c, 'closed', $now ) && ! is_admin() ) {
		add_filter( 'woocommerce_is_purchasable', '__return_false', 99 );
		add_filter( 'woocommerce_variation_is_purchasable', '__return_false', 99 );
		add_action( 'woocommerce_checkout_process', function () use ( $c ) {
			wc_add_notice( wp_strip_all_tags( eg_pub_promo_notice_text( $c ) ), 'error' );
		} );
		add_action( 'woocommerce_single_product_summary', function () use ( $c ) {
			echo '<p class="eg-tienda-cerrada" style="font-weight:600">' . wp_kses_post( eg_pub_promo_notice_text( $c ) ) . '</p>';
		}, 31 );
	}
}, 20 );
