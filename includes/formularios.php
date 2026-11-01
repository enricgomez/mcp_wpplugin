<?php
/**
 * Textos de los formularios de Forminator (tipología «Gestión de la web», módulo «Formularios»):
 *   POST /wp-json/eg/v1/form
 * Sin cambios, lista los textos de un formulario: por campo (element_id: name-1, consent-1…) sus etiquetas,
 * placeholders, descripciones, mensajes y opciones, y los textos de los ajustes (botón de enviar, mensajes).
 * Con `fields` y/o `settings` los cambia: se mezclan con lo que hay, así que basta con mandar lo que cambia.
 * Sirve para traducir los formularios de cada idioma cuando cada idioma usa su propio formulario.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_FORMINATOR_META = 'forminator_form_meta';

add_filter( 'eg_pub_modules', function ( $modules ) {
	$modules['formularios'] = array( 'type' => 'gestion', 'routes' => array( 'form' ) );
	return $modules;
} );

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_module_enabled( 'formularios' ) ) {
		return;
	}
	register_rest_route( 'eg/v1', '/form', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_form_handle',
		'permission_callback' => eg_pub_module_permission( 'edit_others_pages' ),
	) );
} );

/** Claves técnicas o de estilo que no son texto para el visitante. */
function eg_pub_form_is_text_key( $key ) {
	if ( in_array( $key, array( 'id', 'element_id', 'type', 'cols', 'wrapper_id', 'form_id', 'condition_action', 'condition_rule', 'custom-class', 'date_format', 'time_format', 'phone_validation_type' ), true ) ) {
		return false;
	}
	return ! preg_match( '/color|font|padding|border|css|style|width|height|margin|radius|spacing|size|align|-id$|_id$|^cform-|^form-|behav|enable|limit|layout|design|icon/', $key );
}

/** Los textos de un campo: valores de texto no vacíos y las etiquetas de sus opciones. */
function eg_pub_form_field_texts( $field, $full ) {
	$out = array( 'element_id' => (string) ( $field['element_id'] ?? ( $field['id'] ?? '' ) ), 'type' => $field['type'] ?? '' );
	foreach ( $field as $key => $value ) {
		if ( isset( $out[ $key ] ) ) {
			continue;
		}
		if ( 'options' === $key && is_array( $value ) ) {
			$out['options'] = array_map( function ( $o ) {
				return array( 'label' => $o['label'] ?? '', 'value' => $o['value'] ?? '' );
			}, $value );
			continue;
		}
		if ( is_string( $value ) && '' !== trim( $value ) && ( $full || eg_pub_form_is_text_key( $key ) ) && ! is_numeric( $value ) && ! in_array( $value, array( 'true', 'false' ), true ) ) {
			$out[ $key ] = $value;
		}
	}
	return $out;
}

/** Textos de los ajustes (botón de enviar, mensajes), un nivel de profundidad. */
function eg_pub_form_settings_texts( $settings, $full ) {
	$out = array();
	foreach ( (array) $settings as $key => $value ) {
		if ( is_array( $value ) ) {
			$sub = eg_pub_form_settings_texts( $value, $full );
			if ( $sub && ! wp_is_numeric_array( $value ) ) {
				$out[ $key ] = $sub;
			}
			continue;
		}
		if ( is_string( $value ) && '' !== trim( $value ) && ( $full || eg_pub_form_is_text_key( (string) $key ) ) && ! is_numeric( $value ) && ! in_array( $value, array( 'true', 'false' ), true ) ) {
			$out[ $key ] = $value;
		}
	}
	return $out;
}

function eg_pub_form_handle( WP_REST_Request $r ) {
	$p    = $r->get_params();
	$id   = (int) ( $p['id'] ?? 0 );
	$post = $id ? get_post( $id ) : null;
	if ( ! $post || 'forminator_forms' !== $post->post_type ) {
		return eg_pub_error( 'eg_missing', 'Falta id de un formulario de Forminator (el número del shortcode [forminator_form id="…"]).', 404 );
	}
	$meta = get_post_meta( $id, EG_PUB_FORMINATOR_META, true );
	if ( ! is_array( $meta ) ) {
		return eg_pub_error( 'eg_form', 'Ese formulario no tiene los datos de Forminator.', 400 );
	}
	$fields   = is_array( $meta['fields'] ?? null ) ? $meta['fields'] : array();
	$settings = is_array( $meta['settings'] ?? null ) ? $meta['settings'] : array();
	$changes  = is_array( $p['fields'] ?? null ) ? $p['fields'] : array();
	$set      = is_array( $p['settings'] ?? null ) ? $p['settings'] : array();
	$full     = ! empty( $p['full'] );

	$updated = array();
	$missing = array_keys( $changes );
	if ( $changes || $set ) {
		foreach ( $fields as &$field ) {
			$eid = (string) ( $field['element_id'] ?? ( $field['id'] ?? '' ) );
			if ( '' === $eid || ! isset( $changes[ $eid ] ) || ! is_array( $changes[ $eid ] ) ) {
				continue;
			}
			foreach ( $changes[ $eid ] as $key => $value ) {
				if ( in_array( $key, array( 'id', 'element_id', 'type', 'form_id', 'wrapper_id' ), true ) ) {
					continue;
				}
				if ( 'options' === $key && is_array( $value ) && is_array( $field['options'] ?? null ) ) {
					// Las opciones por posición: solo cambia lo que se manda (normalmente label).
					foreach ( $value as $i => $opt ) {
						if ( isset( $field['options'][ $i ] ) && is_array( $opt ) ) {
							$field['options'][ $i ] = array_merge( $field['options'][ $i ], $opt );
						}
					}
					continue;
				}
				$field[ $key ] = is_string( $value ) ? wp_kses_post( $value ) : $value;
			}
			$updated[] = $eid;
		}
		unset( $field );
		$missing = array_values( array_diff( $missing, $updated ) );
		if ( $set ) {
			$settings = array_replace_recursive( $settings, $set );
		}
		$meta['fields']   = $fields;
		$meta['settings'] = $settings;
		update_post_meta( $id, EG_PUB_FORMINATOR_META, $meta );
		clean_post_cache( $id );
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
	}

	$lang = apply_filters( 'wpml_post_language_details', null, $id );
	return new WP_REST_Response( array(
		'ok'               => true,
		'id'               => $id,
		'title'            => $post->post_title,
		'lang'             => is_array( $lang ) ? ( $lang['language_code'] ?? '' ) : '',
		'updated'          => $updated,
		'settings_updated' => (bool) $set,
		'missing'          => $missing,
		'fields'           => array_map( function ( $f ) use ( $full ) {
			return eg_pub_form_field_texts( $f, $full );
		}, $fields ),
		'settings'         => eg_pub_form_settings_texts( $settings, $full ),
	), 200 );
}
