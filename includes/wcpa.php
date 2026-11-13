<?php
/**
 * Formularios de opciones de Acowebs WooCommerce Custom Product Addons (WCPA) (tipología «Gestión de la web»):
 *   POST /wp-json/eg/v1/wcpa-forms
 * Sin parámetros lista los formularios (wcpa_pt_forms) de todos los idiomas: id, título, estado, idioma de WPML,
 * si tienen campos y los textos que muestran (etiquetas, descripciones, opciones…), agrupados por clave. `ids` limita
 * la lista a esos formularios. Con `type: "options"` lista las listas de opciones globales (wcpa_pt_options), que
 * los formularios usan por su clave; `copy` y `translate` también sirven para ellas (sus textos van en `label`).
 *   copy       {"<id origen>": <id destino>} copia los ajustes y campos de WCPA (metadatos que empiezan por _wcpa o
 *              wcpa) del formulario origen al destino, p. ej. del formulario en castellano a su traducción vacía
 *              (la traducción automática de WPML crea el formulario sin campos y la ficha no muestra opciones).
 *   translate  {"Bebida": "Beguda"} cambia, en los formularios copiados, los textos que se ven (claves `keys`) que
 *              coinciden exactamente con el original. Los valores internos de las opciones no se tocan, para que
 *              las condiciones entre campos sigan funcionando.
 *   keys       claves de texto que se traducen (por defecto label, description, placeholder, tooltip, title,
 *              help_text, desc).
 * Solo previsualiza; guarda con apply:true y vacía las cachés de WCPA (productos con formulario y campos de cada
 * producto, en todos los idiomas) y la de WP Rocket.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'eg_pub_modules', function ( $modules ) {
	$modules['wcpa'] = array( 'type' => 'gestion', 'routes' => array( 'wcpa-forms' ) );
	return $modules;
} );

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_module_enabled( 'wcpa' ) ) {
		return;
	}
	register_rest_route( 'eg/v1', '/wcpa-forms', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_wcpa_forms_handle',
		'permission_callback' => eg_pub_module_permission( 'manage_options' ),
	) );
} );

/** Tipos de WCPA que se copian y su metadato con el JSON de campos u opciones. */
const EG_PUB_WCPA_TYPES = array(
	'wcpa_pt_forms'   => '_wcpa_fb_json_data',
	'wcpa_pt_options' => '_wcpa_options_data',
);

/** Metadatos de WCPA de un formulario: todo lo que empieza por _wcpa o wcpa. */
function eg_pub_wcpa_meta( $form_id ) {
	$out = array();
	foreach ( (array) get_post_meta( $form_id ) as $key => $values ) {
		if ( 0 === strpos( $key, '_wcpa' ) || 0 === strpos( $key, 'wcpa' ) ) {
			$out[ $key ] = maybe_unserialize( $values[0] );
		}
	}
	return $out;
}

/** Recorre el JSON del formulario: junta los textos por clave o los traduce con $map en las claves $keys. */
function eg_pub_wcpa_walk( &$node, $keys, $map, &$texts, &$count ) {
	if ( is_array( $node ) || is_object( $node ) ) {
		foreach ( $node as $k => &$v ) {
			if ( is_string( $v ) ) {
				if ( in_array( (string) $k, $keys, true ) && '' !== trim( $v ) ) {
					$texts[ (string) $k ][ $v ] = true;
					if ( isset( $map[ trim( $v ) ] ) ) {
						$v = $map[ trim( $v ) ];
						$count++;
					}
				}
			} else {
				eg_pub_wcpa_walk( $v, $keys, $map, $texts, $count );
			}
		}
		unset( $v );
	}
}

function eg_pub_wcpa_lang( $id ) {
	$info = apply_filters( 'wpml_post_language_details', null, $id );
	return is_array( $info ) && ! empty( $info['language_code'] ) ? $info['language_code'] : '';
}

/** Vacía las cachés de WCPA, que no caducan: productos con formulario y campos de cada producto, en todos los idiomas. */
function eg_pub_wcpa_flush() {
	global $wpdb;
	$langs = array( '' );
	foreach ( (array) apply_filters( 'wpml_active_languages', null, array() ) as $code => $l ) {
		$langs[] = (string) $code;
	}
	$base = defined( 'WCPA_PRODUCTS_TRANSIENT_KEY' ) ? WCPA_PRODUCTS_TRANSIENT_KEY : 'wcpa_products_transient_ver_3';
	$ids  = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", 'product' ) );
	$n    = 0;
	foreach ( $langs as $lang ) {
		$sfx = '' === $lang ? '' : '_' . $lang;
		$n  += (int) delete_transient( $base . $sfx );
		foreach ( $ids as $id ) {
			$n += (int) delete_transient( 'wcpa_fields_' . $id . $sfx );
		}
	}
	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
	}
	return $n;
}

function eg_pub_wcpa_forms_handle( WP_REST_Request $r ) {
	$p     = $r->get_params();
	$apply = ! empty( $p['apply'] );
	$keys  = array_map( 'strval', (array) ( $p['keys'] ?? array( 'label', 'description', 'placeholder', 'tooltip', 'title', 'help_text', 'desc' ) ) );
	$map   = array();
	foreach ( (array) ( $p['translate'] ?? array() ) as $from => $to ) {
		$map[ trim( (string) $from ) ] = (string) $to;
	}
	$changes = array();
	$notes   = array();

	foreach ( (array) ( $p['copy'] ?? array() ) as $src => $dst ) {
		$src = (int) $src;
		$dst = (int) $dst;
		$type = get_post_type( $src );
		if ( ! isset( EG_PUB_WCPA_TYPES[ $type ] ) || get_post_type( $dst ) !== $type || $src === $dst ) {
			$notes[] = 'copia ' . $src . ' → ' . $dst . ': tienen que ser dos formularios (o dos listas de opciones) de WCPA distintos';
			continue;
		}
		$jkey = EG_PUB_WCPA_TYPES[ $type ];
		$meta = eg_pub_wcpa_meta( $src );
		if ( empty( $meta[ $jkey ] ) ) {
			$notes[] = 'copia ' . $src . ' → ' . $dst . ': el origen no tiene campos ni opciones';
			continue;
		}
		$texts      = array();
		$translated = 0;
		$json       = json_decode( (string) $meta[ $jkey ] );
		eg_pub_wcpa_walk( $json, $keys, $map, $texts, $translated );
		$meta[ $jkey ] = wp_json_encode( $json );
		$pending                      = array();
		foreach ( $texts as $k => $list ) {
			foreach ( array_keys( $list ) as $t ) {
				if ( ! isset( $map[ trim( $t ) ] ) && ! in_array( $t, $map, true ) ) {
					$pending[] = $t;
				}
			}
		}
		$changes[] = array(
			'from'          => $src,
			'to'            => $dst,
			'meta'          => array_keys( $meta ),
			'translated'    => $translated,
			'untranslated'  => array_values( array_unique( $pending ) ),
		);
		if ( $apply ) {
			foreach ( $meta as $key => $value ) {
				update_post_meta( $dst, $key, wp_slash( $value ) );
			}
		}
	}
	$flushed = ( $apply && $changes ) ? eg_pub_wcpa_flush() : 0;

	$args = array(
		'post_type'        => 'options' === ( $p['type'] ?? '' ) ? 'wcpa_pt_options' : 'wcpa_pt_forms',
		'post_status'      => 'any',
		'posts_per_page'   => 200,
		'suppress_filters' => true,
		'orderby'          => 'ID',
		'order'            => 'ASC',
	);
	if ( ! empty( $p['ids'] ) ) {
		$args['post__in'] = array_map( 'intval', (array) $p['ids'] );
	}
	$forms = array();
	foreach ( get_posts( $args ) as $post ) {
		$meta  = eg_pub_wcpa_meta( $post->ID );
		$texts = array();
		$n     = 0;
		$jkey  = EG_PUB_WCPA_TYPES[ $post->post_type ];
		$json  = isset( $meta[ $jkey ] ) ? json_decode( (string) $meta[ $jkey ] ) : null;
		if ( $json ) {
			eg_pub_wcpa_walk( $json, $keys, array(), $texts, $n );
		}
		$forms[] = array(
			'id'         => $post->ID,
			'title'      => $post->post_title,
			'status'     => $post->post_status,
			'lang'       => eg_pub_wcpa_lang( $post->ID ),
			'has_fields' => (bool) $json,
			'meta'       => array_keys( $meta ),
			'texts'      => array_map( 'array_keys', $texts ),
		);
	}

	return new WP_REST_Response( array(
		'ok'      => true,
		'applied' => $apply && $changes,
		'changes' => $changes,
		'flushed' => $flushed,
		'notes'   => $notes,
		'forms'   => $forms,
	), 200 );
}
