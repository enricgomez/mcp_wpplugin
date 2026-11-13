<?php
/**
 * Editar una entrada que ya existe, de cualquier tipo (páginas, CPT como habitaciones u ofertas), y sus campos
 * de ACF (tipología «Gestión de la web»):
 *   POST /wp-json/eg/v1/item
 * Con dry_run (o sin cambios) devuelve la entrada y sus campos de ACF con su tipo y valor actual.
 * `set` cambia title, slug, excerpt, content, status o menu_order; `acf` cambia campos por nombre o clave
 * (las imágenes y galerías admiten ID, URL o {"url", "alt"}, y se suben una sola vez); `featured_image` pone
 * la imagen destacada. Se guarda sin wp_update_post para que WPML no lance la traducción automática.
 * `with_meta: true` en la lectura devuelve sus campos personalizados (p. ej. los del tema, como _dt_sidebar_position
 * de The7) y `meta` ({"campo": valor}) los cambia; "" o null borra el campo. No toca los internos de WordPress.
 * `acf_new` ([{"name", "label", "type"?, "after"?}]) añade campos nuevos al grupo de ACF de la entrada (el del campo
 * `group_of`, o el primero), de tipo interruptor (true_false) si no se dice otro. Si ya existe uno con ese nombre en
 * el grupo, no lo duplica. Solo grupos guardados en la base de datos (no los de PHP). Con dry_run solo lo describe.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'eg_pub_modules', function ( $modules ) {
	$modules['fichas'] = array( 'type' => 'gestion', 'routes' => array( 'item' ) );
	return $modules;
} );

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_module_enabled( 'fichas' ) ) {
		return;
	}
	register_rest_route( 'eg/v1', '/item', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_item_handle',
		'permission_callback' => eg_pub_module_permission( 'edit_others_pages' ),
	) );
} );

/** Campos de ACF de los grupos que se aplican a la entrada: nombre => campo (también por clave). */
function eg_pub_item_acf_fields( $post_id ) {
	$fields = array();
	if ( ! function_exists( 'acf_get_field_groups' ) ) {
		return $fields;
	}
	foreach ( acf_get_field_groups( array( 'post_id' => $post_id, 'post_type' => get_post_type( $post_id ) ) ) as $group ) {
		foreach ( (array) acf_get_fields( $group ) as $field ) {
			$fields[ $field['name'] ] = $field;
			$fields[ $field['key'] ]  = $field;
		}
	}
	return $fields;
}

/** Valor de un campo para la respuesta: sin formatear (IDs en imágenes y galerías) y con los subcampos. */
function eg_pub_item_acf_state( $post_id ) {
	$out = array();
	foreach ( eg_pub_item_acf_fields( $post_id ) as $name => $field ) {
		if ( $name !== $field['name'] || '' === $field['name'] ) {
			continue;
		}
		$row = array( 'key' => $field['key'], 'type' => $field['type'], 'label' => $field['label'], 'value' => get_field( $field['name'], $post_id, false ) );
		if ( ! empty( $field['sub_fields'] ) ) {
			$row['sub_fields'] = wp_list_pluck( $field['sub_fields'], 'type', 'name' );
		}
		if ( ! empty( $field['choices'] ) ) {
			$row['choices'] = $field['choices'];
		}
		$out[ $field['name'] ] = $row;
	}
	return $out;
}

/** ID de adjunto a partir de un ID, una URL o {"url"|"id"|"b64", "alt", "filename"}. */
function eg_pub_item_image( $post_id, $img, $filename ) {
	if ( is_numeric( $img ) ) {
		return get_post( (int) $img ) ? (int) $img : new WP_Error( 'eg_img', 'no existe el adjunto ' . (int) $img );
	}
	$img = is_array( $img ) ? $img : array( 'url' => (string) $img );
	if ( ! empty( $img['id'] ) ) {
		if ( ! empty( $img['alt'] ) ) {
			update_post_meta( (int) $img['id'], '_wp_attachment_image_alt', sanitize_text_field( $img['alt'] ) );
		}
		return (int) $img['id'];
	}
	if ( empty( $img['url'] ) && empty( $img['b64'] ) ) {
		return new WP_Error( 'eg_img', 'falta la url (o el b64) de la imagen' );
	}
	return eg_pub_sideload_image( $post_id, (string) ( $img['url'] ?? '' ), (string) ( $img['b64'] ?? '' ), array(
		'filename' => $img['filename'] ?? $filename,
		'alt'      => $img['alt'] ?? '',
	) );
}

function eg_pub_item_state( $post_id ) {
	$post = get_post( $post_id );
	return array(
		'id'             => $post_id,
		'type'           => $post->post_type,
		'title'          => $post->post_title,
		'slug'           => $post->post_name,
		'status'         => $post->post_status,
		'excerpt'        => $post->post_excerpt,
		'menu_order'     => (int) $post->menu_order,
		'author'         => (int) $post->post_author,
		'url'            => get_permalink( $post_id ),
		'featured_image' => (int) get_post_thumbnail_id( $post_id ),
		'acf'            => eg_pub_item_acf_state( $post_id ),
	);
}

/** Añade campos al grupo de ACF de la entrada. Devuelve lo creado y lo que ya estaba. */
function eg_pub_item_acf_new( $post_id, $new, $group_of, $dry_run ) {
	if ( ! function_exists( 'acf_update_field' ) ) {
		return eg_pub_error( 'eg_acf', 'ACF no está activo.' );
	}
	$groups = acf_get_field_groups( array( 'post_id' => $post_id, 'post_type' => get_post_type( $post_id ) ) );
	$group  = $groups ? $groups[0] : null;
	if ( $group_of ) {
		foreach ( $groups as $g ) {
			foreach ( (array) acf_get_fields( $g ) as $f ) {
				if ( $f['name'] === $group_of || $f['key'] === $group_of ) {
					$group = $g;
					break 2;
				}
			}
		}
	}
	if ( ! $group || empty( $group['ID'] ) ) {
		return eg_pub_error( 'eg_acf', 'La entrada no tiene un grupo de campos de ACF guardado en la base de datos.' );
	}
	$existing = array();
	$order    = 0;
	foreach ( (array) acf_get_fields( $group ) as $f ) {
		$existing[ $f['name'] ] = $f['key'];
		$order                  = max( $order, (int) $f['menu_order'] );
	}
	$types   = array( 'true_false', 'text', 'textarea', 'number', 'image', 'url' );
	$created = array();
	$kept    = array();
	foreach ( (array) $new as $item ) {
		$name  = sanitize_key( $item['name'] ?? '' );
		$label = sanitize_text_field( $item['label'] ?? '' );
		$type  = in_array( $item['type'] ?? 'true_false', $types, true ) ? ( $item['type'] ?? 'true_false' ) : 'true_false';
		if ( '' === $name || '' === $label ) {
			return eg_pub_error( 'eg_acf', 'Cada campo nuevo necesita name y label.' );
		}
		if ( isset( $existing[ $name ] ) ) {
			$kept[ $name ] = $existing[ $name ];
			continue;
		}
		$field = array(
			'key'        => 'field_' . substr( md5( $group['key'] . $name ), 0, 13 ),
			'label'      => $label,
			'name'       => $name,
			'type'       => $type,
			'parent'     => (int) $group['ID'],
			'menu_order' => ++$order,
		);
		if ( 'true_false' === $type ) {
			$field += array( 'ui' => 1, 'default_value' => 0 );
		}
		if ( ! $dry_run ) {
			$saved = acf_update_field( $field );
			$field['key'] = $saved['key'] ?? $field['key'];
		}
		$existing[ $name ] = $field['key'];
		$created[ $name ]  = $field['key'];
	}
	return array( 'group' => $group['title'], 'group_key' => $group['key'], 'created' => (object) $created, 'existing' => (object) $kept );
}

function eg_pub_item_handle( WP_REST_Request $r ) {
	$p = $r->get_params();
	if ( isset( $p['items'] ) ) {
		return eg_pub_item_batch( $p['items'] );
	}
	if ( ! empty( $p['create'] ) && empty( $p['id'] ) ) {
		$created = eg_pub_item_create( (array) $p['create'], ! empty( $p['dry_run'] ) );
		if ( $created instanceof WP_REST_Response ) {
			return $created;
		}
		if ( is_array( $created ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'dry_run' => true, 'create' => $created ), 200 );
		}
		$p['id'] = $created;
	}
	$post_id = (int) ( $p['id'] ?? 0 );
	$post    = $post_id ? get_post( $post_id ) : null;
	if ( ! $post || in_array( $post->post_type, array( 'attachment', 'revision' ), true ) ) {
		return eg_pub_error( 'eg_missing', 'No existe la entrada ' . $post_id . '.', 404 );
	}
	$set     = (array) ( $p['set'] ?? array() );
	$acf     = (array) ( $p['acf'] ?? array() );
	$meta    = (array) ( $p['meta'] ?? array() );
	$dry_run = ! empty( $p['dry_run'] );
	$acf_new = null;
	if ( ! empty( $p['acf_new'] ) ) {
		$acf_new = eg_pub_item_acf_new( $post_id, $p['acf_new'], sanitize_text_field( $p['group_of'] ?? '' ), $dry_run );
		if ( is_wp_error( $acf_new ) ) {
			return $acf_new;
		}
		if ( $dry_run || ( ! $set && ! $acf && ! $meta && ! isset( $p['featured_image'] ) ) ) {
			return new WP_REST_Response( array( 'ok' => true, 'dry_run' => $dry_run, 'acf_new' => $acf_new ), 200 );
		}
	}
	if ( $dry_run || ( ! $set && ! $acf && ! $meta && ! isset( $p['featured_image'] ) ) ) {
		$state = eg_pub_item_state( $post_id );
		if ( ! empty( $p['with_content'] ) ) {
			// El contenido completo, tal cual (shortcodes de WPBakery incluidos), para preparar un /text exacto.
			$state['content'] = $post->post_content;
		}
		if ( ! empty( $p['with_meta'] ) ) {
			$state['meta'] = array();
			foreach ( get_post_meta( $post_id ) as $key => $values ) {
				$value                  = maybe_unserialize( $values[0] );
				$state['meta'][ $key ] = is_string( $value ) && strlen( $value ) > 300 ? substr( $value, 0, 300 ) . '…' : $value;
			}
		}
		return new WP_REST_Response( array( 'ok' => true, 'dry_run' => true ) + $state, 200 );
	}

	$changes = array();
	$notes   = array();
	$base    = sanitize_title( $set['slug'] ?? $post->post_name );

	// --- Campos de la entrada ----------------------------------------------------
	$row = array();
	if ( isset( $set['title'] ) ) {
		$row['post_title'] = wp_strip_all_tags( (string) $set['title'] );
	}
	if ( isset( $set['excerpt'] ) ) {
		$row['post_excerpt'] = wp_kses_post( (string) $set['excerpt'] );
	}
	if ( isset( $set['content'] ) ) {
		$row['post_content'] = wp_kses_post( (string) $set['content'] );
	}
	if ( ! empty( $set['content_replace'] ) && is_array( $set['content_replace'] ) ) {
		// Sustituciones exactas en el contenido tal cual (shortcodes y HTML incluidos), p. ej. páginas de WPBakery.
		// Cada «from» debe salir una sola vez; solo se filtra lo que entra («to»), el resto no se toca.
		$content = $row['post_content'] ?? $post->post_content;
		foreach ( $set['content_replace'] as $i => $pair ) {
			$from = (string) ( $pair['from'] ?? '' );
			$hits = '' === $from ? 0 : substr_count( $content, $from );
			if ( 1 !== $hits ) {
				return eg_pub_error( 'eg_replace', 'content_replace[' . $i . ']: «from» aparece ' . $hits . ' veces; debe salir exactamente una.' );
			}
			$content = str_replace( $from, wp_kses_post( (string) ( $pair['to'] ?? '' ) ), $content );
		}
		$row['post_content'] = $content;
	}
	if ( isset( $set['date'] ) ) {
		// Fecha de publicación (ordena listados y archivos): «Y-m-d» o «Y-m-d H:i:s», en la hora de la web.
		$date = trim( (string) $set['date'] );
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			$date .= ' 10:00:00';
		}
		$parsed = DateTime::createFromFormat( 'Y-m-d H:i:s', $date, wp_timezone() );
		if ( ! $parsed || $parsed->format( 'Y-m-d H:i:s' ) !== $date ) {
			return eg_pub_error( 'eg_date', 'date debe ser «AAAA-MM-DD» o «AAAA-MM-DD HH:MM:SS».' );
		}
		$row['post_date']     = $date;
		$row['post_date_gmt'] = get_gmt_from_date( $date );
		if ( $post->post_date && substr( $post->post_date, 0, 10 ) !== substr( $date, 0, 10 ) ) {
			// Lo mismo que hace WordPress al cambiar la fecha: los enlaces con la fecha antigua siguen funcionando.
			add_post_meta( $post_id, '_wp_old_date', substr( $post->post_date, 0, 10 ) );
		}
	}
	if ( isset( $set['menu_order'] ) ) {
		$row['menu_order'] = (int) $set['menu_order'];
	}
	if ( isset( $set['status'] ) ) {
		$status = sanitize_key( $set['status'] );
		if ( ! in_array( $status, array( 'draft', 'publish', 'private', 'pending' ), true ) ) {
			return eg_pub_error( 'eg_status', 'status debe ser draft, publish, private o pending.' );
		}
		$row['post_status'] = $status;
	}
	if ( isset( $set['parent'] ) ) {
		$parent = (int) $set['parent'];
		if ( ! is_post_type_hierarchical( $post->post_type ) ) {
			return eg_pub_error( 'eg_parent', 'El tipo ' . $post->post_type . ' no admite página superior.' );
		}
		if ( $parent && ( $parent === $post_id || get_post_type( $parent ) !== $post->post_type || in_array( $post_id, get_post_ancestors( $parent ), true ) ) ) {
			return eg_pub_error( 'eg_parent', 'parent debe ser 0 u otra entrada del mismo tipo que no cuelgue de esta.' );
		}
		$row['post_parent'] = $parent;
	}
	if ( isset( $set['author'] ) ) {
		$ref  = (string) $set['author'];
		$user = ctype_digit( $ref ) ? get_userdata( (int) $ref ) : ( get_user_by( 'login', $ref ) ?: get_user_by( 'email', $ref ) );
		if ( ! $user || ! user_can( $user, 'edit_posts' ) ) {
			return eg_pub_error( 'eg_author', 'author debe ser el ID, usuario o email de un usuario que pueda escribir entradas.' );
		}
		$row['post_author'] = (int) $user->ID;
	}
	if ( ! empty( $set['slug'] ) || isset( $row['post_parent'] ) ) {
		$row['post_name'] = wp_unique_post_slug( sanitize_title( $set['slug'] ?? $post->post_name ), $post_id, $row['post_status'] ?? $post->post_status, $post->post_type, $row['post_parent'] ?? $post->post_parent );
	}
	if ( $row ) {
		global $wpdb;
		$wpdb->update( $wpdb->posts, $row + array( 'post_modified' => current_time( 'mysql' ), 'post_modified_gmt' => current_time( 'mysql', true ) ), array( 'ID' => $post_id ) );
		clean_post_cache( $post_id );
		if ( isset( $row['post_status'] ) && $row['post_status'] !== $post->post_status ) {
			wp_transition_post_status( $row['post_status'], $post->post_status, get_post( $post_id ) );
		}
		$changes = array_merge( $changes, array_keys( $row ) );
		// Yoast guarda la URL, el título y el estado en su tabla de indexables y no se entera de este cambio (no pasa
		// por wp_update_post): se borra el indexable de la entrada y de sus hijas, y Yoast lo rehace en la siguiente
		// visita. Si no, el canonical, el og:url y el sitemap siguen con la URL antigua.
		if ( defined( 'WPSEO_VERSION' ) && array_intersect( array( 'post_name', 'post_parent', 'post_title', 'post_status' ), array_keys( $row ) ) ) {
			$ids   = array_merge( array( $post_id ), array_map( 'intval', get_children( array( 'post_parent' => $post_id, 'post_type' => $post->post_type, 'fields' => 'ids' ) ) ) );
			$table = $wpdb->prefix . 'yoast_indexable';
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
				$in = implode( ',', array_map( 'intval', $ids ) );
				$wpdb->query( "DELETE FROM {$table} WHERE object_type = 'post' AND object_id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$changes[] = 'yoast_indexable';
			}
		}
	}

	// --- Imagen destacada --------------------------------------------------------
	if ( isset( $p['featured_image'] ) ) {
		$att = eg_pub_item_image( $post_id, $p['featured_image'], $base );
		if ( is_wp_error( $att ) ) {
			$notes[] = 'imagen destacada: ' . $att->get_error_message();
		} else {
			set_post_thumbnail( $post_id, $att );
			$changes[] = 'featured_image';
		}
	}

	// --- Campos de ACF -----------------------------------------------------------
	if ( $acf && ! function_exists( 'update_field' ) ) {
		$notes[] = 'ACF no está activo: no se ha cambiado ningún campo.';
		$acf     = array();
	}
	$fields = $acf ? eg_pub_item_acf_fields( $post_id ) : array();
	foreach ( $acf as $name => $value ) {
		$field = $fields[ $name ] ?? null;
		if ( ! $field ) {
			$notes[] = 'el campo ' . $name . ' no está en los grupos de ACF de esta entrada';
			continue;
		}
		if ( 'image' === $field['type'] && '' !== $value && null !== $value ) {
			$value = eg_pub_item_image( $post_id, $value, $base . '-' . $field['name'] );
		} elseif ( 'gallery' === $field['type'] ) {
			$ids = array();
			foreach ( array_values( (array) $value ) as $i => $img ) {
				$att = eg_pub_item_image( $post_id, $img, $base . '-' . ( $i + 1 ) );
				if ( is_wp_error( $att ) ) {
					$notes[] = $field['name'] . ' #' . ( $i + 1 ) . ': ' . $att->get_error_message();
				} else {
					$ids[] = $att;
				}
			}
			$value = $ids;
		}
		if ( is_wp_error( $value ) ) {
			$notes[] = $field['name'] . ': ' . $value->get_error_message();
			continue;
		}
		update_field( $field['key'], $value, $post_id );
		$changes[] = 'acf.' . $field['name'];
	}

	// --- Campos personalizados -----------------------------------------------------
	foreach ( $meta as $key => $value ) {
		$key = (string) $key;
		if ( preg_match( '/^_(wp_|edit_|thumbnail_id$|elementor_data$)/', $key ) ) {
			$notes[] = 'el campo ' . $key . ' es interno de WordPress: no se cambia';
			continue;
		}
		if ( null === $value || '' === $value ) {
			delete_post_meta( $post_id, $key );
		} else {
			update_post_meta( $post_id, $key, is_scalar( $value ) ? wp_slash( (string) $value ) : $value );
		}
		$changes[] = 'meta.' . $key;
	}

	// Elementor pinta los campos dinámicos con su caché: que la regenere.
	delete_post_meta( $post_id, '_elementor_element_cache' );
	if ( function_exists( 'rocket_clean_post' ) ) {
		rocket_clean_post( $post_id );
	}

	return new WP_REST_Response( array(
		'ok'      => true,
		'changes' => array_values( array_unique( $changes ) ),
		'notes'   => $notes,
	) + eg_pub_item_state( $post_id ), 200 );
}

/**
 * `create` ({"post_type", "title", "slug"?, "status"?}) crea la entrada (en borrador si no se dice otro estado) y
 * devuelve su ID; el resto de la petición (set, meta, acf, featured_image) se le aplica después. Con dry_run solo
 * comprueba el tipo y devuelve lo que crearía. No crea adjuntos, revisiones ni tipos que no existan.
 */
function eg_pub_item_create( array $create, $dry_run ) {
	$type  = sanitize_key( $create['post_type'] ?? '' );
	$title = wp_strip_all_tags( (string) ( $create['title'] ?? '' ) );
	if ( ! post_type_exists( $type ) || in_array( $type, array( 'attachment', 'revision', 'nav_menu_item', 'wp_template', 'wp_template_part', 'wp_global_styles' ), true ) ) {
		return eg_pub_error( 'eg_create', 'create.post_type debe ser un tipo de contenido que exista (p. ej. post, page o un CPT).' );
	}
	if ( '' === $title ) {
		return eg_pub_error( 'eg_create', 'create.title es obligatorio.' );
	}
	$status = sanitize_key( $create['status'] ?? 'draft' );
	if ( ! in_array( $status, array( 'draft', 'publish', 'private', 'pending' ), true ) ) {
		return eg_pub_error( 'eg_status', 'create.status debe ser draft, publish, private o pending.' );
	}
	$data = array( 'post_type' => $type, 'post_title' => $title, 'post_status' => $status, 'post_name' => sanitize_title( $create['slug'] ?? $title ) );
	if ( $dry_run ) {
		return $data;
	}
	$id = wp_insert_post( $data + array( 'post_author' => get_current_user_id() ), true );
	return is_wp_error( $id ) ? eg_pub_error( 'eg_create', $id->get_error_message() ) : (int) $id;
}

/**
 * `items` ([{id o create, set, meta, acf, featured_image, dry_run}, …], máx. 100) hace varias peticiones de /item en
 * una sola llamada (p. ej. retocar un campo en todas las fichas de un CPT). Cada una va por separado: si una falla,
 * las demás siguen, y la respuesta trae el resultado de cada una en el mismo orden.
 */
function eg_pub_item_batch( $items ) {
	if ( ! is_array( $items ) || ! $items || count( $items ) > 100 ) {
		return eg_pub_error( 'eg_items', 'items debe ser una lista de 1 a 100 peticiones.' );
	}
	$out = array();
	$ok  = 0;
	foreach ( array_values( $items ) as $item ) {
		$item = (array) $item;
		unset( $item['items'] );
		$req = new WP_REST_Request( 'POST', '/eg/v1/item' );
		$req->set_body_params( $item );
		$res = eg_pub_item_handle( $req );
		$data  = is_wp_error( $res ) ? array( 'ok' => false, 'message' => $res->get_error_message() ) : (array) $res->get_data();
		$out[] = $data + array( 'id' => $item['id'] ?? null );
		$ok   += empty( $data['ok'] ) ? 0 : 1;
	}
	return new WP_REST_Response( array( 'ok' => $ok === count( $out ), 'done' => $ok, 'total' => count( $out ), 'items' => $out ), 200 );
}
