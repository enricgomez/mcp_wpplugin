<?php
/**
 * Cambiar el estado de páginas, entradas o proyectos (tipología «Gestión de la web»):
 *   POST /wp-json/eg/v1/status
 * Elige las entradas por ruta (paths), por ID (ids) o por términos de una taxonomía (post_type + terms), con
 * sus traducciones de WPML si se pide. Con dry_run solo las lista con su estado actual. El cambio se guarda
 * sin wp_update_post para que WPML no lance la traducción automática del resto de idiomas.
 * Con gone (410, o {"code":301,"to":"/ruta/"}) guarda además, en cada entrada publicada que se despublica, la
 * redirección de la URL que tenía (en su idioma), con el mismo sistema que /redirect. Sirve para dar de baja una
 * página en todos los idiomas con una sola llamada: {"paths":["/ruta/"],"with_translations":true,"status":"draft","gone":410}.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'eg_pub_modules', function ( $modules ) {
	$modules['contenido'] = array( 'type' => 'gestion', 'routes' => array( 'status' ) );
	return $modules;
} );

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_module_enabled( 'contenido' ) ) {
		return;
	}
	register_rest_route( 'eg/v1', '/status', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_status_handle',
		'permission_callback' => eg_pub_module_permission( 'edit_others_pages' ),
	) );
} );

/** ID de la entrada publicada (o no) en esa ruta, en cualquier idioma. 0 si no hay. */
function eg_pub_status_find_path( $path ) {
	$path = '/' . trim( (string) wp_parse_url( $path, PHP_URL_PATH ), '/' ) . '/';
	$id   = url_to_postid( home_url( $path ) );
	if ( $id ) {
		return (int) $id;
	}
	// Borradores y rutas con prefijo de idioma: buscar por la ruta sin el prefijo.
	$parts = explode( '/', trim( $path, '/' ) );
	$langs = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
	if ( is_array( $langs ) && count( $parts ) > 1 && isset( $langs[ $parts[0] ] ) ) {
		array_shift( $parts );
	}
	$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );
	$page  = get_page_by_path( implode( '/', $parts ), OBJECT, $types );
	return $page ? (int) $page->ID : 0;
}

/**
 * Guarda la redirección de una URL que se despublica con el mismo /redirect del plugin (Rank Math, Redirection o
 * la lista propia de EG Publicación). Devuelve from, code y ok, o el error.
 */
function eg_pub_status_gone( $url, $gone ) {
	$req = new WP_REST_Request( 'POST', '/eg/v1/redirect' );
	$req->set_param( 'from', $url );
	$req->set_param( 'to', ! empty( $gone['to'] ) ? (string) $gone['to'] : '/' );
	$req->set_param( 'code', (int) $gone['code'] );
	$res = eg_pub_redirect_handle( $req );
	if ( is_wp_error( $res ) ) {
		return array( 'ok' => false, 'from' => $url, 'error' => $res->get_error_message() );
	}
	$data = $res instanceof WP_REST_Response ? $res->get_data() : (array) $res;
	return array( 'ok' => ! empty( $data['ok'] ), 'from' => $data['from'] ?? $url, 'code' => (int) $gone['code'], 'plugin' => $data['plugin'] ?? '', 'message' => $data['message'] ?? '' );
}

function eg_pub_status_handle( WP_REST_Request $r ) {
	$p       = $r->get_params();
	$status  = sanitize_key( $p['status'] ?? '' );
	$dry_run = ! empty( $p['dry_run'] );
	if ( ! $dry_run && ! in_array( $status, array( 'draft', 'publish', 'private', 'pending' ), true ) ) {
		return eg_pub_error( 'eg_status', 'status debe ser draft, publish, private o pending (o usar dry_run).' );
	}

	// gone: 410 (o 301/302 con to) para las URL de las entradas que se despublican.
	$gone = null;
	if ( isset( $p['gone'] ) && '' !== $p['gone'] && false !== $p['gone'] ) {
		$gone = is_array( $p['gone'] ) ? $p['gone'] : array( 'code' => (int) $p['gone'] );
		$gone['code'] = (int) ( $gone['code'] ?? 410 );
		$gone['code'] = $gone['code'] ? $gone['code'] : 410;
		if ( ! function_exists( 'eg_pub_redirect_handle' ) ) {
			return eg_pub_error( 'eg_gone', 'gone necesita el módulo de redirecciones activo.', 409 );
		}
		if ( ! in_array( $gone['code'], array( 410, 451 ), true ) && empty( $gone['to'] ) ) {
			return eg_pub_error( 'eg_gone', 'gone con código ' . $gone['code'] . ' necesita to.' );
		}
	}

	$ids   = array();
	$notes = array();
	$paths = array();
	foreach ( (array) ( $p['paths'] ?? array() ) as $path ) {
		$id             = eg_pub_status_find_path( $path );
		$paths[ $path ] = $id;
		if ( $id ) {
			$ids[] = $id;
		} else {
			$notes[] = 'no encuentro ' . $path;
		}
	}
	foreach ( (array) ( $p['ids'] ?? array() ) as $id ) {
		$ids[] = (int) $id;
	}
	// Por términos: {"categoria-proyecto": ["banos", "obra-varios"]}, menos los que tengan exclude_terms.
	if ( ! empty( $p['terms'] ) && is_array( $p['terms'] ) ) {
		$tax_query = array( 'relation' => 'OR' );
		foreach ( $p['terms'] as $tax => $slugs ) {
			$tax_query[] = array( 'taxonomy' => sanitize_key( $tax ), 'field' => 'slug', 'terms' => array_map( 'sanitize_title', (array) $slugs ) );
		}
		$query = array(
			'post_type'        => sanitize_key( $p['post_type'] ?? 'post' ),
			'post_status'      => 'any',
			'numberposts'      => -1,
			'fields'           => 'ids',
			'tax_query'        => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery
			'suppress_filters' => true,
		);
		if ( ! empty( $p['exclude_terms'] ) && is_array( $p['exclude_terms'] ) ) {
			$query['tax_query'] = array( 'relation' => 'AND', $tax_query );
			foreach ( $p['exclude_terms'] as $tax => $slugs ) {
				$query['tax_query'][] = array( 'taxonomy' => sanitize_key( $tax ), 'field' => 'slug', 'terms' => array_map( 'sanitize_title', (array) $slugs ), 'operator' => 'NOT IN' );
			}
		}
		$ids = array_merge( $ids, array_map( 'intval', get_posts( $query ) ) );
	}
	// Añadir las traducciones de WPML de cada una.
	if ( ! empty( $p['with_translations'] ) ) {
		foreach ( $ids as $id ) {
			$type = apply_filters( 'wpml_element_type', get_post_type( $id ) );
			$trid = apply_filters( 'wpml_element_trid', null, $id, $type );
			foreach ( (array) apply_filters( 'wpml_get_element_translations', null, $trid, $type ) as $tr ) {
				if ( ! empty( $tr->element_id ) ) {
					$ids[] = (int) $tr->element_id;
				}
			}
		}
	}
	$ids = array_values( array_unique( array_filter( $ids ) ) );
	if ( ! $ids ) {
		return eg_pub_error( 'eg_missing', 'No hay ninguna entrada: indica paths, ids o post_type + terms. ' . implode( ' | ', $notes ), 404 );
	}

	$done = array();
	foreach ( $ids as $id ) {
		$post = get_post( $id );
		if ( ! $post ) {
			$notes[] = 'no existe ' . $id;
			continue;
		}
		$lang = apply_filters( 'wpml_post_language_details', null, $id );
		$row  = array(
			'id'     => $id,
			'type'   => $post->post_type,
			'lang'   => is_array( $lang ) ? ( $lang['language_code'] ?? '' ) : '',
			'title'  => $post->post_title,
			'url'    => get_permalink( $id ),
			'status' => $post->post_status,
		);
		if ( ! $dry_run && $post->post_status !== $status ) {
			// gone: al despublicar, guarda una redirección (o un 410) en la URL que tenía cada entrada publicada.
			if ( null !== $gone && 'publish' === $post->post_status && 'publish' !== $status ) {
				$row['redirect'] = eg_pub_status_gone( $row['url'], $gone );
			}
			global $wpdb;
			$wpdb->update( $wpdb->posts, array( 'post_status' => $status ), array( 'ID' => $id ) );
			clean_post_cache( $id );
			wp_transition_post_status( $status, $post->post_status, get_post( $id ) );
			$row['status']   = $status;
			$row['previous'] = $post->post_status;
		}
		$done[] = $row;
	}

	if ( ! $dry_run && function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
	}
	return new WP_REST_Response( array(
		'ok'      => true,
		'dry_run' => $dry_run,
		'count'   => count( $done ),
		'posts'   => $done,
		'paths'   => $paths,
		'message' => implode( ' | ', $notes ),
	), 200 );
}
