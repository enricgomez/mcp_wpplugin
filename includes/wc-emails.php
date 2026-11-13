<?php
/**
 * Emails de WooCommerce (tipología «Gestión de la web»):
 *   POST /wp-json/eg/v1/wc-emails
 * Sin parámetros lista los emails de WooCommerce (id, título, activo, destinatario) y los textos añadidos.
 *   extra  {"customer_completed_order": {"es": "<p>¿Nos dejas una reseña? <a href=\"…\">…</a></p>", "ca": "…"}}
 *          añade un texto al final del email (debajo del contenido adicional de WooCommerce), en el idioma del
 *          pedido (WPML); "" o null lo quita. Sirve, p. ej., para pedir una reseña de Google con el enlace directo.
 *          La clave "*" vale para cualquier idioma que no tenga su propio texto.
 *   style  {"base_color": "#B8202F", "background_color": "#0E0A08", "body_background_color": "#1A1310",
 *          "text_color": "#E9DCCD", "footer_text_color": "#BFAE9F", "header_image": 953 | "https://…",
 *          "header_image_width": 160, "header_alignment": "center", "font_family": "…", "footer_text": "<p>…</p>"}
 *          cambia el aspecto común de todos los emails (opciones woocommerce_email_* de Ajustes > Emails).
 *          header_image admite el ID de una imagen de la biblioteca o su URL. Desde la 2.78.0.
 *   css    "…" CSS que se añade al final de los estilos de los emails (WooCommerce lo pasa a estilos en línea al
 *          enviar); "" lo quita. Desde la 2.78.0.
 *   preview "customer_on_hold_order"  devuelve el HTML del email con un pedido de ejemplo (vista previa de
 *          WooCommerce ≥ 9.6), con los cambios de style/css aunque aún no se hayan guardado. Desde la 2.78.0.
 * Solo previsualiza; guarda con apply:true. El texto se añade con un filtro, así que el email original de
 * WooCommerce no cambia y al desactivar el plugin todo vuelve a como estaba.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_WC_EMAIL_EXTRA_OPT = 'eg_pub_wc_email_extra';
const EG_PUB_WC_EMAIL_CSS_OPT   = 'eg_pub_wc_email_css';

/** Opciones de aspecto de los emails que se pueden cambiar con style (clave => opción de WooCommerce). */
function eg_pub_wc_email_style_keys() {
	return array(
		'base_color'            => 'color',
		'background_color'      => 'color',
		'body_background_color' => 'color',
		'text_color'            => 'color',
		'footer_text_color'     => 'color',
		'header_image'          => 'image',
		'header_image_width'    => 'int',
		'header_alignment'      => array( 'left', 'center', 'right' ),
		'font_family'           => 'text',
		'footer_text'           => 'html',
	);
}

/** CSS propio añadido a los estilos de los emails (la vista previa puede sustituirlo por uno sin guardar). */
add_filter( 'woocommerce_email_styles', function ( $css ) {
	$extra = $GLOBALS['eg_pub_wc_email_css_preview'] ?? get_option( EG_PUB_WC_EMAIL_CSS_OPT, '' );
	return '' === (string) $extra ? $css : $css . "\n" . $extra;
}, 99 );

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
	$style   = array();

	// Aspecto común de los emails.
	foreach ( (array) ( $p['style'] ?? array() ) as $key => $value ) {
		$types = eg_pub_wc_email_style_keys();
		if ( ! isset( $types[ $key ] ) ) {
			$notes[] = 'style: clave no admitida ' . $key;
			continue;
		}
		$type = $types[ $key ];
		if ( 'color' === $type ) {
			$value = sanitize_hex_color( (string) $value );
		} elseif ( 'image' === $type ) {
			$value = is_numeric( $value ) ? (string) wp_get_attachment_url( (int) $value ) : esc_url_raw( (string) $value );
		} elseif ( 'int' === $type ) {
			$value = (string) absint( $value );
		} elseif ( 'html' === $type ) {
			$value = wp_kses_post( (string) $value );
		} elseif ( is_array( $type ) ) {
			$value = in_array( $value, $type, true ) ? $value : null;
		} else {
			$value = sanitize_text_field( (string) $value );
		}
		if ( null === $value || ( '' === $value && 'html' !== $type && 'text' !== $type ) ) {
			$notes[] = 'style: valor no válido para ' . $key;
			continue;
		}
		$opt = 'woocommerce_email_' . $key;
		if ( (string) get_option( $opt, '' ) !== $value ) {
			$style[ $opt ] = $value;
			$changes[]     = 'style.' . $key;
		}
	}

	// CSS propio.
	$css_new = null;
	if ( array_key_exists( 'css', $p ) ) {
		$css_new = trim( wp_strip_all_tags( (string) $p['css'] ) );
		if ( $css_new !== (string) get_option( EG_PUB_WC_EMAIL_CSS_OPT, '' ) ) {
			$changes[] = 'css';
		} else {
			$css_new = null;
		}
	}

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
		foreach ( $style as $opt => $value ) {
			update_option( $opt, $value );
		}
		if ( null !== $css_new ) {
			if ( '' === $css_new ) {
				delete_option( EG_PUB_WC_EMAIL_CSS_OPT );
			} else {
				update_option( EG_PUB_WC_EMAIL_CSS_OPT, $css_new, false );
			}
		}
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
	$current = array();
	foreach ( array_keys( eg_pub_wc_email_style_keys() ) as $key ) {
		$current[ $key ] = $style[ 'woocommerce_email_' . $key ] ?? get_option( 'woocommerce_email_' . $key, '' );
	}

	$out = array(
		'ok'           => true,
		'applied'      => $apply && $changes,
		'changes'      => $changes,
		'notes'        => $notes,
		'style'        => $current,
		'css'          => null !== $css_new ? $css_new : (string) get_option( EG_PUB_WC_EMAIL_CSS_OPT, '' ),
		'improvements' => get_option( 'woocommerce_feature_email_improvements_enabled', '' ),
		'emails'       => $list,
	);

	if ( ! empty( $p['preview'] ) ) {
		$out['preview'] = eg_pub_wc_email_preview( (string) $p['preview'], $apply ? array() : $style, $apply ? null : $css_new, $emails );
	}
	return new WP_REST_Response( $out, 200 );
}

/**
 * HTML de un email con el pedido de ejemplo de la vista previa de WooCommerce. $style y $css son cambios aún
 * sin guardar que se aplican solo durante la vista previa.
 */
function eg_pub_wc_email_preview( $id, array $style, $css, array $emails ) {
	$class = '';
	foreach ( $emails as $key => $email ) {
		if ( $email->id === $id ) {
			$class = is_string( $key ) ? $key : get_class( $email );
		}
	}
	if ( '' === $class ) {
		return array( 'error' => 'No existe el email ' . $id . '.' );
	}
	$preview_class = 'Automattic\\WooCommerce\\Internal\\Admin\\EmailPreview\\EmailPreview';
	if ( ! class_exists( $preview_class ) || ! function_exists( 'wc_get_container' ) ) {
		return array( 'error' => 'Esta versión de WooCommerce no tiene vista previa de emails.' );
	}
	$filters = array();
	foreach ( $style as $opt => $value ) {
		$cb = function () use ( $value ) {
			return $value;
		};
		add_filter( 'pre_option_' . $opt, $cb );
		$filters[ $opt ] = $cb;
	}
	if ( null !== $css ) {
		$GLOBALS['eg_pub_wc_email_css_preview'] = $css;
	}
	try {
		$preview = wc_get_container()->get( $preview_class );
		$preview->set_email_type( $class );
		$html    = $preview->render();
		$subject = method_exists( $preview, 'get_subject' ) ? $preview->get_subject() : '';
		$result  = array( 'email' => $id, 'subject' => $subject, 'html' => $html );
	} catch ( \Throwable $e ) {
		$result = array( 'error' => $e->getMessage() );
	}
	foreach ( $filters as $opt => $cb ) {
		remove_filter( 'pre_option_' . $opt, $cb );
	}
	unset( $GLOBALS['eg_pub_wc_email_css_preview'] );
	return $result;
}
