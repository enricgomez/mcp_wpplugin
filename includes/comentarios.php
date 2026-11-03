<?php
/**
 * Tipología «Gestión de la web»: revisar comentarios, pingbacks y trackbacks.
 *   POST /wp-json/eg/v1/comments
 * Lista los más recientes de cualquier estado (pendientes incluidos), con autor, URL de origen, IP,
 * entrada a la que apuntan y texto. Filtros opcionales: type (comment, pingback, trackback, pings),
 * status (hold, approve, spam, trash, all; all por defecto), post_id, limit (20 por defecto, máx. 100).
 * Solo lectura: no aprueba, no marca spam ni borra.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'eg_pub_modules', function ( $modules ) {
	$modules['comentarios'] = array( 'type' => 'gestion', 'routes' => array( 'comments' ) );
	return $modules;
} );

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_module_enabled( 'comentarios' ) ) {
		return;
	}
	register_rest_route( 'eg/v1', '/comments', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_comments_handle',
		'permission_callback' => 'eg_pub_permission',
	) );
} );

function eg_pub_comments_handle( WP_REST_Request $r ) {
	$p      = $r->get_params();
	$status = sanitize_key( $p['status'] ?? 'all' );
	if ( ! in_array( $status, array( 'all', 'hold', 'approve', 'spam', 'trash' ), true ) ) {
		return eg_pub_error( 'eg_status', 'status no válido: ' . $status );
	}
	$args = array(
		'status'  => $status,
		'number'  => max( 1, min( 100, (int) ( $p['limit'] ?? 20 ) ) ),
		'orderby' => 'comment_date_gmt',
		'order'   => 'DESC',
	);
	if ( ! empty( $p['type'] ) ) {
		$type = sanitize_key( $p['type'] );
		if ( ! in_array( $type, array( 'comment', 'pingback', 'trackback', 'pings' ), true ) ) {
			return eg_pub_error( 'eg_type', 'type no válido: ' . $type );
		}
		$args['type'] = $type;
	}
	if ( ! empty( $p['post_id'] ) ) {
		$args['post_id'] = (int) $p['post_id'];
	}

	$items = array();
	foreach ( get_comments( $args ) as $c ) {
		$items[] = array(
			'id'         => (int) $c->comment_ID,
			'type'       => $c->comment_type ? $c->comment_type : 'comment',
			'status'     => wp_get_comment_status( $c ),
			'date'       => $c->comment_date,
			'author'     => $c->comment_author,
			'author_url' => $c->comment_author_url,
			'ip'         => $c->comment_author_IP,
			'agent'      => $c->comment_agent,
			'post_id'    => (int) $c->comment_post_ID,
			'post_title' => get_the_title( $c->comment_post_ID ),
			'post_url'   => get_permalink( $c->comment_post_ID ),
			'content'    => wp_strip_all_tags( $c->comment_content ),
		);
	}

	return new WP_REST_Response( array(
		'ok'         => true,
		'count'      => count( $items ),
		'pings_open' => 'open' === get_option( 'default_ping_status' ),
		'comments'   => $items,
	), 200 );
}
