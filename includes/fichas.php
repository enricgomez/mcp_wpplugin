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

function eg_pub_item_handle( WP_REST_Request $r ) {
	$p       = $r->get_params();
	$post_id = (int) ( $p['id'] ?? 0 );
	$post    = $post_id ? get_post( $post_id ) : null;
	if ( ! $post || in_array( $post->post_type, array( 'attachment', 'revision' ), true ) ) {
		return eg_pub_error( 'eg_missing', 'No existe la entrada ' . $post_id . '.', 404 );
	}
	$set     = (array) ( $p['set'] ?? array() );
	$acf     = (array) ( $p['acf'] ?? array() );
	$dry_run = ! empty( $p['dry_run'] );
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
