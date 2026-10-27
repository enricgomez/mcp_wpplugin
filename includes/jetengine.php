<?php
/**
 * Tipos de contenido y taxonomías de JetEngine (tipología «Gestión de la web»):
 *   POST /wp-json/eg/v1/cpt
 * Con dry_run (o sin nada que crear) lista los CPT y las taxonomías de JetEngine con sus campos.
 * `post_type` crea un CPT con sus campos con el mismo formato que la herramienta MCP «add-cpt» de JetEngine
 * (general_settings, labels, advanced_settings, meta_fields simplificados: name, title, type, options…).
 * `taxonomies` crea taxonomías de JetEngine (name, slug, object_type, hierarchical, labels) y sus `terms`.
 * Lo que ya existe (mismo slug) no se toca: se devuelve como `existing`.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_OPTION_FLUSH = 'eg_pub_flush_rewrite';

add_filter( 'eg_pub_modules', function ( $modules ) {
	$modules['jetengine'] = array( 'type' => 'gestion', 'routes' => array( 'cpt' ) );
	return $modules;
} );

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_module_enabled( 'jetengine' ) ) {
		return;
	}
	register_rest_route( 'eg/v1', '/cpt', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_cpt_handle',
		'permission_callback' => eg_pub_module_permission( 'manage_options' ),
	) );
} );

// Los CPT y taxonomías nuevos se registran en la petición siguiente: entonces se regeneran las URL.
add_action( 'init', function () {
	if ( get_option( EG_PUB_OPTION_FLUSH ) ) {
		delete_option( EG_PUB_OPTION_FLUSH );
		flush_rewrite_rules( false );
	}
}, 999 );

/** CPT y taxonomías de JetEngine: slug => resumen. */
function eg_pub_cpt_list() {
	$out = array( 'post_types' => array(), 'taxonomies' => array() );
	foreach ( (array) jet_engine()->cpt->data->get_items() as $item ) {
		$fields = jet_engine_safe_unserialize_array( $item['meta_fields'], 'eg_pub_cpt_list' );
		$labels = jet_engine_safe_unserialize_array( $item['labels'], 'eg_pub_cpt_list' );
		$out['post_types'][ $item['slug'] ] = array(
			'id'     => (int) $item['id'],
			'name'   => $labels['name'] ?? '',
			'fields' => array_values( array_map( function ( $f ) {
				return array( 'name' => $f['name'] ?? '', 'title' => $f['title'] ?? '', 'type' => $f['type'] ?? '' );
			}, array_filter( (array) $fields, function ( $f ) {
				return 'field' === ( $f['object_type'] ?? 'field' );
			} ) ) ),
		);
	}
	if ( ! empty( jet_engine()->taxonomies ) ) {
		foreach ( (array) jet_engine()->taxonomies->data->get_items() as $item ) {
			$labels = jet_engine_safe_unserialize_array( $item['labels'], 'eg_pub_cpt_list' );
			$out['taxonomies'][ $item['slug'] ] = array(
				'id'          => (int) $item['id'],
				'name'        => $labels['name'] ?? '',
				'object_type' => jet_engine_safe_unserialize_array( $item['object_type'], 'eg_pub_cpt_list' ),
			);
		}
	}
	return $out;
}

/** Crea el CPT con la normalización de la herramienta MCP de JetEngine (campos simplificados → campos de JetEngine). */
function eg_pub_cpt_create( $input ) {
	$tool = '\Jet_Engine\Post_Types\MCP\Tool_Add_CPT';
	if ( ! class_exists( $tool ) ) {
		return new WP_Error( 'eg_jet_version', 'Esta versión de JetEngine no trae la herramienta add-cpt; actualiza JetEngine.' );
	}
	// Sin constructor: solo registra la herramienta en el registro MCP de JetEngine, aquí no hace falta.
	$instance = ( new ReflectionClass( $tool ) )->newInstanceWithoutConstructor();
	return $instance->callback( $input );
}

function eg_pub_tax_create( $tax ) {
	$slug = sanitize_key( $tax['slug'] ?? sanitize_title( $tax['name'] ?? '' ) );
	$name = sanitize_text_field( $tax['name'] ?? '' );
	$objs = array_values( array_filter( array_map( 'sanitize_key', (array) ( $tax['object_type'] ?? array() ) ) ) );
	if ( ! $slug || ! $name || ! $objs ) {
		return new WP_Error( 'eg_tax_input', 'Cada taxonomía necesita name, slug y object_type.' );
	}
	$labels = array_map( 'sanitize_text_field', (array) ( $tax['labels'] ?? array() ) );
	$bools  = array( 'public' => true, 'publicly_queryable' => true, 'show_ui' => true, 'show_in_menu' => true,
		'show_in_nav_menus' => true, 'show_in_rest' => true, 'rewrite' => true, 'with_front' => true,
		'hierarchical' => false, 'rewrite_hierarchical' => false );
	$request = array_merge( $labels, array( 'name' => $name, 'slug' => $slug, 'object_type' => $objs, 'rewrite_slug' => $slug ) );
	foreach ( $bools as $key => $default ) {
		$request[ $key ] = isset( $tax[ $key ] ) ? (bool) $tax[ $key ] : $default;
	}
	jet_engine()->taxonomies->data->set_request( $request );
	try {
		$id = jet_engine()->taxonomies->data->create_item( false );
	} catch ( Exception $e ) {
		return new WP_Error( 'eg_tax_create', $e->getMessage() );
	}
	if ( ! $id ) {
		return new WP_Error( 'eg_tax_create', 'JetEngine no ha creado la taxonomía ' . $slug . '.' );
	}
	// La taxonomía se registra en la próxima carga; para crear sus términos ahora, se registra en esta petición.
	if ( ! taxonomy_exists( $slug ) ) {
		register_taxonomy( $slug, $objs, array( 'hierarchical' => $request['hierarchical'], 'public' => $request['public'] ) );
	}
	return (int) $id;
}

function eg_pub_tax_terms( $slug, $terms ) {
	$out = array();
	foreach ( (array) $terms as $term ) {
		$term_name = sanitize_text_field( is_array( $term ) ? ( $term['name'] ?? '' ) : $term );
		if ( '' === $term_name ) {
			continue;
		}
		$found = term_exists( $term_name, $slug );
		if ( $found ) {
			$out[ $term_name ] = (int) $found['term_id'];
			continue;
		}
		$args = is_array( $term ) && ! empty( $term['slug'] ) ? array( 'slug' => sanitize_title( $term['slug'] ) ) : array();
		$res  = wp_insert_term( $term_name, $slug, $args );
		$out[ $term_name ] = is_wp_error( $res ) ? $res->get_error_message() : (int) $res['term_id'];
	}
	return $out;
}

function eg_pub_cpt_handle( WP_REST_Request $r ) {
	if ( ! function_exists( 'jet_engine' ) || empty( jet_engine()->cpt ) ) {
		return eg_pub_error( 'eg_no_jetengine', 'JetEngine no está activo en esta web.' );
	}
	$p      = (array) $r->get_json_params();
	$before = eg_pub_cpt_list();
	if ( ! empty( $p['dry_run'] ) || ( empty( $p['post_type'] ) && empty( $p['taxonomies'] ) ) ) {
		return new WP_REST_Response( array( 'ok' => true, 'dry_run' => true ) + $before );
	}

	$result  = array( 'ok' => true, 'post_type' => null, 'taxonomies' => array() );
	$changed = false;

	if ( ! empty( $p['post_type'] ) && is_array( $p['post_type'] ) ) {
		$pt   = $p['post_type'];
		$slug = sanitize_title( $pt['general_settings']['slug'] ?? ( $pt['general_settings']['name'] ?? '' ) );
		if ( $slug && ( isset( $before['post_types'][ $slug ] ) || post_type_exists( $slug ) ) ) {
			$result['post_type'] = array( 'slug' => $slug, 'existing' => true ) + ( $before['post_types'][ $slug ] ?? array() );
		} else {
			$res = eg_pub_cpt_create( $pt );
			if ( is_wp_error( $res ) ) {
				return eg_pub_error( $res->get_error_code(), $res->get_error_message() );
			}
			if ( empty( $res['success'] ) ) {
				return new WP_REST_Response( array( 'ok' => false, 'code' => 'eg_cpt_create', 'message' => 'JetEngine no ha creado el CPT.', 'detail' => $res ), 400 );
			}
			$result['post_type'] = array( 'slug' => $slug, 'id' => (int) $res['item_id'], 'notices' => $res['notices'] ?? array() );
			$changed             = true;
		}
	}

	foreach ( (array) ( $p['taxonomies'] ?? array() ) as $tax ) {
		if ( ! is_array( $tax ) ) {
			continue;
		}
		$slug = sanitize_key( $tax['slug'] ?? sanitize_title( $tax['name'] ?? '' ) );
		$row  = array( 'slug' => $slug );
		if ( isset( $before['taxonomies'][ $slug ] ) || taxonomy_exists( $slug ) ) {
			$row['existing'] = true;
		} else {
			$id = eg_pub_tax_create( $tax );
			if ( is_wp_error( $id ) ) {
				$row['error'] = $id->get_error_message();
				$result['taxonomies'][] = $row;
				$result['ok'] = false;
				continue;
			}
			$row['id'] = $id;
			$changed   = true;
		}
		if ( ! empty( $tax['terms'] ) ) {
			$row['terms'] = eg_pub_tax_terms( $slug, $tax['terms'] );
		}
		$result['taxonomies'][] = $row;
	}

	if ( $changed ) {
		update_option( EG_PUB_OPTION_FLUSH, 1, false );
	}
	return new WP_REST_Response( $result, $result['ok'] ? 200 : 207 );
}
