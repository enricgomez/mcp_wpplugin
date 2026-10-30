<?php
/**
 * Tipología «Elementor»: estilos globales del kit de Elementor, fuentes propias e imágenes, para montar una web
 * nueva desde Make sin entrar en wp-admin.
 *   POST /wp-json/eg/v1/kit
 *     Sin parámetros devuelve el kit activo (colores, tipografías y el resto de ajustes) y las fuentes propias.
 *     colors        {"primary": "#3AB0FF", "secondary": "#FFB562", "text": "#121A1A", "accent": "#FFB562"}
 *                   colores del sistema (primary, secondary, text, accent); se conservan sus nombres.
 *     custom_colors [{"_id": "gris", "title": "Gris", "color": "#E8E8EA"}] añade o cambia por _id.
 *     typography    {"primary": {"typography_font_family": "General Sans", "typography_font_weight": "600"}, ...}
 *                   tipografías del sistema (primary, secondary, text, accent), con los nombres de campo de Elementor.
 *     custom_typography [{"_id": "h1", "title": "H1", "typography_typography": "custom", ...}] añade o cambia por _id.
 *     settings      {"container_width": {"unit": "px", "size": 1312}, "button_border_radius": {...}}
 *                   cualquier otro ajuste del kit, tal cual lo guarda Elementor (se fusiona con lo que hay).
 *     fonts         [{"family": "General Sans", "css_url": "https://api.fontshare.com/v2/css?f[]=general-sans@400,500,600&display=swap"}]
 *                   descarga los woff2 de esa hoja de estilos a uploads/eg-fonts/, la guarda con las rutas locales y
 *                   añade la familia a la lista de fuentes de Elementor (grupo «Fuentes propias»). Sin css_url, la quita.
 *   POST /wp-json/eg/v1/media
 *     items [{"url": "https://…", "alt": "…", "title": "…", "key": "hero"}] descarga cada imagen a la biblioteca y
 *     devuelve su id y su URL. Con key, una segunda llamada con la misma key devuelve la imagen ya subida.
 * Como /seo, /kit solo previsualiza; guarda con apply:true y vacía la caché de CSS de Elementor.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_KIT_SYSTEM = array( 'primary', 'secondary', 'text', 'accent' );
const EG_PUB_FONTS_OPT  = 'eg_pub_fonts';

add_filter( 'eg_pub_modules', function ( $modules ) {
	$modules['kit'] = array( 'type' => 'elementor', 'routes' => array( 'kit', 'media' ) );
	return $modules;
} );

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_module_enabled( 'kit' ) ) {
		return;
	}
	register_rest_route( 'eg/v1', '/kit', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_kit_handle',
		'permission_callback' => eg_pub_module_permission( 'manage_options' ),
	) );
	register_rest_route( 'eg/v1', '/media', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_media_handle',
		'permission_callback' => eg_pub_module_permission( 'upload_files' ),
	) );
} );

/* Fuentes propias: se pintan siempre que haya alguna guardada, aunque el módulo se apague después. */

add_filter( 'elementor/fonts/groups', function ( $groups ) {
	return get_option( EG_PUB_FONTS_OPT ) ? array( 'eg_pub' => 'Fuentes propias' ) + (array) $groups : $groups;
} );

add_filter( 'elementor/fonts/additional_fonts', function ( $fonts ) {
	foreach ( array_keys( (array) get_option( EG_PUB_FONTS_OPT, array() ) ) as $family ) {
		$fonts[ $family ] = 'eg_pub';
	}
	return $fonts;
} );

function eg_pub_fonts_enqueue() {
	foreach ( (array) get_option( EG_PUB_FONTS_OPT, array() ) as $family => $font ) {
		if ( ! empty( $font['url'] ) ) {
			wp_enqueue_style( 'eg-pub-font-' . sanitize_title( $family ), $font['url'], array(), $font['ver'] ?? null );
		}
	}
}
add_action( 'wp_enqueue_scripts', 'eg_pub_fonts_enqueue' );
add_action( 'elementor/editor/after_enqueue_styles', 'eg_pub_fonts_enqueue' );
add_action( 'elementor/preview/enqueue_styles', 'eg_pub_fonts_enqueue' );

/** Descarga los woff2/woff de una hoja de @font-face y la guarda con rutas locales. Devuelve el registro de la fuente. */
function eg_pub_fonts_install( $family, $css_url ) {
	$res = wp_remote_get( $css_url, array( 'timeout' => 30 ) );
	if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
		return new WP_Error( 'eg_font_css', 'No se ha podido descargar la hoja de la fuente ' . $family . '.' );
	}
	$css = (string) wp_remote_retrieve_body( $res );
	if ( ! preg_match_all( '#url\(\s*[\'"]?([^\'")]+\.(woff2?|ttf|otf))[^\'")]*[\'"]?\s*\)#i', $css, $m, PREG_SET_ORDER ) ) {
		return new WP_Error( 'eg_font_css', 'La hoja de ' . $family . ' no tiene ficheros de fuente.' );
	}
	$up   = wp_upload_dir();
	$slug = sanitize_title( $family );
	$dir  = trailingslashit( $up['basedir'] ) . 'eg-fonts/' . $slug;
	$url  = trailingslashit( $up['baseurl'] ) . 'eg-fonts/' . $slug;
	if ( ! wp_mkdir_p( $dir ) ) {
		return new WP_Error( 'eg_font_dir', 'No se ha podido crear ' . $dir . '.' );
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	$files = 0;
	foreach ( $m as $hit ) {
		$src = $hit[1];
		$abs = 0 === strpos( $src, '//' ) ? 'https:' . $src : ( preg_match( '#^https?://#', $src ) ? $src : WP_Http::make_absolute_url( $src, $css_url ) );
		$name = sanitize_file_name( basename( wp_parse_url( $abs, PHP_URL_PATH ) ) );
		if ( ! file_exists( "$dir/$name" ) ) {
			$tmp = download_url( $abs, 60 );
			if ( is_wp_error( $tmp ) ) {
				return new WP_Error( 'eg_font_file', 'No se ha podido descargar ' . $abs . ': ' . $tmp->get_error_message() );
			}
			@rename( $tmp, "$dir/$name" ) || ( copy( $tmp, "$dir/$name" ) && @unlink( $tmp ) );
		}
		$css = str_replace( $hit[0], "url('" . esc_url_raw( "$url/$name" ) . "')", $css );
		$files++;
	}
	file_put_contents( "$dir/font.css", $css );
	return array( 'url' => "$url/font.css", 'ver' => (string) time(), 'files' => $files, 'source' => $css_url );
}

function eg_pub_kit_id() {
	$id = (int) get_option( 'elementor_active_kit' );
	return ( $id && get_post( $id ) ) ? $id : 0;
}

/** Fusiona una lista de Elementor (colores o tipografías) por _id: cambia los que existen y añade el resto. */
function eg_pub_kit_merge_list( $list, $items, $keep_title ) {
	$list = array_values( (array) $list );
	foreach ( (array) $items as $item ) {
		$item = (array) $item;
		if ( empty( $item['_id'] ) ) {
			continue;
		}
		$item['_id'] = sanitize_key( $item['_id'] );
		$found       = false;
		foreach ( $list as $i => $row ) {
			if ( isset( $row['_id'] ) && $row['_id'] === $item['_id'] ) {
				if ( $keep_title && isset( $row['title'] ) && ! isset( $item['title'] ) ) {
					$item['title'] = $row['title'];
				}
				$list[ $i ] = array_merge( $row, $item );
				$found      = true;
			}
		}
		if ( ! $found ) {
			$list[] = $item + array( 'title' => $item['_id'] );
		}
	}
	return $list;
}

function eg_pub_kit_handle( WP_REST_Request $r ) {
	if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
		return eg_pub_error( 'eg_kit', 'Elementor no está activo en esta web.' );
	}
	$kit_id = eg_pub_kit_id();
	if ( ! $kit_id ) {
		return eg_pub_error( 'eg_kit', 'No hay un kit activo de Elementor (opción elementor_active_kit).' );
	}
	$p     = $r->get_params();
	$apply = ! empty( $p['apply'] );
	$old   = get_post_meta( $kit_id, '_elementor_page_settings', true );
	$old   = is_array( $old ) ? $old : array();
	$new   = $old;

	if ( isset( $p['settings'] ) ) {
		if ( ! is_array( $p['settings'] ) ) {
			return eg_pub_error( 'eg_kit', 'settings debe ser un objeto con ajustes del kit.' );
		}
		$new = array_merge( $new, $p['settings'] );
	}
	foreach ( array( 'colors' => 'system_colors', 'typography' => 'system_typography' ) as $param => $key ) {
		if ( ! isset( $p[ $param ] ) ) {
			continue;
		}
		$items = array();
		foreach ( (array) $p[ $param ] as $id => $value ) {
			if ( ! in_array( $id, EG_PUB_KIT_SYSTEM, true ) ) {
				return eg_pub_error( 'eg_kit', $param . ': solo se admiten ' . implode( ', ', EG_PUB_KIT_SYSTEM ) . '.' );
			}
			if ( 'colors' === $param ) {
				$color = sanitize_hex_color( (string) $value );
				if ( ! $color ) {
					return eg_pub_error( 'eg_kit', 'Color no válido para ' . $id . ': ' . (string) $value );
				}
				$items[] = array( '_id' => $id, 'color' => $color );
			} else {
				$items[] = array( '_id' => $id, 'typography_typography' => 'custom' ) + (array) $value;
			}
		}
		$new[ $key ] = eg_pub_kit_merge_list( $new[ $key ] ?? array(), $items, true );
	}
	foreach ( array( 'custom_colors', 'custom_typography' ) as $key ) {
		if ( isset( $p[ $key ] ) ) {
			$new[ $key ] = eg_pub_kit_merge_list( $new[ $key ] ?? array(), $p[ $key ], false );
		}
	}

	$fonts     = (array) get_option( EG_PUB_FONTS_OPT, array() );
	$fonts_new = $fonts;
	$font_note = array();
	foreach ( (array) ( $p['fonts'] ?? array() ) as $font ) {
		$family = trim( sanitize_text_field( (string) ( $font['family'] ?? '' ) ) );
		if ( '' === $family ) {
			continue;
		}
		$css_url = esc_url_raw( (string) ( $font['css_url'] ?? '' ) );
		if ( '' === $css_url ) {
			unset( $fonts_new[ $family ] );
			$font_note[ $family ] = 'quitada';
			continue;
		}
		if ( ! $apply ) {
			$font_note[ $family ] = 'se instalará desde ' . $css_url;
			continue;
		}
		$installed = eg_pub_fonts_install( $family, $css_url );
		if ( is_wp_error( $installed ) ) {
			return eg_pub_error( $installed->get_error_code(), $installed->get_error_message() );
		}
		$fonts_new[ $family ] = $installed;
		$font_note[ $family ] = $installed['files'] . ' ficheros';
	}

	$changes = array();
	foreach ( array_unique( array_merge( array_keys( $old ), array_keys( $new ) ) ) as $key ) {
		if ( ( $old[ $key ] ?? null ) !== ( $new[ $key ] ?? null ) ) {
			$changes[ $key ] = array( 'before' => $old[ $key ] ?? null, 'after' => $new[ $key ] ?? null );
		}
	}

	if ( $apply ) {
		if ( $changes ) {
			update_post_meta( $kit_id, '_elementor_page_settings', $new );
		}
		if ( $fonts_new !== $fonts ) {
			update_option( EG_PUB_FONTS_OPT, $fonts_new, true );
		}
		if ( isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
	}

	$shown = $apply ? $new : $old;
	return new WP_REST_Response( array(
		'ok'      => true,
		'applied' => $apply,
		'kit_id'  => $kit_id,
		'elementor' => ELEMENTOR_VERSION,
		'kit'     => array(
			'system_colors'     => $shown['system_colors'] ?? array(),
			'custom_colors'     => $shown['custom_colors'] ?? array(),
			'system_typography' => $shown['system_typography'] ?? array(),
			'custom_typography' => $shown['custom_typography'] ?? array(),
			'other_keys'        => array_values( array_diff( array_keys( $shown ), array( 'system_colors', 'custom_colors', 'system_typography', 'custom_typography' ) ) ),
		),
		'fonts'   => $apply ? $fonts_new : $fonts,
		'font_changes' => $font_note,
		'changes' => $changes,
	), 200 );
}

function eg_pub_media_handle( WP_REST_Request $r ) {
	$items = $r->get_param( 'items' );
	if ( ! is_array( $items ) || ! $items ) {
		return eg_pub_error( 'eg_media', 'items debe ser una lista de {"url": …, "alt": …}.' );
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$out = array();
	foreach ( $items as $item ) {
		$item = (array) $item;
		$url  = esc_url_raw( (string) ( $item['url'] ?? '' ) );
		$key  = sanitize_key( (string) ( $item['key'] ?? '' ) );
		$row  = array( 'key' => $key, 'url' => $url );
		if ( $key ) {
			$found = get_posts( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_eg_pub_media_key', 'meta_value' => $key, 'fields' => 'ids', 'numberposts' => 1 ) );
			if ( $found ) {
				$out[] = $row + array( 'id' => (int) $found[0], 'src' => wp_get_attachment_url( $found[0] ), 'existing' => true );
				continue;
			}
		}
		$b64 = (string) ( $item['b64'] ?? '' );
		if ( '' === $url && '' === $b64 ) {
			$out[] = $row + array( 'error' => 'Falta url o b64.' );
			continue;
		}
		if ( '' !== $b64 ) {
			// Imagen ya optimizada que no está publicada en ninguna URL (p. ej. una foto de Drive reducida).
			$data = base64_decode( preg_replace( '#^data:image/\w+;base64,#', '', $b64 ), true );
			if ( false === $data ) {
				$out[] = $row + array( 'error' => 'El base64 no es válido.' );
				continue;
			}
			$tmp = wp_tempnam( 'eg-media' );
			file_put_contents( $tmp, $data );
		} else {
			$tmp = download_url( $url, 60 );
		}
		if ( is_wp_error( $tmp ) ) {
			$out[] = $row + array( 'error' => $tmp->get_error_message() );
			continue;
		}
		$name = sanitize_file_name( (string) ( $item['filename'] ?? '' ) );
		if ( '' === $name ) {
			$name = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		}
		if ( ! preg_match( '/\.(jpe?g|png|gif|webp|svg|avif)$/i', $name ) ) {
			$type = wp_get_image_mime( $tmp );
			$name = ( $key ?: 'imagen' ) . '.' . ( $type ? str_replace( array( 'image/', 'jpeg' ), array( '', 'jpg' ), $type ) : 'jpg' );
		}
		$id = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), 0, sanitize_text_field( (string) ( $item['title'] ?? '' ) ) ?: null );
		if ( is_wp_error( $id ) ) {
			@unlink( $tmp );
			$out[] = $row + array( 'error' => $id->get_error_message() );
			continue;
		}
		if ( ! empty( $item['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( (string) $item['alt'] ) );
		}
		if ( $key ) {
			update_post_meta( $id, '_eg_pub_media_key', $key );
		}
		$out[] = $row + array( 'id' => (int) $id, 'src' => wp_get_attachment_url( $id ), 'existing' => false );
	}
	return new WP_REST_Response( array( 'ok' => true, 'items' => $out ), 200 );
}
