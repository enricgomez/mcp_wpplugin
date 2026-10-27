<?php
/**
 * Tipos de contenido y taxonomías de JetEngine (tipología «Gestión de la web»):
 *   POST /wp-json/eg/v1/cpt
 * Con dry_run (o sin nada que crear) lista los CPT y las taxonomías de JetEngine con sus campos.
 * `post_type` crea un CPT con sus campos con el mismo formato que la herramienta MCP «add-cpt» de JetEngine
 * (general_settings, labels, advanced_settings, meta_fields simplificados: name, title, type, options…).
 * `taxonomies` crea taxonomías de JetEngine (name, slug, object_type, hierarchical, labels) y sus `terms`.
 * Lo que ya existe (mismo slug) no se toca: se devuelve como `existing`.
 * `entries` crea o actualiza (por post_type + slug) entradas de un CPT de JetEngine: title, slug, content, excerpt,
 * status, menu_order, featured_image (ID), terms ({"taxonomía": ["Término", …]}) y meta ({"campo": valor}). Las
 * fechas se pasan como AAAA-MM-DD y se guardan como timestamp si el campo es de timestamp; las galerías como lista
 * de IDs; los switcher como true/false.
 * `listings` crea o actualiza (por key) plantillas de listado de JetEngine hechas con Elementor para un CPT: key, title,
 * post_type, elements (JSON de Elementor del elemento del listado) y settings (ajustes de página opcionales). El ID que
 * devuelve es el que se pone en `lisitng_id` del widget Listing Grid.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_OPTION_FLUSH = 'eg_pub_flush_rewrite';
const EG_PUB_META_LISTING_KEY = '_eg_pub_listing_key';

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
	$out['listings'] = array();
	foreach ( get_posts( array( 'post_type' => 'jet-engine', 'post_status' => 'any', 'numberposts' => 100, 'suppress_filters' => true ) ) as $listing ) {
		$data = (array) get_post_meta( $listing->ID, '_listing_data', true );
		$out['listings'][] = array( 'id' => $listing->ID, 'title' => $listing->post_title, 'post_type' => $data['post_type'] ?? '', 'key' => (string) get_post_meta( $listing->ID, EG_PUB_META_LISTING_KEY, true ) );
	}
	// Con los ajustes antiguos desactivados, el Listing Grid ignora sus filtros propios (posts_query) y hay que usar el Query Builder.
	$out['listing_legacy_disabled'] = isset( jet_engine()->listings->legacy ) ? (bool) jet_engine()->listings->legacy->is_disabled() : null;
	return $out;
}

/** Crea el CPT con la normalización de la herramienta MCP de JetEngine (campos simplificados → campos de JetEngine). */
function eg_pub_cpt_create( $input ) {
	$tool = '\Jet_Engine\Post_Types\MCP\Tool_Add_CPT';
	// JetEngine solo carga sus herramientas MCP cuando arranca su servidor MCP: si no está cargada, se carga aquí.
	$file = trailingslashit( dirname( ( new ReflectionClass( 'Jet_Engine' ) )->getFileName() ) ) . 'includes/components/post-types/mcp/tool-add-cpt.php';
	if ( ! class_exists( $tool, false ) && file_exists( $file ) ) {
		require_once $file;
	}
	if ( ! class_exists( $tool, false ) ) {
		return new WP_Error( 'eg_jet_version', 'Esta versión de JetEngine no trae la herramienta add-cpt (' . $file . ( file_exists( $file ) ? ', existe' : ', no existe' ) . '); actualiza JetEngine.' );
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
	if ( ! empty( $p['dry_run'] ) || ( empty( $p['post_type'] ) && empty( $p['taxonomies'] ) && empty( $p['entries'] ) && empty( $p['listings'] ) ) ) {
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

	if ( ! empty( $p['entries'] ) ) {
		$result['entries'] = array();
		foreach ( (array) $p['entries'] as $entry ) {
			$row = is_array( $entry ) ? eg_pub_cpt_entry( $entry ) : new WP_Error( 'eg_entry_input', 'Entrada no válida.' );
			if ( is_wp_error( $row ) ) {
				$row          = array( 'slug' => $entry['slug'] ?? '', 'error' => $row->get_error_message() );
				$result['ok'] = false;
			}
			$result['entries'][] = $row;
		}
	}

	if ( ! empty( $p['listings'] ) ) {
		$result['listings'] = array();
		foreach ( (array) $p['listings'] as $listing ) {
			$row = is_array( $listing ) ? eg_pub_cpt_listing( $listing ) : new WP_Error( 'eg_listing_input', 'Listado no válido.' );
			if ( is_wp_error( $row ) ) {
				$row          = array( 'key' => $listing['key'] ?? '', 'error' => $row->get_error_message() );
				$result['ok'] = false;
			}
			$result['listings'][] = $row;
		}
	}

	if ( $changed ) {
		update_option( EG_PUB_OPTION_FLUSH, 1, false );
	}
	return new WP_REST_Response( $result, $result['ok'] ? 200 : 207 );
}

/** Campos de JetEngine de un CPT: nombre => definición. */
function eg_pub_cpt_fields( $post_type ) {
	foreach ( (array) jet_engine()->cpt->data->get_items() as $item ) {
		if ( $item['slug'] === $post_type ) {
			$fields = (array) jet_engine_safe_unserialize_array( $item['meta_fields'], 'eg_pub_cpt_fields' );
			return array_column( $fields, null, 'name' );
		}
	}
	return array();
}

function eg_pub_cpt_meta_value( $field, $value ) {
	$type = $field['type'] ?? 'text';
	if ( in_array( $type, array( 'date', 'datetime', 'datetime-local' ), true ) && ! empty( $field['is_timestamp'] ) && ! is_numeric( $value ) ) {
		return '' === (string) $value ? '' : (string) strtotime( $value . ( 'date' === $type ? ' 00:00:00' : '' ) . ' UTC' );
	}
	if ( 'gallery' === $type ) {
		return implode( ',', array_map( 'absint', (array) $value ) );
	}
	if ( 'switcher' === $type ) {
		return filter_var( $value, FILTER_VALIDATE_BOOLEAN ) ? 'true' : 'false';
	}
	if ( 'number' === $type ) {
		return '' === (string) $value ? '' : (string) ( 0 + $value );
	}
	return is_array( $value ) ? $value : wp_kses_post( (string) $value );
}

/** Crea o actualiza una entrada de un CPT con sus campos de JetEngine y sus términos. */
function eg_pub_cpt_entry( $e ) {
	$type = sanitize_key( $e['post_type'] ?? '' );
	if ( ! $type || ! post_type_exists( $type ) ) {
		return new WP_Error( 'eg_entry_type', 'El tipo de contenido «' . $type . '» no existe.' );
	}
	$slug  = sanitize_title( $e['slug'] ?? ( $e['title'] ?? '' ) );
	$found = $slug ? get_posts( array( 'post_type' => $type, 'name' => $slug, 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'suppress_filters' => true ) ) : array();
	$data  = array( 'post_type' => $type );
	foreach ( array( 'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'status' => 'post_status', 'menu_order' => 'menu_order' ) as $in => $col ) {
		if ( isset( $e[ $in ] ) ) {
			$data[ $col ] = 'post_title' === $col ? sanitize_text_field( $e[ $in ] ) : ( 'menu_order' === $col ? (int) $e[ $in ] : ( 'post_status' === $col ? sanitize_key( $e[ $in ] ) : wp_kses_post( $e[ $in ] ) ) );
		}
	}
	if ( $found ) {
		$data['ID'] = (int) $found[0];
		$id         = wp_update_post( wp_slash( $data ), true );
	} else {
		$data['post_name']   = $slug;
		$data['post_status'] = $data['post_status'] ?? 'publish';
		$id                  = wp_insert_post( wp_slash( $data ), true );
	}
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	$fields  = eg_pub_cpt_fields( $type );
	$unknown = array();
	foreach ( (array) ( $e['meta'] ?? array() ) as $key => $value ) {
		$key = sanitize_key( $key );
		if ( ! isset( $fields[ $key ] ) ) {
			$unknown[] = $key;
			continue;
		}
		update_post_meta( $id, $key, eg_pub_cpt_meta_value( $fields[ $key ], $value ) );
	}
	foreach ( (array) ( $e['terms'] ?? array() ) as $tax => $names ) {
		$tax = sanitize_key( $tax );
		if ( taxonomy_exists( $tax ) ) {
			wp_set_object_terms( $id, array_map( 'sanitize_text_field', (array) $names ), $tax );
		}
	}
	if ( ! empty( $e['featured_image'] ) ) {
		set_post_thumbnail( $id, absint( $e['featured_image'] ) );
	}
	return array( 'slug' => get_post_field( 'post_name', $id ), 'id' => (int) $id, 'created' => ! $found, 'link' => get_permalink( $id ) ) + ( $unknown ? array( 'unknown_meta' => $unknown ) : array() );
}

/** Crea o actualiza (por key) una plantilla de listado de JetEngine hecha con Elementor para las entradas de un CPT. */
function eg_pub_cpt_listing( $l ) {
	$key       = sanitize_key( $l['key'] ?? '' );
	$title     = sanitize_text_field( $l['title'] ?? '' );
	$post_type = sanitize_key( $l['post_type'] ?? '' );
	$elements  = $l['elements'] ?? null;
	if ( ! $key || ! $title || ! $post_type || ! is_array( $elements ) || ! $elements ) {
		return new WP_Error( 'eg_listing_input', 'Cada listado necesita key, title, post_type y elements (JSON de Elementor).' );
	}
	if ( ! post_type_exists( $post_type ) ) {
		return new WP_Error( 'eg_listing_type', 'El tipo de contenido «' . $post_type . '» no existe.' );
	}
	$found = get_posts( array( 'post_type' => 'jet-engine', 'post_status' => 'any', 'meta_key' => EG_PUB_META_LISTING_KEY, 'meta_value' => $key, 'numberposts' => 1, 'fields' => 'ids', 'suppress_filters' => true ) );
	$data  = array( 'post_type' => 'jet-engine', 'post_title' => $title, 'post_status' => 'publish' );
	if ( $found ) {
		$data['ID'] = (int) $found[0];
		$id         = wp_update_post( wp_slash( $data ), true );
	} else {
		$data['post_author'] = get_current_user_id();
		$id                  = wp_insert_post( wp_slash( $data ), true );
	}
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	// Los mismos metadatos que guarda JetEngine al crear un listado desde el panel (fuente: entradas del CPT).
	$source = array( 'source' => 'posts', 'post_type' => $post_type, 'tax' => 'category' );
	update_post_meta( $id, EG_PUB_META_LISTING_KEY, $key );
	update_post_meta( $id, '_entry_type', 'listing' );
	update_post_meta( $id, '_listing_type', 'elementor' );
	update_post_meta( $id, '_listing_data', $source );
	update_post_meta( $id, '_elementor_page_settings', array_merge( (array) ( $l['settings'] ?? array() ), array(
		'listing_source'    => 'posts',
		'listing_post_type' => $post_type,
		'listing_tax'       => 'category',
		'repeater_source'   => '',
		'repeater_field'    => '',
		'repeater_option'   => '',
	) ) );
	update_post_meta( $id, '_elementor_template_type', 'jet-listing-items' );
	update_post_meta( $id, '_elementor_edit_mode', 'builder' );
	update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( eg_pub_template_ids( $elements ) ) ) );
	if ( defined( 'ELEMENTOR_VERSION' ) ) {
		update_post_meta( $id, '_elementor_version', ELEMENTOR_VERSION );
	}
	delete_post_meta( $id, '_elementor_css' );
	delete_post_meta( $id, '_elementor_element_cache' );
	return array( 'key' => $key, 'id' => (int) $id, 'created' => ! $found );
}
