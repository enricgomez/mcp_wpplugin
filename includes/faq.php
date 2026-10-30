<?php
/**
 * Preguntas frecuentes de los posts del blog en bloques desplegables nativos de WordPress (core/details),
 * con estilo propio y datos estructurados FAQPage.
 *
 * En /publish, `faqs: "auto"` busca en el contenido la sección cuyo H2 es «Preguntas frecuentes» (o su
 * equivalente en ca, en, fr, de) y convierte cada H3 con su respuesta en un desplegable. `faqs` también
 * admite una lista [{q, a}] (sustituye a la sección detectada o se añade al final) y `false` la deja como texto.
 * La primera vez que una web recibe `faqs`, queda activado por defecto (opción eg_pub_faq_auto): los posts que llegan
 * sin el parámetro, como los de Notion, se convierten igual si tienen la sección. `faqs_default: false` lo apaga.
 * El schema se añade al grafo de Yoast (la página pasa a WebPage + FAQPage, como el bloque FAQ de Yoast);
 * sin Yoast se imprime un JSON-LD propio. Los posts hechos con Elementor usan su widget de acordeón con FAQ Schema.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_META_FAQ     = '_eg_faq';
const EG_PUB_OPTION_FAQ_CSS = 'eg_pub_faq_css';
const EG_PUB_OPTION_FAQ_AUTO = 'eg_pub_faq_auto';
const EG_PUB_FAQ_HEADING  = '/preguntas\s+frecuentes|preguntes\s+freq[üu]ents|frequently\s+asked|\bfaqs?\b|questions\s+fr[ée]quentes|h[äa]ufig\s+gestellte|h[äa]ufige\s+fragen/iu';

/** Título por defecto de la sección según el idioma. */
function eg_pub_faq_title( $lang ) {
	$titles = array(
		'ca' => 'Preguntes freqüents',
		'en' => 'Frequently asked questions',
		'fr' => 'Questions fréquentes',
		'de' => 'Häufig gestellte Fragen',
	);
	return $titles[ $lang ] ?? 'Preguntas frecuentes';
}

/** Quita los comentarios de bloque de Gutenberg. */
function eg_pub_faq_strip_blocks( $html ) {
	return trim( preg_replace( '/<!--\s*\/?wp:[^>]*-->/', '', (string) $html ) );
}

/**
 * Busca la sección de FAQ. Devuelve array( antes, título, lista, después ) o null.
 * Las preguntas son los H3/H4 de la sección o, si ya estaba convertida, los <summary>.
 */
function eg_pub_faq_extract( $html ) {
	if ( ! preg_match_all( '/(?:<!--\s*wp:heading[^>]*-->\s*)?<h2\b[^>]*>(.*?)<\/h2>(?:\s*<!--\s*\/wp:heading\s*-->)?/is', $html, $m, PREG_OFFSET_CAPTURE ) ) {
		return null;
	}
	foreach ( $m[1] as $i => $title ) {
		$text = trim( wp_strip_all_tags( $title[0] ) );
		if ( ! preg_match( EG_PUB_FAQ_HEADING, $text ) ) {
			continue;
		}
		$start = $m[0][ $i ][1];
		$from  = $start + strlen( $m[0][ $i ][0] );
		$end   = isset( $m[0][ $i + 1 ] ) ? $m[0][ $i + 1 ][1] : strlen( $html );
		$raw   = substr( $html, $from, $end - $from );
		$end   = $from + eg_pub_faq_section_length( $raw );
		$body  = eg_pub_faq_strip_blocks( substr( $html, $from, $end - $from ) );
		$items = array();
		if ( preg_match( '/<summary\b/i', $body ) ) {
			preg_match_all( '/<summary\b[^>]*>(.*?)<\/summary>(.*?)<\/details>/is', $body, $qa, PREG_SET_ORDER );
			foreach ( $qa as $row ) {
				$items[] = array( 'q' => $row[1], 'a' => $row[2] );
			}
		} else {
			$parts = preg_split( '/<h[34]\b[^>]*>(.*?)<\/h[34]>/is', $body, -1, PREG_SPLIT_DELIM_CAPTURE );
			for ( $k = 1; $k + 1 < count( $parts ); $k += 2 ) {
				$items[] = array( 'q' => $parts[ $k ], 'a' => $parts[ $k + 1 ] );
			}
		}
		$items = eg_pub_faq_clean( $items );
		if ( ! $items ) {
			return null;
		}
		// Si ya estaba convertida, el grupo que la envolvía se rehace entero.
		$before = preg_replace( '/<!--\s*wp:group\s+\{"className":"eg-faq"[^>]*-->\s*<div class="wp-block-group eg-faq">\s*$/', '', rtrim( substr( $html, 0, $start ) ) );
		return array( rtrim( $before ), $text, $items, ltrim( substr( $html, $end ) ) );
	}
	return null;
}

/**
 * Largo de la sección de FAQ dentro del texto que va de su H2 al siguiente H2. Tras la última pregunta,
 * un texto final (p. ej. la llamada a reservar) no forma parte de la respuesta: en HTML suelto, la respuesta
 * acaba en la primera línea en blanco; con bloques de Gutenberg, en su primer bloque.
 */
function eg_pub_faq_section_length( $raw ) {
	if ( preg_match( '/<summary\b/i', $raw ) || ! preg_match_all( '/<h[34]\b[^>]*>.*?<\/h[34]>(?:\s*<!--\s*\/wp:heading\s*-->)?/is', $raw, $hs, PREG_OFFSET_CAPTURE ) ) {
		return strlen( $raw );
	}
	$last = end( $hs[0] );
	$from = $last[1] + strlen( $last[0] );
	$rest = substr( $raw, $from );
	if ( false !== strpos( $rest, '<!-- wp:' ) ) {
		if ( preg_match( '/^\s*<!--\s*wp:([a-z0-9\/-]+).*?<!--\s*\/wp:\1\s*-->/is', $rest, $b ) ) {
			return $from + strlen( $b[0] );
		}
		return strlen( $raw );
	}
	if ( preg_match( '/\S.*?(?=\n[ \t]*\n)/s', $rest, $b, PREG_OFFSET_CAPTURE ) ) {
		return $from + $b[0][1] + strlen( $b[0][0] );
	}
	return strlen( $raw );
}

/** Normaliza la lista: pregunta en texto plano, respuesta en HTML permitido; descarta las vacías. */
function eg_pub_faq_clean( $items ) {
	$out = array();
	foreach ( (array) $items as $item ) {
		$q = trim( wp_strip_all_tags( (string) ( $item['q'] ?? '' ) ) );
		$a = trim( wp_kses_post( eg_pub_faq_strip_blocks( $item['a'] ?? '' ) ) );
		if ( '' !== $q && '' !== $a ) {
			$out[] = array( 'q' => $q, 'a' => $a );
		}
	}
	return $out;
}

/** Respuesta en bloques: párrafos y listas como bloques propios, el resto como HTML. */
function eg_pub_faq_answer_blocks( $answer ) {
	if ( ! preg_match( '/<(p|ul|ol)\b/i', $answer ) ) {
		$answer = '<p>' . $answer . '</p>';
	}
	preg_match_all( '/<(p|ul|ol)\b[^>]*>.*?<\/\1>|[^<]+|<(?!(?:p|ul|ol)\b)[^>]*>/is', $answer, $chunks );
	$blocks = array();
	foreach ( $chunks[0] as $chunk ) {
		$chunk = trim( $chunk );
		if ( '' === $chunk ) {
			continue;
		}
		if ( preg_match( '/^<p\b/i', $chunk ) ) {
			$blocks[] = "<!-- wp:paragraph -->\n" . $chunk . "\n<!-- /wp:paragraph -->";
		} elseif ( preg_match( '/^<ol\b/i', $chunk ) ) {
			$blocks[] = "<!-- wp:list {\"ordered\":true} -->\n" . $chunk . "\n<!-- /wp:list -->";
		} elseif ( preg_match( '/^<ul\b/i', $chunk ) ) {
			$blocks[] = "<!-- wp:list -->\n" . $chunk . "\n<!-- /wp:list -->";
		} else {
			$blocks[] = "<!-- wp:html -->\n" . $chunk . "\n<!-- /wp:html -->";
		}
	}
	return implode( "\n\n", $blocks );
}

/** Sección de FAQ en bloques de Gutenberg: grupo con un H2 y un desplegable por pregunta. */
function eg_pub_faq_blocks( $title, $items ) {
	$html  = "<!-- wp:group {\"className\":\"eg-faq\",\"layout\":{\"type\":\"constrained\"}} -->\n<div class=\"wp-block-group eg-faq\">";
	$html .= "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . esc_html( $title ) . "</h2>\n<!-- /wp:heading -->\n\n";
	foreach ( $items as $item ) {
		$html .= "<!-- wp:details {\"className\":\"eg-faq-item\"} -->\n<details class=\"wp-block-details eg-faq-item\"><summary>" . esc_html( $item['q'] ) . "</summary>"
			. eg_pub_faq_answer_blocks( $item['a'] ) . "</details>\n<!-- /wp:details -->\n\n";
	}
	return $html . "</div>\n<!-- /wp:group -->";
}

/**
 * Aplica `faqs` de /publish al contenido ya guardado del post. Devuelve el número de preguntas
 * o una nota si no se pudo.
 */
function eg_pub_faq_apply( $post_id, $p ) {
	if ( isset( $p['faqs_default'] ) ) {
		update_option( EG_PUB_OPTION_FAQ_AUTO, rest_sanitize_boolean( $p['faqs_default'] ) ? 1 : 0, false );
	}
	if ( ! array_key_exists( 'faqs', $p ) ) {
		// Web que ya ha usado las FAQ: los posts que llegan sin `faqs` (p. ej. desde Notion) también se convierten.
		if ( ! get_option( EG_PUB_OPTION_FAQ_AUTO ) ) {
			return 0;
		}
		$found = eg_pub_faq_extract( (string) get_post_field( 'post_content', $post_id, 'raw' ) );
		if ( ! $found ) {
			delete_post_meta( $post_id, EG_PUB_META_FAQ );
			return 0;
		}
		$p['faqs'] = 'auto';
	} elseif ( ! isset( $p['faqs_default'] ) && ( 'auto' === $p['faqs'] || is_array( $p['faqs'] ) ) ) {
		update_option( EG_PUB_OPTION_FAQ_AUTO, 1, false );
	}
	$faqs    = $p['faqs'];
	$content = (string) get_post_field( 'post_content', $post_id, 'raw' );
	if ( false === $faqs || 'false' === $faqs || array() === $faqs ) {
		delete_post_meta( $post_id, EG_PUB_META_FAQ );
		return 0;
	}
	if ( false !== strpos( $content, 'wp:yoast/faq-block' ) ) {
		delete_post_meta( $post_id, EG_PUB_META_FAQ );
		return 'faqs: el contenido ya usa el bloque FAQ de Yoast; se deja como está.';
	}
	$found = eg_pub_faq_extract( $content );
	if ( is_array( $faqs ) ) {
		$items  = eg_pub_faq_clean( $faqs );
		$title  = ! empty( $p['faq_title'] ) ? wp_strip_all_tags( $p['faq_title'] ) : ( $found ? $found[1] : eg_pub_faq_title( sanitize_key( $p['lang'] ?? '' ) ) );
	} elseif ( $found ) {
		$items = $found[2];
		$title = ! empty( $p['faq_title'] ) ? wp_strip_all_tags( $p['faq_title'] ) : $found[1];
	} else {
		delete_post_meta( $post_id, EG_PUB_META_FAQ );
		return 'faqs: no hay ninguna sección con un H2 de preguntas frecuentes y sus preguntas en H3.';
	}
	if ( ! $items ) {
		return 'faqs: la lista no tiene ninguna pregunta con respuesta.';
	}
	$section = eg_pub_faq_blocks( $title, $items );
	$content = $found ? $found[0] . "\n\n" . $section . ( '' !== $found[3] ? "\n\n" . $found[3] : '' ) : rtrim( $content ) . "\n\n" . $section;
	wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_content' => $content ) ) );
	update_post_meta( $post_id, EG_PUB_META_FAQ, wp_slash( wp_json_encode( $items ) ) );
	return count( $items );
}

/** Preguntas guardadas de un post (vacío si no tiene). */
function eg_pub_faq_items( $post_id ) {
	$items = json_decode( (string) get_post_meta( $post_id, EG_PUB_META_FAQ, true ), true );
	return is_array( $items ) ? $items : array();
}

/** Piezas Question del schema, con el formato del bloque FAQ de Yoast. */
function eg_pub_faq_questions( $post_id, $url ) {
	$lang = str_replace( '_', '-', get_locale() );
	$out  = array();
	foreach ( eg_pub_faq_items( $post_id ) as $i => $item ) {
		$out[] = array(
			'@type'          => 'Question',
			'@id'            => $url . '#faq-' . ( $i + 1 ),
			'position'       => $i + 1,
			'url'            => $url . '#faq-' . ( $i + 1 ),
			'name'           => $item['q'],
			'answerCount'    => 1,
			'acceptedAnswer' => array(
				'@type'      => 'Answer',
				'text'       => wp_strip_all_tags( $item['a'] ),
				'inLanguage' => $lang,
			),
			'inLanguage'     => $lang,
		);
	}
	return $out;
}

// Yoast: la página del post pasa a WebPage + FAQPage y las preguntas entran en su grafo.
add_filter( 'wpseo_schema_graph', function ( $graph ) {
	if ( ! is_singular() ) {
		return $graph;
	}
	$post_id = get_queried_object_id();
	$url     = get_permalink( $post_id );
	$qs      = $post_id ? eg_pub_faq_questions( $post_id, $url ) : array();
	if ( ! $qs || ! is_array( $graph ) ) {
		return $graph;
	}
	foreach ( $graph as &$piece ) {
		$types = isset( $piece['@type'] ) ? (array) $piece['@type'] : array();
		if ( in_array( 'WebPage', $types, true ) ) {
			if ( ! in_array( 'FAQPage', $types, true ) ) {
				$types[] = 'FAQPage';
			}
			$piece['@type']      = $types;
			$piece['mainEntity'] = array_map( function ( $q ) {
				return array( '@id' => $q['@id'] );
			}, $qs );
			break;
		}
	}
	unset( $piece );
	return array_merge( $graph, $qs );
} );

add_action( 'wp_head', function () {
	if ( defined( 'WPSEO_VERSION' ) || ! is_singular() ) {
		return;
	}
	$post_id = get_queried_object_id();
	$qs      = $post_id ? eg_pub_faq_questions( $post_id, get_permalink( $post_id ) ) : array();
	if ( ! $qs ) {
		return;
	}
	$schema = array(
		'@context'   => 'https://schema.org',
		'@type'      => 'FAQPage',
		'mainEntity' => array_map( function ( $q ) {
			unset( $q['@id'], $q['url'], $q['position'] );
			return $q;
		}, $qs ),
	);
	echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "</script>\n";
} );

/**
 * Estilo de los desplegables: neutro, con la pregunta en el color secundario de Elementor si lo hay; `faq_css` en /publish lo sustituye por el de la marca.
 * El margen entre el título «Preguntas frecuentes» y el primer desplegable va siempre, también con un CSS de marca.
 */
function eg_pub_faq_css() {
	$spacing = '.eg-faq h2{margin:0 0 .75em}.eg-faq h2+.eg-faq-item{margin-top:.75em}';
	$custom  = (string) get_option( EG_PUB_OPTION_FAQ_CSS, '' );
	if ( '' !== $custom ) {
		return $custom . $spacing;
	}
	return '.eg-faq{margin:2.5em 0}'
		. '.eg-faq .eg-faq-item{border:1px solid rgba(0,0,0,.12);border-radius:10px;margin:0 0 12px;background:#fff;overflow:hidden;transition:box-shadow .2s}'
		. '.eg-faq .eg-faq-item[open]{box-shadow:0 6px 18px rgba(0,0,0,.08);border-color:rgba(0,0,0,.25)}'
		. '.eg-faq .eg-faq-item>summary{list-style:none;cursor:pointer;position:relative;padding:16px 52px 16px 20px;font-weight:600;line-height:1.4;color:var(--e-global-color-secondary,inherit)}'
		. '.eg-faq .eg-faq-item>summary::-webkit-details-marker{display:none}'
		. '.eg-faq .eg-faq-item>summary::after{content:"+";position:absolute;right:20px;top:50%;transform:translateY(-50%);font-size:24px;font-weight:400;line-height:1;color:currentColor}'
		. '.eg-faq .eg-faq-item[open]>summary::after{content:"\2212"}'
		. '.eg-faq .eg-faq-item>summary:focus-visible{outline:2px solid currentColor;outline-offset:-2px}'
		. '.eg-faq .eg-faq-item>:not(summary){margin:0;padding:0 20px 16px}'
		. '.eg-faq .eg-faq-item>summary+*{padding-top:2px}'
		. $spacing;
}

add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_singular() || ! eg_pub_faq_items( get_queried_object_id() ) ) {
		return;
	}
	wp_register_style( 'eg-pub-faq', false, array(), EG_PUB_VERSION );
	wp_enqueue_style( 'eg-pub-faq' );
	wp_add_inline_style( 'eg-pub-faq', eg_pub_faq_css() );
} );
