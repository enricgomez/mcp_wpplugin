<?php
/**
 * Tipología «Gestión de la web»: limpiar scripts inyectados (malware) del contenido.
 *   POST /wp-json/eg/v1/cleanup
 *   signature  texto que identifica el script malicioso (mín. 8 caracteres), p. ej. el dominio al que redirige.
 *   revisions  true para incluir también las revisiones (por defecto no).
 * Busca bloques <script>…</script> que contengan la firma en el contenido de todas las entradas (cualquier tipo y
 * estado) y en los metadatos de texto plano (p. ej. _elementor_data), y lista dónde están. Las opciones que la
 * contienen solo se listan, nunca se tocan. Con apply:true quita esos bloques (solo los <script> con la firma, el
 * resto del contenido queda igual), sin cambiar la fecha de modificación, y vacía la caché de WP Rocket.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'eg_pub_modules', function ( $modules ) {
	$modules['limpieza'] = array( 'type' => 'gestion', 'routes' => array( 'cleanup' ) );
	return $modules;
} );

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_module_enabled( 'limpieza' ) ) {
		return;
	}
	register_rest_route( 'eg/v1', '/cleanup', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_cleanup_handle',
		'permission_callback' => eg_pub_module_permission( 'manage_options' ),
	) );
} );

/** Quita los bloques <script> que contienen la firma. Devuelve el texto nuevo y suma los bloques en $count. */
function eg_pub_cleanup_strip( $text, $signature, &$count ) {
	$sig = preg_quote( $signature, '/' );
	// Script con o sin barra escapada al cerrar (JSON de Elementor guarda <\/script>).
	$re  = '/<script\b[^>]*>(?:(?!<\\\\?\/script>).)*?' . $sig . '(?:(?!<\\\\?\/script>).)*?<\\\\?\/script>/is';
	$new = preg_replace( $re, '', $text, -1, $n );
	if ( null === $new ) {
		return $text;
	}
	$count += $n;
	return $new;
}

function eg_pub_cleanup_handle( WP_REST_Request $r ) {
	global $wpdb;
	$p         = (array) $r->get_json_params();
	$signature = isset( $p['signature'] ) && is_string( $p['signature'] ) ? trim( $p['signature'] ) : '';
	if ( mb_strlen( $signature ) < 8 ) {
		return new WP_Error( 'eg_signature', 'Falta "signature" (mín. 8 caracteres) que identifique el script.', array( 'status' => 400 ) );
	}
	$apply     = ! empty( $p['apply'] );
	$revisions = ! empty( $p['revisions'] );
	$like      = '%' . $wpdb->esc_like( $signature ) . '%';
	$out       = array( 'ok' => true, 'applied' => $apply, 'signature' => $signature, 'posts' => array(), 'meta' => array(), 'options' => array(), 'removed' => 0, 'left' => array() );

	$sql = "SELECT ID, post_type, post_status, post_title, post_content FROM {$wpdb->posts} WHERE post_content LIKE %s";
	if ( ! $revisions ) {
		$sql .= " AND post_type <> 'revision'";
	}
	$rows = $wpdb->get_results( $wpdb->prepare( $sql . ' LIMIT 2000', $like ) );
	foreach ( (array) $rows as $row ) {
		$n   = 0;
		$new = eg_pub_cleanup_strip( $row->post_content, $signature, $n );
		$item = array( 'id' => (int) $row->ID, 'type' => $row->post_type, 'status' => $row->post_status, 'title' => $row->post_title, 'scripts' => $n );
		if ( false !== stripos( $new, $signature ) ) {
			$item['still_after'] = true; // La firma sigue fuera de un <script>: revisar a mano.
			$out['left'][]       = array( 'where' => 'post_content', 'id' => (int) $row->ID );
		}
		if ( $apply && $n > 0 ) {
			$wpdb->update( $wpdb->posts, array( 'post_content' => $new ), array( 'ID' => (int) $row->ID ) );
			clean_post_cache( (int) $row->ID );
			$out['removed'] += $n;
		}
		$out['posts'][] = $item;
	}

	$metas = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_value LIKE %s LIMIT 2000", $like ) );
	foreach ( (array) $metas as $m ) {
		$item = array( 'meta_id' => (int) $m->meta_id, 'post_id' => (int) $m->post_id, 'key' => $m->meta_key, 'scripts' => 0 );
		if ( is_serialized( $m->meta_value ) ) {
			$item['serialized']   = true; // No se toca: cambiar la longitud rompería el serializado.
			$out['left'][]        = array( 'where' => 'postmeta', 'id' => (int) $m->meta_id );
			$out['meta'][]        = $item;
			continue;
		}
		$n   = 0;
		$new = eg_pub_cleanup_strip( $m->meta_value, $signature, $n );
		$item['scripts'] = $n;
		if ( false !== stripos( $new, $signature ) ) {
			$item['still_after'] = true;
			$out['left'][]       = array( 'where' => 'postmeta', 'id' => (int) $m->meta_id );
		}
		if ( $apply && $n > 0 ) {
			$wpdb->update( $wpdb->postmeta, array( 'meta_value' => $new ), array( 'meta_id' => (int) $m->meta_id ) );
			wp_cache_delete( (int) $m->post_id, 'post_meta' );
			$out['removed'] += $n;
		}
		$out['meta'][] = $item;
	}

	$opts = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_value LIKE %s LIMIT 200", $like ) );
	$out['options'] = array_values( (array) $opts );

	if ( $apply && $out['removed'] > 0 && function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
	}
	return rest_ensure_response( $out );
}
