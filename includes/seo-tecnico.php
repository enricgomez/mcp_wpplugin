<?php
/**
 * Tipología «Gestión de la web»: SEO técnico de toda la web.
 *   POST /wp-json/eg/v1/seo-tech
 * Sin parámetros devuelve el estado: negocio local guardado, líneas añadidas a robots.txt, si hay un robots.txt
 * físico (que tapa el virtual de WordPress) y si WPML imprime los hreflang en el <head>.
 *   inspect   ["/serveis/", "https://…/es/…"]  lee el HTML de esas URL de la propia web (máx. 20) y devuelve
 *             título, canonical, hreflang, meta robots, tipos de JSON-LD (también los del @graph de Yoast),
 *             preloads y las primeras imágenes con loading / fetchpriority. Con "robots_txt": true, también robots.txt.
 *   business  {"types": ["VeterinaryCare"], "name", "telephone", "address": {…}, "openingHoursSpecification": […], …}
 *             datos del negocio local en schema.org. Con Yoast se fusionan en su Organization (una sola entidad, sin
 *             duplicar); sin Yoast se imprime un JSON-LD propio en la portada. null lo quita.
 *   robots    "líneas" que se añaden al final del robots.txt virtual de WordPress (p. ej. reglas para bots de IA). "" lo quita.
 *   hreflang  true/false: activa o desactiva en WPML «mostrar idiomas alternativos en el HEAD».
 * Como /seo y /rocket, los cambios solo se previsualizan; apply:true guarda y vacía la caché de WP Rocket.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_OPTION_BUSINESS = 'eg_pub_schema_business';
const EG_PUB_OPTION_ROBOTS   = 'eg_pub_robots_extra';

add_filter( 'eg_pub_modules', function ( $modules ) {
	$modules['seo-tecnico'] = array( 'type' => 'gestion', 'routes' => array( 'seo-tech' ) );
	return $modules;
} );

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_module_enabled( 'seo-tecnico' ) ) {
		return;
	}
	register_rest_route( 'eg/v1', '/seo-tech', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_seo_tech_handle',
		'permission_callback' => eg_pub_module_permission( 'manage_options' ),
	) );
} );

/** Limpia en profundidad los datos del negocio: textos sin HTML y URLs válidas. */
function eg_pub_seo_tech_clean( $value, $key = '' ) {
	if ( is_array( $value ) ) {
		$out = array();
		foreach ( $value as $k => $v ) {
			$out[ is_int( $k ) ? $k : preg_replace( '/[^A-Za-z0-9@_]/', '', (string) $k ) ] = eg_pub_seo_tech_clean( $v, is_int( $k ) ? $key : (string) $k );
		}
		return $out;
	}
	if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
		return $value;
	}
	$value = (string) $value;
	return in_array( $key, array( 'url', 'sameAs', 'image', 'logo', 'hasMap' ), true ) ? esc_url_raw( $value ) : sanitize_text_field( $value );
}

/** Datos del negocio guardados (sin la clave «types»), y sus tipos. */
function eg_pub_seo_tech_business() {
	$data = get_option( EG_PUB_OPTION_BUSINESS, array() );
	if ( ! is_array( $data ) || ! $data ) {
		return array( array(), array() );
	}
	$types = array_values( array_filter( (array) ( $data['types'] ?? array() ), 'is_string' ) );
	unset( $data['types'], $data['@type'], $data['@id'], $data['@context'] );
	return array( $data, $types );
}

// Yoast: el negocio se fusiona en su Organization, así hay una sola entidad para la web.
add_filter( 'wpseo_schema_graph', function ( $graph ) {
	list( $data, $types ) = eg_pub_seo_tech_business();
	if ( ! $data || ! is_array( $graph ) ) {
		return $graph;
	}
	foreach ( $graph as &$piece ) {
		$piece_types = isset( $piece['@type'] ) ? (array) $piece['@type'] : array();
		if ( in_array( 'Organization', $piece_types, true ) ) {
			$piece['@type'] = array_values( array_unique( array_merge( $piece_types, $types ) ) );
			$piece          = array_merge( $piece, $data );
			unset( $piece );
			return $graph;
		}
	}
	unset( $piece );
	// Sin Organization en Yoast (web configurada como persona): el negocio va como pieza propia.
	$graph[] = array_merge( array( '@type' => $types ? $types : 'LocalBusiness', '@id' => home_url( '/#localbusiness' ) ), $data );
	return $graph;
} );

add_action( 'wp_head', function () {
	if ( defined( 'WPSEO_VERSION' ) || ! is_front_page() ) {
		return;
	}
	list( $data, $types ) = eg_pub_seo_tech_business();
	if ( ! $data ) {
		return;
	}
	$schema = array_merge( array( '@context' => 'https://schema.org', '@type' => $types ? $types : 'LocalBusiness' ), $data );
	echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "</script>\n";
} );

add_filter( 'robots_txt', function ( $output ) {
	$extra = trim( (string) get_option( EG_PUB_OPTION_ROBOTS, '' ) );
	return '' === $extra ? $output : rtrim( $output ) . "\n\n" . $extra . "\n";
}, 99 );

/** Lee una URL de la propia web y resume lo que importa para el SEO técnico. */
function eg_pub_seo_tech_inspect_url( $url ) {
	$started = microtime( true );
	$res = wp_remote_get( $url, array( 'timeout' => 20, 'redirection' => 0, 'headers' => array( 'User-Agent' => 'Mozilla/5.0 (Linux; Android 12) EG-Publicacion/' . EG_PUB_VERSION ) ) );
	if ( is_wp_error( $res ) ) {
		return array( 'url' => $url, 'error' => $res->get_error_message() );
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$html = (string) wp_remote_retrieve_body( $res );
	$out  = array( 'url' => $url, 'status' => $code );
	if ( $code >= 300 && $code < 400 ) {
		$out['location'] = wp_remote_retrieve_header( $res, 'location' );
		return $out;
	}
	$head = preg_match( '#<head[^>]*>(.*?)</head>#is', $html, $m ) ? $m[1] : $html;
	$attr = function ( $tag, $name ) {
		return preg_match( '/\s' . $name . '\s*=\s*(["\'])(.*?)\1/is', $tag, $a ) ? html_entity_decode( $a[2] ) : null;
	};
	$out['title'] = preg_match( '#<title[^>]*>(.*?)</title>#is', $head, $t ) ? trim( html_entity_decode( wp_strip_all_tags( $t[1] ) ) ) : null;
	$out['canonical'] = array();
	$out['hreflang']  = array();
	$out['preload']   = array();
	preg_match_all( '#<link\b[^>]*>#i', $head, $links );
	foreach ( $links[0] as $tag ) {
		$rel = strtolower( (string) $attr( $tag, 'rel' ) );
		if ( 'canonical' === $rel ) {
			$out['canonical'][] = $attr( $tag, 'href' );
		} elseif ( 'alternate' === $rel && null !== $attr( $tag, 'hreflang' ) ) {
			$out['hreflang'][ $attr( $tag, 'hreflang' ) ] = $attr( $tag, 'href' );
		} elseif ( 'preload' === $rel ) {
			$out['preload'][] = array_filter( array( 'as' => $attr( $tag, 'as' ), 'href' => $attr( $tag, 'href' ), 'imagesrcset' => $attr( $tag, 'imagesrcset' ) ? 'sí' : null, 'fetchpriority' => $attr( $tag, 'fetchpriority' ) ) );
		}
	}
	$out['robots'] = array();
	preg_match_all( '#<meta\b[^>]*>#i', $head, $metas );
	foreach ( $metas[0] as $tag ) {
		$name = strtolower( (string) $attr( $tag, 'name' ) );
		if ( in_array( $name, array( 'robots', 'googlebot' ), true ) ) {
			$out['robots'][ $name ] = $attr( $tag, 'content' );
		}
	}
	$out['x_robots_tag'] = wp_remote_retrieve_header( $res, 'x-robots-tag' ) ? wp_remote_retrieve_header( $res, 'x-robots-tag' ) : null;
	// Cabeceras de caché: dicen si la página sale de Cloudflare, de WP Rocket o de PHP, y cuánto tarda el servidor.
	$out['cache'] = array();
	foreach ( array( 'cf-cache-status', 'cache-control', 'age', 'server', 'x-powered-by' ) as $h ) {
		$v = wp_remote_retrieve_header( $res, $h );
		if ( $v ) {
			$out['cache'][ $h ] = is_array( $v ) ? implode( ', ', $v ) : $v;
		}
	}
	$out['cache']['rocket'] = false !== strpos( $html, 'This website is like a Rocket' );
	$out['cache']['seconds'] = round( microtime( true ) - $started, 2 );
	$out['jsonld'] = array();
	preg_match_all( '#<script[^>]+application/ld\+json[^>]*>(.*?)</script>#is', $html, $scripts );
	foreach ( $scripts[1] as $json ) {
		$data = json_decode( trim( $json ), true );
		if ( ! is_array( $data ) ) {
			$out['jsonld'][] = 'JSON no válido';
			continue;
		}
		$nodes = isset( $data['@graph'] ) ? (array) $data['@graph'] : ( isset( $data[0] ) ? $data : array( $data ) );
		$block = array();
		foreach ( $nodes as $node ) {
			if ( is_array( $node ) && isset( $node['@type'] ) ) {
				$item = implode( '+', (array) $node['@type'] );
				if ( isset( $node['mainEntity'] ) && is_array( $node['mainEntity'] ) ) {
					$item .= ' (' . count( $node['mainEntity'] ) . ' preguntas)';
				}
				if ( isset( $node['address'] ) ) {
					$item .= ' (con dirección)';
				}
				if ( isset( $node['openingHoursSpecification'] ) || isset( $node['openingHours'] ) ) {
					$item .= ' (con horario)';
				}
				$block[] = $item;
			}
		}
		$out['jsonld'][] = implode( ', ', $block );
	}
	$body = preg_match( '#<body[^>]*>(.*)#is', $html, $b ) ? $b[1] : $html;
	preg_match_all( '#<img\b[^>]*>#i', $body, $imgs );
	$out['images'] = array();
	foreach ( array_slice( $imgs[0], 0, 6 ) as $tag ) {
		$src = $attr( $tag, 'src' );
		if ( $src && 0 === strpos( $src, 'data:' ) ) {
			$src = 'data:… (data-lazy-src: ' . $attr( $tag, 'data-lazy-src' ) . ')';
		}
		$out['images'][] = array_filter( array(
			'src'           => $src,
			'loading'       => $attr( $tag, 'loading' ),
			'fetchpriority' => $attr( $tag, 'fetchpriority' ),
			'width'         => $attr( $tag, 'width' ),
			'height'        => $attr( $tag, 'height' ),
			'class'         => $attr( $tag, 'class' ) ? mb_substr( $attr( $tag, 'class' ), 0, 80 ) : null,
		) );
	}
	$out['bytes'] = strlen( $html );
	return $out;
}

/** Ajuste de WPML «mostrar idiomas alternativos en el HEAD» (null si WPML no está). */
function eg_pub_seo_tech_wpml_hreflang() {
	if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
		return null;
	}
	$settings = get_option( 'icl_sitepress_settings', array() );
	return ! empty( $settings['seo']['head_langs'] );
}

function eg_pub_seo_tech_handle( WP_REST_Request $r ) {
	$p     = $r->get_params();
	$apply = ! empty( $p['apply'] );
	$out   = array( 'ok' => true, 'applied' => false, 'changes' => array() );

	if ( isset( $p['inspect'] ) ) {
		$urls = array_slice( array_values( array_filter( (array) $p['inspect'], 'is_string' ) ), 0, 20 );
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$out['pages'] = array();
		foreach ( $urls as $url ) {
			$url = 0 === strpos( $url, '/' ) ? untrailingslashit( get_option( 'home' ) ) . $url : $url;
			if ( wp_parse_url( $url, PHP_URL_HOST ) !== $host ) {
				$out['pages'][] = array( 'url' => $url, 'error' => 'Solo URLs de esta web (' . $host . ').' );
				continue;
			}
			$out['pages'][] = eg_pub_seo_tech_inspect_url( $url );
		}
	}
	if ( ! empty( $p['robots_txt'] ) ) {
		$res = wp_remote_get( home_url( '/robots.txt' ), array( 'timeout' => 15 ) );
		$out['robots_txt'] = is_wp_error( $res ) ? $res->get_error_message() : wp_remote_retrieve_body( $res );
	}

	if ( array_key_exists( 'business', $p ) ) {
		$before = get_option( EG_PUB_OPTION_BUSINESS, array() );
		$after  = is_array( $p['business'] ) ? eg_pub_seo_tech_clean( $p['business'] ) : array();
		if ( $before !== $after ) {
			$out['changes']['business'] = array( 'before' => $before, 'after' => $after );
			if ( $apply ) {
				update_option( EG_PUB_OPTION_BUSINESS, $after, false );
			}
		}
	}
	if ( array_key_exists( 'robots', $p ) ) {
		$before = (string) get_option( EG_PUB_OPTION_ROBOTS, '' );
		$after  = trim( implode( "\n", array_map( 'sanitize_text_field', preg_split( '/\r\n|\r|\n/', (string) $p['robots'] ) ) ) );
		if ( $before !== $after ) {
			$out['changes']['robots'] = array( 'before' => $before, 'after' => $after );
			if ( $apply ) {
				update_option( EG_PUB_OPTION_ROBOTS, $after, false );
			}
		}
	}
	if ( array_key_exists( 'hreflang', $p ) ) {
		$before = eg_pub_seo_tech_wpml_hreflang();
		if ( null === $before ) {
			return eg_pub_error( 'eg_wpml', 'WPML no está activo en esta web.' );
		}
		$after = rest_sanitize_boolean( $p['hreflang'] );
		if ( $before !== $after ) {
			$out['changes']['hreflang'] = array( 'before' => $before, 'after' => $after );
			if ( $apply ) {
				$settings                       = get_option( 'icl_sitepress_settings', array() );
				$settings['seo']['head_langs'] = $after ? 1 : 0;
				update_option( 'icl_sitepress_settings', $settings );
			}
		}
	}
	if ( $apply && $out['changes'] ) {
		$out['applied'] = true;
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
	}

	$out['state'] = array(
		'business'        => get_option( EG_PUB_OPTION_BUSINESS, array() ),
		'robots'          => (string) get_option( EG_PUB_OPTION_ROBOTS, '' ),
		'robots_physical' => file_exists( ABSPATH . 'robots.txt' ),
		'wpml_hreflang'   => eg_pub_seo_tech_wpml_hreflang(),
		'yoast'           => defined( 'WPSEO_VERSION' ) ? WPSEO_VERSION : null,
		'yoast_company'   => defined( 'WPSEO_VERSION' ) ? ( (array) get_option( 'wpseo_titles', array() ) )['company_or_person'] ?? null : null,
	);
	return new WP_REST_Response( $out, 200 );
}
