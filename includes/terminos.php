<?php
/**
 * Tipología «Gestión de la web»: textos y SEO de categorías y otros términos públicos.
 *   POST /wp-json/eg/v1/term
 * Elige el término por taxonomy (product_cat por defecto) + slug o id. Los cambios van en set:
 *   name                 nombre visible de la categoría
 *   description          descripción (HTML permitido en entradas: p, h2, h3, ul, li, strong, a...)
 *   seo_title, seo_description, focus_keyword   SEO del archivo de la categoría (Rank Math y/o Yoast)
 * Como /seo, por defecto solo previsualiza; apply:true guarda. No cambia el slug (las URLs no se mueven).
 * Vacía la caché del archivo en WP Rocket.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'eg_pub_modules', function ( $modules ) {
	$modules['terminos'] = array( 'type' => 'gestion', 'routes' => array( 'term' ) );
	return $modules;
} );

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_module_enabled( 'terminos' ) ) {
		return;
	}
	register_rest_route( 'eg/v1', '/term', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_term_handle',
		'permission_callback' => 'eg_pub_permission',
	) );
} );

/** SEO del término: Rank Math en term meta; Yoast en la opción wpseo_taxonomy_meta. */
function eg_pub_term_seo_get( $term ) {
	$values = array( 'seo_title' => '', 'seo_description' => '', 'focus_keyword' => '' );
	if ( defined( 'RANK_MATH_VERSION' ) ) {
		$values['seo_title']       = (string) get_term_meta( $term->term_id, 'rank_math_title', true );
		$values['seo_description'] = (string) get_term_meta( $term->term_id, 'rank_math_description', true );
		$values['focus_keyword']   = (string) get_term_meta( $term->term_id, 'rank_math_focus_keyword', true );
	} elseif ( defined( 'WPSEO_VERSION' ) ) {
		$all = get_option( 'wpseo_taxonomy_meta', array() );
		$row = $all[ $term->taxonomy ][ $term->term_id ] ?? array();
		$values['seo_title']       = (string) ( $row['wpseo_title'] ?? '' );
		$values['seo_description'] = (string) ( $row['wpseo_desc'] ?? '' );
		$values['focus_keyword']   = (string) ( $row['wpseo_focuskw'] ?? '' );
	}
	return $values;
}

function eg_pub_term_seo_save( $term, $values ) {
	if ( defined( 'RANK_MATH_VERSION' ) ) {
		$keys = array( 'seo_title' => 'rank_math_title', 'seo_description' => 'rank_math_description', 'focus_keyword' => 'rank_math_focus_keyword' );
		foreach ( $keys as $field => $key ) {
			if ( isset( $values[ $field ] ) ) {
				update_term_meta( $term->term_id, $key, $values[ $field ] );
			}
		}
	}
	if ( defined( 'WPSEO_VERSION' ) ) {
		$keys = array( 'seo_title' => 'wpseo_title', 'seo_description' => 'wpseo_desc', 'focus_keyword' => 'wpseo_focuskw' );
		$all  = get_option( 'wpseo_taxonomy_meta', array() );
		$all  = is_array( $all ) ? $all : array();
		foreach ( $keys as $field => $key ) {
			if ( isset( $values[ $field ] ) ) {
				$all[ $term->taxonomy ][ $term->term_id ][ $key ] = $values[ $field ];
			}
		}
		update_option( 'wpseo_taxonomy_meta', $all );
	}
}

function eg_pub_term_handle( WP_REST_Request $r ) {
	$p        = $r->get_params();
	$apply    = ! empty( $p['apply'] );
	$taxonomy = sanitize_key( $p['taxonomy'] ?? 'product_cat' );
	$tax      = get_taxonomy( $taxonomy );
	if ( ! $tax || ! $tax->public ) {
		return eg_pub_error( 'eg_taxonomy', 'Taxonomía no válida o no pública: ' . $taxonomy );
	}
	if ( ! current_user_can( $tax->cap->edit_terms ) ) {
		return eg_pub_error( 'eg_forbidden', 'El autor configurado no puede editar términos de ' . $taxonomy . '.', 403 );
	}
	$term = ! empty( $p['id'] ) ? get_term( (int) $p['id'], $taxonomy ) : get_term_by( 'slug', sanitize_title( (string) ( $p['slug'] ?? '' ) ), $taxonomy );
	if ( ! $term || is_wp_error( $term ) ) {
		return eg_pub_error( 'eg_term', 'No existe el término (indica slug o id) en ' . $taxonomy . '.', 404 );
	}

	$set     = (array) ( $p['set'] ?? array() );
	$changes = array();
	foreach ( array( 'name', 'description' ) as $field ) {
		if ( ! array_key_exists( $field, $set ) || ! is_scalar( $set[ $field ] ) ) {
			continue;
		}
		$new = 'name' === $field ? sanitize_text_field( (string) $set[ $field ] ) : wp_kses_post( (string) $set[ $field ] );
		if ( $new !== $term->$field ) {
			$changes[ $field ] = array( 'before' => $term->$field, 'after' => $new );
		}
	}
	$seo = eg_pub_term_seo_get( $term );
	foreach ( $seo as $field => $old ) {
		if ( ! array_key_exists( $field, $set ) || ! is_scalar( $set[ $field ] ) ) {
			continue;
		}
		$new = sanitize_text_field( (string) $set[ $field ] );
		if ( $new !== $old ) {
			$changes[ $field ] = array( 'before' => $old, 'after' => $new );
			$seo[ $field ]     = $new;
		}
	}
	if ( isset( $changes['seo_title'] ) || isset( $changes['seo_description'] ) || isset( $changes['focus_keyword'] ) ) {
		if ( ! defined( 'RANK_MATH_VERSION' ) && ! defined( 'WPSEO_VERSION' ) ) {
			return eg_pub_error( 'eg_seo_plugin', 'No hay Yoast SEO ni Rank Math activos.' );
		}
	}

	if ( $apply && $changes ) {
		$args = array();
		foreach ( array( 'name', 'description' ) as $field ) {
			if ( isset( $changes[ $field ] ) ) {
				$args[ $field ] = $changes[ $field ]['after'];
			}
		}
		if ( $args ) {
			// Sin esto, wp_update_term pasa la descripción por wp_filter_kses y quita los h2/h3 a quien no tiene unfiltered_html.
			$kses = has_filter( 'pre_term_description', 'wp_filter_kses' );
			if ( $kses ) {
				remove_filter( 'pre_term_description', 'wp_filter_kses' );
			}
			$done = wp_update_term( $term->term_id, $taxonomy, $args );
			if ( $kses ) {
				add_filter( 'pre_term_description', 'wp_filter_kses' );
			}
			if ( is_wp_error( $done ) ) {
				return eg_pub_error( 'eg_term_save', $done->get_error_message() );
			}
		}
		eg_pub_term_seo_save( $term, array_map( function ( $c ) { return $c['after']; }, array_intersect_key( $changes, $seo ) ) );
		if ( function_exists( 'rocket_clean_term' ) ) {
			rocket_clean_term( $term->term_id, $taxonomy );
		}
		$term = get_term( $term->term_id, $taxonomy );
	}

	return new WP_REST_Response( array(
		'ok'          => true,
		'applied'     => $apply,
		'id'          => $term->term_id,
		'taxonomy'    => $taxonomy,
		'slug'        => $term->slug,
		'link'        => get_term_link( $term ),
		'name'        => $term->name,
		'description' => $term->description,
		'seo'         => $seo,
		'changes'     => $changes,
	), 200 );
}
