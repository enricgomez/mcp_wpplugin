<?php
/**
 * Traslado de un blog de WordPress a esta web (tipología «Gestión de la web»):
 *   POST /wp-json/eg/v1/import-wp
 * Lee los posts publicados de otra web WordPress por su API pública (`source`, p. ej. https://viajestauro.com) y los
 * crea aquí con el mismo slug, fecha, título, extracto, categorías, etiquetas y meta descripción de Yoast. La imagen
 * destacada y las imágenes del texto se suben a esta biblioteca de medios y el texto pasa a apuntar a ellas; las
 * galerías se convierten en el shortcode [gallery] con las imágenes nuevas. Un post que ya existe (mismo slug) se
 * actualiza; las imágenes ya subidas no se duplican.
 * Por tandas: `page` y `per_page` (3 por defecto). `slugs` limita a esos posts. `status` (publish o draft) fija el
 * estado; sin él se publican. `dry_run` solo lista los posts del origen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'eg_pub_modules', function ( $modules ) {
	$modules['importar'] = array( 'type' => 'gestion', 'routes' => array( 'import-wp' ) );
	return $modules;
} );

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_module_enabled( 'importar' ) ) {
		return;
	}
	register_rest_route( 'eg/v1', '/import-wp', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_import_handle',
		'permission_callback' => eg_pub_module_permission( 'manage_options' ),
	) );
} );

/** GET a la API REST del origen: [datos, total de páginas] o WP_Error. */
function eg_pub_import_get( $source, $path, $args ) {
	$url = add_query_arg( $args, trailingslashit( $source ) . 'wp-json/wp/v2/' . $path );
	$res = wp_remote_get( $url, array( 'timeout' => 30, 'headers' => array( 'Accept' => 'application/json' ) ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$code = wp_remote_retrieve_response_code( $res );
	$data = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( 200 !== $code || ! is_array( $data ) ) {
		return new WP_Error( 'eg_import_source', 'El origen respondió ' . $code . ' en ' . $url );
	}
	return array( $data, (int) wp_remote_retrieve_header( $res, 'x-wp-totalpages' ) );
}

/** URL del archivo original de una imagen subida (sin el sufijo de tamaño -150x150). */
function eg_pub_import_original( $url ) {
	return preg_replace( '/-\d+x\d+(\.(?:jpe?g|png|webp|gif|avif))$/i', '$1', strtok( $url, '?' ) );
}

/** Sube (una sola vez) la imagen del origen y devuelve su ID aquí. */
function eg_pub_import_image( $post_id, $url, $alt, &$notes ) {
	$att = eg_pub_sideload_image( $post_id, $url, '', array( 'filename' => basename( wp_parse_url( $url, PHP_URL_PATH ) ), 'alt' => $alt ) );
	if ( is_wp_error( $att ) ) {
		$notes[] = basename( $url ) . ': ' . $att->get_error_message();
		return 0;
	}
	return (int) $att;
}

/**
 * Pasa al texto las imágenes del origen: las sube, cambia src, enlaces y clases wp-image-ID, quita srcset/sizes
 * (WordPress los rehace) y convierte las galerías en [gallery]. Quita también los data-start/data-end que dejan
 * los textos pegados de ChatGPT.
 */
function eg_pub_import_content( $html, $post_id, $host, &$notes, &$images ) {
	$is_source = function ( $url ) use ( $host ) {
		return false !== strpos( $url, '/wp-content/uploads/' ) && $host === wp_parse_url( $url, PHP_URL_HOST );
	};
	$html = preg_replace( '/\sdata-(?:start|end)="\d+"/', '', $html );

	// Galerías (cualquier tema): un div con clase «gallery», hasta su cierre, que contiene imágenes del origen.
	$offset = 0;
	while ( preg_match( '#<div\b[^>]*\sclass=([\'"])(?:(?!\1).)*\bgallery\b(?:(?!\1).)*\1[^>]*>#is', $html, $open, PREG_OFFSET_CAPTURE, $offset ) ) {
		$from  = $open[0][1];
		$end   = eg_pub_import_div_end( $html, $from );
		$block = substr( $html, $from, $end - $from );
		$repl  = eg_pub_import_gallery( $block, $post_id, $is_source, $notes, $images );
		$html  = substr( $html, 0, $from ) . $repl . substr( $html, $end );
		$offset = $from + strlen( $repl );
	}

	// Imágenes sueltas.
	$map  = array();
	$html = preg_replace_callback( '#<img\b[^>]*>#i', function ( $m ) use ( $post_id, $is_source, &$notes, &$images, &$map ) {
		$tag = $m[0];
		if ( ! preg_match( '#\ssrc=[\'"]([^\'"]+)[\'"]#i', $tag, $s ) || ! $is_source( $s[1] ) ) {
			return $tag;
		}
		$orig = eg_pub_import_original( $s[1] );
		$alt  = preg_match( '#alt=[\'"]([^\'"]*)#i', $tag, $a ) ? html_entity_decode( $a[1] ) : '';
		$id   = eg_pub_import_image( $post_id, $orig, $alt, $notes );
		if ( ! $id ) {
			return $tag;
		}
		$images[]      = $id;
		$map[ $orig ]  = wp_get_attachment_url( $id );
		$size          = preg_match( '/-(\d+)x\d+\.\w+$/', strtok( $s[1], '?' ), $w ) && (int) $w[1] < 700 ? 'medium' : 'large';
		$new           = wp_get_attachment_image_url( $id, $size );
		$tag           = preg_replace( '#\s(?:srcset|sizes)=([\'"]).*?\1#i', '', $tag );
		$tag           = preg_replace( '#\ssrc=([\'"]).*?\1#i', ' src="' . esc_url( $new ) . '"', $tag );
		$tag           = preg_replace( '/\bwp-image-\d+\b/', 'wp-image-' . $id, $tag, 1, $count );
		if ( ! $count ) {
			$tag = preg_match( '#\sclass=[\'"]#i', $tag ) ? preg_replace( '#\sclass=([\'"])#i', ' class=$1wp-image-' . $id . ' ', $tag, 1 ) : str_replace( '<img', '<img class="wp-image-' . $id . '"', $tag );
		}
		return $tag;
	}, $html );

	// Enlaces al archivo de una imagen del origen (p. ej. la imagen ampliada).
	$html = preg_replace_callback( '#href=([\'"])([^\'"]+\.(?:jpe?g|png|webp|gif|avif))\1#i', function ( $m ) use ( $is_source, $map ) {
		$orig = eg_pub_import_original( $m[2] );
		return $is_source( $m[2] ) && isset( $map[ $orig ] ) ? 'href=' . $m[1] . esc_url( $map[ $orig ] ) . $m[1] : $m[0];
	}, $html );

	return trim( $html );
}

/** Posición justo después del </div> que cierra el div que empieza en $from (o el final del texto). */
function eg_pub_import_div_end( $html, $from ) {
	$depth = 0;
	preg_match_all( '#<(/?)div\b[^>]*>#i', $html, $tags, PREG_OFFSET_CAPTURE | PREG_SET_ORDER, $from );
	foreach ( $tags as $t ) {
		$depth += '' === $t[1][0] ? 1 : -1;
		if ( 0 === $depth ) {
			return $t[0][1] + strlen( $t[0][0] );
		}
	}
	return strlen( $html );
}

/** Una galería del origen → [gallery] con las imágenes subidas aquí (o el bloque tal cual si no tiene ninguna). */
function eg_pub_import_gallery( $block, $post_id, $is_source, &$notes, &$images ) {
	preg_match_all( '#(?:<a\b[^>]*\shref=([\'"])([^\'"]+)\1[^>]*>\s*)?(<img\b[^>]*>)#i', $block, $found, PREG_SET_ORDER );
	$ids = array();
	foreach ( $found as $f ) {
		$src = preg_match( '#\ssrc=([\'"])(.*?)\1#i', $f[3], $s ) ? $s[2] : '';
		$url = ! empty( $f[2] ) && preg_match( '/\.(jpe?g|png|webp|gif|avif)(\?|$)/i', $f[2] ) ? $f[2] : $src;
		if ( ! $url || ! $is_source( $url ) ) {
			continue;
		}
		$alt = preg_match( '#\salt=([\'"])(.*?)\1#i', $f[3], $a ) ? html_entity_decode( $a[2] ) : '';
		$id  = eg_pub_import_image( $post_id, eg_pub_import_original( $url ), $alt, $notes );
		if ( $id ) {
			$ids[]    = $id;
			$images[] = $id;
		}
	}
	if ( ! $ids ) {
		return $block;
	}
	$cols = preg_match( '/gallery-columns-(\d+)/', $block, $c ) ? (int) $c[1] : 3;
	return "\n[gallery ids=\"" . implode( ',', array_unique( $ids ) ) . '" columns="' . $cols . '" size="medium" link="file"]' . "\n";
}

/** Términos del post en el origen (por _embed) → IDs aquí, creando los que falten. */
function eg_pub_import_terms( $embedded, $taxonomy ) {
	$ids = array();
	foreach ( (array) ( $embedded['wp:term'] ?? array() ) as $group ) {
		foreach ( (array) $group as $term ) {
			if ( ( $term['taxonomy'] ?? '' ) !== $taxonomy ) {
				continue;
			}
			$found = get_term_by( 'slug', $term['slug'], $taxonomy );
			if ( ! $found ) {
				$res   = wp_insert_term( html_entity_decode( $term['name'] ), $taxonomy, array( 'slug' => $term['slug'] ) );
				$found = is_wp_error( $res ) ? null : get_term( $res['term_id'], $taxonomy );
			}
			if ( $found && ! is_wp_error( $found ) ) {
				$ids[] = (int) $found->term_id;
			}
		}
	}
	return $ids;
}

function eg_pub_import_post( $src, $source, $status ) {
	$notes  = array();
	$images = array();
	$slug   = sanitize_title( $src['slug'] );
	$found  = get_posts( array( 'post_type' => 'post', 'name' => $slug, 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids', 'suppress_filters' => true ) );
	$author = eg_pub_blog_author( array(), $notes );
	$data   = array(
		'post_type'     => 'post',
		'post_title'    => wp_strip_all_tags( html_entity_decode( $src['title']['rendered'] ?? '', ENT_QUOTES ) ),
		'post_name'     => $slug,
		'post_excerpt'  => trim( wp_strip_all_tags( html_entity_decode( $src['excerpt']['rendered'] ?? '', ENT_QUOTES ) ) ),
		'post_status'   => $status,
		'post_date'     => str_replace( 'T', ' ', $src['date'] ),
		'post_date_gmt' => str_replace( 'T', ' ', $src['date_gmt'] ),
	);
	if ( $author ) {
		$data['post_author'] = $author;
	}
	if ( $found ) {
		$data['ID'] = (int) $found[0];
		$id         = wp_update_post( wp_slash( $data ), true );
	} else {
		$id = wp_insert_post( wp_slash( $data ), true );
	}
	if ( is_wp_error( $id ) ) {
		return array( 'slug' => $slug, 'error' => $id->get_error_message() );
	}

	$host    = wp_parse_url( $source, PHP_URL_HOST );
	$content = eg_pub_import_content( $src['content']['rendered'] ?? '', $id, $host, $notes, $images );
	wp_update_post( wp_slash( array( 'ID' => $id, 'post_content' => $content ) ) );

	$emb = (array) ( $src['_embedded'] ?? array() );
	wp_set_post_categories( $id, eg_pub_import_terms( $emb, 'category' ) ?: array( (int) get_option( 'default_category' ) ) );
	wp_set_post_tags( $id, eg_pub_import_terms( $emb, 'post_tag' ) );

	$featured = 0;
	$media    = $emb['wp:featuredmedia'][0] ?? null;
	if ( ! empty( $media['source_url'] ) ) {
		$featured = eg_pub_import_image( $id, $media['source_url'], $media['alt_text'] ?? '', $notes );
		if ( $featured ) {
			set_post_thumbnail( $id, $featured );
		}
	} elseif ( ! empty( $src['featured_media'] ) ) {
		$notes[] = 'la imagen destacada del origen no es accesible';
	}

	$desc = $src['yoast_head_json']['description'] ?? '';
	if ( '' !== $desc ) {
		update_post_meta( $id, '_yoast_wpseo_metadesc', sanitize_text_field( html_entity_decode( $desc, ENT_QUOTES ) ) );
	}

	// Lo que siga apuntando a imágenes del origen después del traslado.
	preg_match_all( '#https?://' . preg_quote( $host, '#' ) . '/wp-content/uploads/[^\s\'"<>)]+#i', $content, $left );
	return array(
		'slug'      => $slug,
		'id'        => (int) $id,
		'created'   => ! $found,
		'link'      => get_permalink( $id ),
		'featured'  => $featured,
		'images'    => count( array_unique( $images ) ),
		'left_urls' => array_values( array_unique( $left[0] ) ),
	) + ( $notes ? array( 'notes' => $notes ) : array() );
}

function eg_pub_import_handle( WP_REST_Request $r ) {
	$p      = (array) $r->get_json_params();
	$source = esc_url_raw( untrailingslashit( $p['source'] ?? '' ) );
	if ( ! $source || ! wp_parse_url( $source, PHP_URL_HOST ) ) {
		return eg_pub_error( 'eg_import_input', 'Falta source (URL de la web de origen).' );
	}
	if ( wp_parse_url( $source, PHP_URL_HOST ) === wp_parse_url( home_url(), PHP_URL_HOST ) ) {
		return eg_pub_error( 'eg_import_input', 'El origen es esta misma web.' );
	}
	$args = array(
		'per_page' => max( 1, min( 20, (int) ( $p['per_page'] ?? 3 ) ) ),
		'page'     => max( 1, (int) ( $p['page'] ?? 1 ) ),
		'orderby'  => 'date',
		'order'    => 'asc',
	);
	if ( ! empty( $p['slugs'] ) ) {
		$args['slug'] = implode( ',', array_map( 'sanitize_title', (array) $p['slugs'] ) );
	}
	if ( ! empty( $p['dry_run'] ) ) {
		$args['_fields']  = 'id,slug,date,title,featured_media,categories';
		$args['per_page'] = 100;
	} else {
		$args['_embed'] = 'wp:featuredmedia,wp:term';
	}
	$got = eg_pub_import_get( $source, 'posts', $args );
	if ( is_wp_error( $got ) ) {
		return eg_pub_error( $got->get_error_code(), $got->get_error_message(), 502 );
	}
	list( $posts, $pages ) = $got;
	if ( ! empty( $p['dry_run'] ) ) {
		return new WP_REST_Response( array( 'ok' => true, 'dry_run' => true, 'pages' => $pages, 'posts' => array_map( function ( $x ) {
			return array( 'id' => $x['id'], 'slug' => $x['slug'], 'date' => $x['date'], 'title' => html_entity_decode( $x['title']['rendered'] ?? '' ), 'featured_media' => $x['featured_media'] );
		}, $posts ) ) );
	}

	@set_time_limit( 300 );
	$status = in_array( $p['status'] ?? '', array( 'publish', 'draft' ), true ) ? $p['status'] : 'publish';
	$out    = array();
	$ok     = true;
	foreach ( $posts as $src ) {
		$row   = eg_pub_import_post( $src, $source, $status );
		$ok    = $ok && empty( $row['error'] );
		$out[] = $row;
	}
	return new WP_REST_Response( array( 'ok' => $ok, 'page' => $args['page'], 'pages' => $pages, 'next_page' => $args['page'] < $pages ? $args['page'] + 1 : null, 'posts' => $out ), $ok ? 200 : 207 );
}
