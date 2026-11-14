<?php
/**
 * Tipología «Gestión de la web»: revisar y limpiar comentarios, pingbacks y trackbacks.
 *   POST /wp-json/eg/v1/comments
 * Lista los más recientes de cualquier estado (pendientes incluidos), con autor, URL de origen, IP,
 * entrada a la que apuntan y texto. Filtros opcionales: type (comment, pingback, trackback, pings),
 * status (hold, approve, spam, trash, all; all por defecto), post_id, limit (20 por defecto, máx. 100).
 * Acciones (solo previsualizan salvo apply:true):
 *   trash   [ids]  manda esos comentarios a la papelera
 *   pings   false  desactiva los pingbacks y trackbacks: deja de avisar a los blogs enlazados, no acepta los que
 *                  lleguen a entradas nuevas y los cierra en las que ya existen
 *   comments false cierra los comentarios en toda la web: los cierra en las entradas y páginas que ya existen, las
 *                  nuevas nacen cerradas, y mientras siga así WordPress no acepta ninguno (formulario, REST ni
 *                  XML-RPC) y no pinta el formulario ni los comentarios. true quita el bloqueo (las entradas siguen
 *                  cerradas hasta que se abran a mano).
 *   trash_status "hold"|"spam" manda a la papelera todos los comentarios en ese estado (máx. 500 por llamada).
 */

const EG_PUB_COMMENTS_OFF = 'eg_pub_comments_off';

// Bloqueo de comentarios en toda la web (comments:false).
if ( get_option( EG_PUB_COMMENTS_OFF ) ) {
	add_filter( 'comments_open', '__return_false', 99 );
	add_filter( 'pings_open', '__return_false', 99 );
	add_filter( 'comments_array', '__return_empty_array', 99 );
	add_filter( 'rest_allow_anonymous_comments', '__return_false', 99 );
	add_filter( 'xmlrpc_methods', function ( $methods ) {
		unset( $methods['pingback.ping'], $methods['wp.newComment'] );
		return $methods;
	} );
	add_action( 'pre_comment_on_post', function () {
		wp_die( esc_html__( 'Comments are closed.' ), '', array( 'response' => 403 ) );
	} );
}

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

	$apply   = ! empty( $p['apply'] );
	$changes = array();
	foreach ( array_unique( array_map( 'intval', (array) ( $p['trash'] ?? array() ) ) ) as $cid ) {
		if ( ! $cid || ! get_comment( $cid ) ) {
			$changes[] = array( 'trash' => $cid, 'ok' => false, 'message' => 'no existe' );
			continue;
		}
		$changes[] = array( 'trash' => $cid, 'ok' => $apply ? (bool) wp_trash_comment( $cid ) : null );
	}
	if ( isset( $p['pings'] ) && false === filter_var( $p['pings'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE ) ) {
		global $wpdb;
		$open = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ping_status = 'open'" );
		if ( $apply ) {
			update_option( 'default_pingback_flag', '' );
			update_option( 'default_ping_status', 'closed' );
			$wpdb->query( "UPDATE {$wpdb->posts} SET ping_status = 'closed' WHERE ping_status = 'open'" );
			wp_cache_flush();
		}
		$changes[] = array( 'pings' => 'off', 'posts_closed' => $open, 'ok' => $apply ? true : null );
	}

	if ( isset( $p['comments'] ) ) {
		$on = filter_var( $p['comments'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		if ( false === $on ) {
			global $wpdb;
			$open = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE comment_status = 'open'" );
			if ( $apply ) {
				update_option( 'default_comment_status', 'closed' );
				update_option( EG_PUB_COMMENTS_OFF, 1 );
				$wpdb->query( "UPDATE {$wpdb->posts} SET comment_status = 'closed' WHERE comment_status = 'open'" );
				wp_cache_flush();
				if ( function_exists( 'rocket_clean_domain' ) ) {
					rocket_clean_domain();
				}
			}
			$changes[] = array( 'comments' => 'off', 'posts_closed' => $open, 'ok' => $apply ? true : null );
		} elseif ( true === $on ) {
			if ( $apply ) {
				delete_option( EG_PUB_COMMENTS_OFF );
			}
			$changes[] = array( 'comments' => 'block_removed', 'ok' => $apply ? true : null );
		}
	}
	if ( ! empty( $p['trash_status'] ) ) {
		$ts = sanitize_key( $p['trash_status'] );
		if ( ! in_array( $ts, array( 'hold', 'spam' ), true ) ) {
			return eg_pub_error( 'eg_status', 'trash_status debe ser hold o spam.' );
		}
		$ids = get_comments( array( 'status' => $ts, 'number' => 500, 'fields' => 'ids' ) );
		$done = 0;
		if ( $apply ) {
			foreach ( $ids as $cid ) {
				$done += wp_trash_comment( $cid ) ? 1 : 0;
			}
		}
		$changes[] = array( 'trash_status' => $ts, 'found' => count( $ids ), 'trashed' => $apply ? $done : null );
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
		'applied'    => $apply && $changes,
		'changes'    => $changes,
		'count'      => count( $items ),
		'pings_open' => 'open' === get_option( 'default_ping_status' ),
		'comments_blocked' => (bool) get_option( EG_PUB_COMMENTS_OFF ),
		'comments_default' => get_option( 'default_comment_status' ),
		'comments'   => $items,
	), 200 );
}
