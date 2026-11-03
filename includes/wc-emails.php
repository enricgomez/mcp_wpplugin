<?php
/**
 * Emails de WooCommerce (tipología «Gestión de la web»):
 *   POST /wp-json/eg/v1/wc-emails
 * Sin parámetros lista los emails de WooCommerce (id, título, activo, destinatario) y los textos añadidos.
 *   extra  {"customer_completed_order": {"es": "<p>¿Nos dejas una reseña? <a href=\"…\">…</a></p>", "ca": "…"}}
 *          añade un texto al final del email (debajo del contenido adicional de WooCommerce), en el idioma del
 *          pedido (WPML); "" o null lo quita. Sirve, p. ej., para pedir una reseña de Google con el enlace directo.
 *          La clave "*" vale para cualquier idioma que no tenga su propio texto.
 * Solo previsualiza; guarda con apply:true. El texto se añade con un filtro, así que el email original de
 * WooCommerce no cambia y al desactivar el plugin todo vuelve a como estaba.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_WC_EMAIL_EXTRA_OPT = 'eg_pub_wc_email_extra';

add_filter( 'eg_pub_modules', function ( $modules ) {
	$modules['wc-emails'] = array( 'type' => 'gestion', 'routes' => array( 'wc-emails' ) );
	return $modules;
} );

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_module_enabled( 'wc-emails' ) ) {
		return;
	}
	register_rest_route( 'eg/v1', '/wc-emails', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_wc_emails_handle',
		'permission_callback' => eg_pub_module_permission( 'manage_woocommerce' ),
	) );
} );

/** Idioma del pedido (WPML guarda wpml_language en el pedido); si no, el idioma actual. */
function eg_pub_wc_email_lang( $object ) {
	if ( is_object( $object ) && is_a( $object, 'WC_Order' ) ) {
		$lang = (string) $object->get_meta( 'wpml_language' );
		if ( '' !== $lang ) {
			return $lang;
		}
	}
	return (string) apply_filters( 'wpml_current_language', null );
}

foreach ( array_keys( (array) get_option( EG_PUB_WC_EMAIL_EXTRA_OPT, array() ) ) as $eg_pub_email_id ) {
	add_filter( 'woocommerce_email_additional_content_' . $eg_pub_email_id, function ( $content, $object = null ) use ( $eg_pub_email_id ) {
		$all  = (array) get_option( EG_PUB_WC_EMAIL_EXTRA_OPT, array() );
		$map  = (array) ( $all[ $eg_pub_email_id ] ?? array() );
		$lang = eg_pub_wc_email_lang( $object );
		$text = $map[ $lang ] ?? ( $map['*'] ?? '' );
		if ( '' === $text ) {
			return $content;
		}
		return trim( (string) $content ) === '' ? $text : $content . "\n\n" . $text;
	}, 20, 2 );
}
unset( $eg_pub_email_id );

function eg_pub_wc_emails_handle( WP_REST_Request $r ) {
	if ( ! function_exists( 'WC' ) ) {
		return eg_pub_error( 'eg_wc', 'WooCommerce no está activo.' );
	}
	$p       = $r->get_params();
	$apply   = ! empty( $p['apply'] );
	$extra   = (array) get_option( EG_PUB_WC_EMAIL_EXTRA_OPT, array() );
	$emails  = WC()->mailer()->get_emails();
	$ids     = array();
	foreach ( $emails as $email ) {
		$ids[] = $email->id;
	}
	$changes = array();
	$notes   = array();

	foreach ( (array) ( $p['extra'] ?? array() ) as $id => $langs ) {
		$id = sanitize_key( $id );
		if ( ! in_array( $id, $ids, true ) ) {
			$notes[] = 'no existe el email ' . $id;
			continue;
		}
		foreach ( (array) $langs as $lang => $text ) {
			$lang = '*' === $lang ? '*' : sanitize_key( $lang );
			if ( null === $text || '' === $text ) {
				if ( isset( $extra[ $id ][ $lang ] ) ) {
					unset( $extra[ $id ][ $lang ] );
					$changes[] = $id . '.' . $lang;
				}
			} elseif ( ( $extra[ $id ][ $lang ] ?? null ) !== wp_kses_post( $text ) ) {
				$extra[ $id ][ $lang ] = wp_kses_post( $text );
				$changes[]             = $id . '.' . $lang;
			}
		}
		if ( isset( $extra[ $id ] ) && ! $extra[ $id ] ) {
			unset( $extra[ $id ] );
		}
	}

	if ( $apply && $changes ) {
		if ( $extra ) {
			update_option( EG_PUB_WC_EMAIL_EXTRA_OPT, $extra, true );
		} else {
			delete_option( EG_PUB_WC_EMAIL_EXTRA_OPT );
		}
	}

	$list = array();
	foreach ( $emails as $email ) {
		$list[] = array(
			'id'        => $email->id,
			'title'     => $email->get_title(),
			'enabled'   => $email->is_enabled(),
			'customer'  => $email->is_customer_email(),
			'extra'     => $extra[ $email->id ] ?? null,
		);
	}
	return new WP_REST_Response( array(
		'ok'      => true,
		'applied' => $apply && $changes,
		'changes' => $changes,
		'notes'   => $notes,
		'emails'  => $list,
	), 200 );
}
