<?php
/**
 * Enlaces por idioma (tipología «Gestión de la web»):
 *   POST /wp-json/eg/v1/lang-links
 * Escribe en la web el enlace correcto de cada idioma, por ejemplo el botón Reservar de la cabecera hacia el motor
 * de reservas en cada idioma. El cambio queda guardado en el JSON de Elementor (y en el contenido) de cada
 * traducción (y en los elementos de menú con enlace personalizado y en las traducciones de cadenas de WPML cuyo
 * original es esa URL, como un campo de opciones): el plugin no hace nada al servir las páginas, así que si se desactiva los enlaces siguen bien.
 *
 *   from     URL tal como está ahora (https://engine.witbooking.com/property/campingelfar).
 *   to       URL con {lang} (https://engine.witbooking.com/{lang}/property/campingelfar).
 *   langs    opcional: código del destino para cada idioma de WPML si no coincide ({"ca": "ca"}).
 *   skip     opcional: idiomas que no se tocan (por defecto el principal de WPML).
 *   ids      opcional: solo esas entradas o plantillas (y sus traducciones).
 *   translate_templates  opcional: IDs de plantillas de Elementor (cabecera, pie...) que, en los idiomas que
 *            aún no tienen traducción, se duplican desde el original y se enlazan en WPML, para poder poner
 *            allí el enlace del idioma. El texto queda como en el original (igual que se ve hoy en esos idiomas).
 *   dry_run  no guarda nada y devuelve qué cambiaría.
 * Se guarda sin wp_update_post, así WPML no lanza la traducción automática.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'eg_pub_modules', function ( $modules ) {
	$modules['enlaces'] = array( 'type' => 'gestion', 'routes' => array( 'lang-links', 'internal-links', 'wpml-strings' ) );
	return $modules;
} );

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_module_enabled( 'enlaces' ) ) {
		return;
	}
	register_rest_route( 'eg/v1', '/lang-links', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_lang_links_handle',
		'permission_callback' => eg_pub_module_permission( 'manage_options' ),
	) );
	register_rest_route( 'eg/v1', '/internal-links', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_internal_links_handle',
		'permission_callback' => eg_pub_module_permission( 'manage_options' ),
	) );
	register_rest_route( 'eg/v1', '/wpml-strings', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_wpml_strings_handle',
		'permission_callback' => eg_pub_module_permission( 'manage_options' ),
	) );
} );

function eg_pub_lang_links_lang( $id ) {
	return (string) apply_filters( 'wpml_element_language_code', null, array( 'element_id' => $id, 'element_type' => get_post_type( $id ) ) );
}

/** Traducciones de WPML de una entrada: idioma => ID (incluida ella misma). */
function eg_pub_lang_links_translations( $id ) {
	$type = 'post_' . get_post_type( $id );
	$trid = apply_filters( 'wpml_element_trid', null, $id, $type );
	$out  = array();
	foreach ( $trid ? (array) apply_filters( 'wpml_get_element_translations', null, $trid, $type ) : array() as $lang => $t ) {
		$out[ $lang ] = (int) $t->element_id;
	}
	if ( ! $out ) {
		$out[ eg_pub_lang_links_lang( $id ) ] = (int) $id;
	}
	return $out;
}

/** Duplica una plantilla de Elementor a un idioma de WPML y la enlaza como traducción. Devuelve el ID nuevo. */
function eg_pub_lang_links_duplicate( $source_id, $lang ) {
	$src = get_post( $source_id );
	$id  = wp_insert_post( wp_slash( array(
		'post_type'    => $src->post_type,
		'post_status'  => $src->post_status,
		'post_title'   => $src->post_title . ' (' . strtoupper( $lang ) . ')',
		'post_content' => $src->post_content,
		'post_author'  => $src->post_author,
	) ), true );
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	$skip = array( '_edit_lock', '_edit_last', '_elementor_css', '_elementor_element_cache', '_elementor_page_assets', '_wp_old_slug' );
	foreach ( get_post_meta( $source_id ) as $key => $values ) {
		if ( in_array( $key, $skip, true ) || 0 === strpos( $key, '_wpml_' ) || 0 === strpos( $key, '_icl_' ) ) {
			continue;
		}
		foreach ( $values as $value ) {
			add_post_meta( $id, $key, wp_slash( maybe_unserialize( $value ) ) );
		}
	}
	foreach ( get_object_taxonomies( $src->post_type ) as $tax ) {
		$terms = wp_get_object_terms( $source_id, $tax, array( 'fields' => 'ids' ) );
		if ( $terms && ! is_wp_error( $terms ) ) {
			wp_set_object_terms( $id, $terms, $tax );
		}
	}
	$type = 'post_' . $src->post_type;
	do_action( 'wpml_set_element_language_details', array(
		'element_id'           => $id,
		'element_type'         => $type,
		'trid'                 => apply_filters( 'wpml_element_trid', null, $source_id, $type ),
		'language_code'        => $lang,
		'source_language_code' => eg_pub_lang_links_lang( $source_id ),
	) );
	return (int) $id;
}

/** URLs del motor que hay en un texto (también en la forma escapada del JSON). */
function eg_pub_lang_links_urls( $str, $host ) {
	preg_match_all( '#https?:(?:\\\\/|/){2}[^"\'\s<>]*' . preg_quote( $host, '#' ) . '[^"\'\s<>]*#i', (string) $str, $m );
	return array_values( array_unique( array_map( function ( $u ) {
		return str_replace( '\/', '/', $u );
	}, $m[0] ) ) );
}

function eg_pub_lang_links_handle( WP_REST_Request $r ) {
	global $wpdb;
	$p       = $r->get_params();
	$dry_run = ! empty( $p['dry_run'] );
	$from    = esc_url_raw( trim( (string) ( $p['from'] ?? '' ) ) );
	$to      = trim( (string) ( $p['to'] ?? '' ) );
	if ( '' === $from || false === strpos( $to, '{lang}' ) ) {
		return eg_pub_error( 'eg_missing', 'Faltan from (URL actual) y to (URL con {lang}).' );
	}
	$host    = (string) wp_parse_url( $from, PHP_URL_HOST );
	$default = (string) apply_filters( 'wpml_default_language', null );
	$skip    = isset( $p['skip'] ) ? array_map( 'sanitize_key', (array) $p['skip'] ) : array( $default );
	$codes   = is_array( $p['langs'] ?? null ) ? $p['langs'] : array();
	$active  = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
	$active  = is_array( $active ) ? array_keys( $active ) : array();
	$notes   = array();

	// Plantillas que hay que traducir a los idiomas que no las tienen.
	$created = array();
	foreach ( array_map( 'intval', (array) ( $p['translate_templates'] ?? array() ) ) as $tpl ) {
		if ( 'elementor_library' !== get_post_type( $tpl ) ) {
			$notes[] = $tpl . ': no es una plantilla de Elementor';
			continue;
		}
		$have = eg_pub_lang_links_translations( $tpl );
		$orig = isset( $have[ $default ] ) ? $have[ $default ] : $tpl;
		foreach ( array_diff( $active, array_keys( $have ), $skip ) as $lang ) {
			$new                            = $dry_run ? 0 : eg_pub_lang_links_duplicate( $orig, $lang );
			$created[ $tpl . ':' . $lang ] = is_wp_error( $new ) ? $new->get_error_message() : $new;
		}
	}

	// Entradas donde está la URL (y, si se pidieron ids, solo esas y sus traducciones).
	$like = '%' . $wpdb->esc_like( $from ) . '%';
	$esc  = '%' . $wpdb->esc_like( str_replace( '/', '\/', $from ) ) . '%';
	$ids  = array_map( 'intval', $wpdb->get_col( $wpdb->prepare(
		"SELECT DISTINCT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key IN ('_elementor_data','_menu_item_url')
		WHERE p.post_type <> 'revision' AND p.post_status NOT IN ('trash','auto-draft','inherit')
		AND ( p.post_content LIKE %s OR p.post_content LIKE %s OR m.meta_value LIKE %s OR m.meta_value LIKE %s )",
		$like, $esc, $like, $esc
	) ) );
	// Dónde más aparece el dominio (otras metas, opciones, cadenas de WPML), para saber qué no se cambia.
	$hlike     = '%' . $wpdb->esc_like( $host ) . '%';
	$elsewhere = array(
		'meta'    => $wpdb->get_results( $wpdb->prepare( "SELECT m.post_id, p.post_type, m.meta_key, LEFT(m.meta_value, 300) AS value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_value LIKE %s AND m.meta_key NOT IN ('_elementor_data','_menu_item_url','_elementor_element_cache','_elementor_css') AND p.post_type <> 'revision' LIMIT 30", $hlike ), ARRAY_A ),
		'options' => $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_value LIKE %s AND option_name NOT LIKE '%%transient%%' LIMIT 30", $hlike ) ),
	);
	if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}icl_strings'" ) ) {
		$elsewhere['wpml_strings'] = $wpdb->get_results( $wpdb->prepare( "SELECT s.id, s.context, s.name, s.value, GROUP_CONCAT(CONCAT(t.language, ':', t.status, ':', t.value) SEPARATOR ' | ') AS translations FROM {$wpdb->prefix}icl_strings s LEFT JOIN {$wpdb->prefix}icl_string_translations t ON t.string_id = s.id WHERE s.value LIKE %s OR t.value LIKE %s GROUP BY s.id LIMIT 30", $hlike, $hlike ), ARRAY_A );
	}
	if ( ! empty( $p['ids'] ) ) {
		$only = array();
		foreach ( (array) $p['ids'] as $id ) {
			$only = array_merge( $only, array_values( eg_pub_lang_links_translations( (int) $id ) ) );
		}
		$ids = array_values( array_intersect( $ids, $only ) );
	}

	$changed = array();
	$kept    = array();
	foreach ( $ids as $id ) {
		$lang = eg_pub_lang_links_lang( $id );
		$row  = array( 'id' => $id, 'type' => get_post_type( $id ), 'title' => get_the_title( $id ), 'lang' => $lang );
		$code = array_key_exists( $lang, $codes ) ? (string) $codes[ $lang ] : $lang;
		if ( '' === $lang || '' === $code || in_array( $lang, $skip, true ) ) {
			$kept[] = $row + array( 'urls' => eg_pub_lang_links_urls( get_post_meta( $id, '_elementor_data', true ) . ' ' . get_post_meta( $id, '_menu_item_url', true ) . ' ' . get_post_field( 'post_content', $id ), $host ) );
			continue;
		}
		$new_url = str_replace( '{lang}', $code, $to );
		$pairs   = array( $from => $new_url, str_replace( '/', '\/', $from ) => str_replace( '/', '\/', $new_url ) );
		$count   = 0;
		$data    = (string) get_post_meta( $id, '_elementor_data', true );
		$content = (string) get_post_field( 'post_content', $id );
		$data2   = strtr( $data, $pairs );
		$content2 = strtr( $content, $pairs );
		$menu    = (string) get_post_meta( $id, '_menu_item_url', true );
		$menu2   = strtr( $menu, $pairs );
		$count   = ( $data2 !== $data ? 1 : 0 ) + ( $content2 !== $content ? 1 : 0 ) + ( $menu2 !== $menu ? 1 : 0 );
		if ( $count && ! $dry_run ) {
			if ( $data2 !== $data ) {
				update_post_meta( $id, '_elementor_data', wp_slash( $data2 ) );
				delete_post_meta( $id, '_elementor_css' );
				delete_post_meta( $id, '_elementor_element_cache' );
			}
			if ( $menu2 !== $menu ) {
				update_post_meta( $id, '_menu_item_url', wp_slash( $menu2 ) );
			}
			if ( $content2 !== $content ) {
				$wpdb->update( $wpdb->posts, array( 'post_content' => $content2 ), array( 'ID' => $id ) );
			}
			clean_post_cache( $id );
		}
		if ( $count ) {
			$changed[] = $row + array( 'to' => $new_url );
		} else {
			$kept[] = $row + array( 'urls' => array() );
		}
	}

	// Cadenas de WPML (p. ej. un campo de opciones traducido como «admin text») cuyo original es la URL: se guarda
	// como traducción completa de cada idioma el enlace de ese idioma.
	$strings = array();
	if ( $wpdb->get_var( "SHOW TABLES LIKE '{$wpdb->prefix}icl_strings'" ) ) {
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT id, context, name, value FROM {$wpdb->prefix}icl_strings WHERE value = %s OR value = %s", $from, untrailingslashit( $from ) . '/' ) ) as $str ) {
			foreach ( $active as $lang ) {
				$code = array_key_exists( $lang, $codes ) ? (string) $codes[ $lang ] : $lang;
				if ( in_array( $lang, $skip, true ) || '' === $code ) {
					continue;
				}
				$new_url = str_replace( '{lang}', $code, $to );
				$old     = (string) $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$wpdb->prefix}icl_string_translations WHERE string_id = %d AND language = %s", $str->id, $lang ) );
				if ( $old === $new_url ) {
					continue;
				}
				if ( ! $dry_run ) {
					if ( function_exists( 'icl_add_string_translation' ) ) {
						icl_add_string_translation( (int) $str->id, $lang, $new_url, defined( 'ICL_TM_COMPLETE' ) ? ICL_TM_COMPLETE : 10 );
					} else {
						$wpdb->replace( $wpdb->prefix . 'icl_string_translations', array( 'string_id' => (int) $str->id, 'language' => $lang, 'status' => 10, 'value' => $new_url ) );
					}
				}
				$strings[] = array( 'string' => (int) $str->id, 'name' => $str->name, 'context' => $str->context, 'lang' => $lang, 'from' => $old, 'to' => $new_url );
			}
		}
		if ( $strings && ! $dry_run ) {
			wp_cache_flush();
		}
	}

	if ( ( $changed || $created || $strings ) && ! $dry_run ) {
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
	}

	return new WP_REST_Response( array(
		'ok'               => true,
		'dry_run'          => $dry_run,
		'default_language' => $default,
		'languages'        => $active,
		'created'          => $created,
		'changed'          => $changed,
		'kept'             => $kept,
		'strings'          => $strings,
		'elsewhere'        => $elsewhere,
		'message'          => implode( ' | ', $notes ),
	), 200 );
}

/*
 * Enlaces internos escritos a mano → etiqueta dinámica «Enlace interno» de Elementor:
 *   POST /wp-json/eg/v1/internal-links
 * Busca en el JSON de Elementor de todas las páginas y plantillas (o de `ids`) los enlaces de los widgets (botón,
 * imagen, título, listas de iconos y cualquier control de enlace, también dentro de repetidores) que apuntan a una
 * entrada de esta web, y en los menús los elementos con enlace personalizado a una entrada de la web. Sin `apply`
 * solo lo lista. Con `apply: true` pone en cada enlace la etiqueta dinámica internal-url con el ID de la entrada
 * (la URL escrita queda como respaldo) y pasa los elementos de menú a enlace de entrada. Así, si cambia una URL o
 * se traduce con WPML, el enlace sigue bien.
 *   domains  opcional: otros dominios que cuentan como internos (p. ej. el de la web antigua antes de migrar).
 *   ids      opcional: solo esas entradas o plantillas.
 * No toca enlaces con #ancla o parámetros, ni tel:, mailto: o externos; los que no corresponden a ninguna entrada
 * salen en `unresolved`. Antes de guardar deja el JSON anterior en _eg_pub_elementor_backup.
 */
function eg_pub_internal_links_handle( WP_REST_Request $r ) {
	global $wpdb;
	$p       = (array) $r->get_json_params();
	$apply   = ! empty( $p['apply'] );
	$hosts   = array();
	foreach ( array_merge( array( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ), (array) ( $p['domains'] ?? array() ) ) as $h ) {
		$h = strtolower( preg_replace( '#^(https?://)?(www\.)?#i', '', trim( (string) $h, '/ ' ) ) );
		if ( $h ) {
			$hosts[ $h ] = true;
		}
	}
	$ids = array_filter( array_map( 'absint', (array) ( $p['ids'] ?? array() ) ) );
	$sql = "SELECT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = '_elementor_data' AND p.post_type <> 'revision' AND p.post_status IN ('publish','draft','private','future','pending')";
	$all = array_map( 'intval', $wpdb->get_col( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	if ( $ids ) {
		$all = array_values( array_intersect( $all, $ids ) );
	}

	$out = array( 'ok' => true, 'apply' => $apply, 'docs' => array(), 'menus' => array(), 'unresolved' => array(), 'converted' => 0, 'pending' => 0 );
	foreach ( $all as $id ) {
		$raw  = get_post_meta( $id, '_elementor_data', true );
		$data = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		if ( ! is_array( $data ) ) {
			continue;
		}
		$found = array();
		eg_pub_internal_links_walk( $data, $hosts, $apply, $found );
		if ( ! $found ) {
			continue;
		}
		$doc = array( 'id' => $id, 'title' => get_the_title( $id ), 'type' => get_post_type( $id ), 'links' => array() );
		$changed = false;
		foreach ( $found as $f ) {
			if ( empty( $f['post_id'] ) ) {
				$out['unresolved'][] = array( 'doc' => $id ) + $f;
				continue;
			}
			$doc['links'][] = $f;
			$changed        = $changed || $apply;
			$out[ $apply ? 'converted' : 'pending' ]++;
		}
		if ( $doc['links'] ) {
			$out['docs'][] = $doc;
		}
		if ( $changed ) {
			update_post_meta( $id, '_eg_pub_elementor_backup', wp_slash( wp_json_encode( array( 'time' => time(), 'title' => get_the_title( $id ), 'data' => is_string( $raw ) ? $raw : wp_json_encode( $raw ) ) ) ) );
			update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
			delete_post_meta( $id, '_elementor_css' );
			delete_post_meta( $id, '_elementor_element_cache' );
			if ( function_exists( 'rocket_clean_post' ) ) {
				rocket_clean_post( $id );
			}
		}
	}

	// Menús: elementos con enlace personalizado a una entrada de la web.
	if ( ! $ids ) {
		$items = get_posts( array( 'post_type' => 'nav_menu_item', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_menu_item_type', 'meta_value' => 'custom', 'suppress_filters' => true ) );
		foreach ( $items as $item ) {
			$url = (string) get_post_meta( $item->ID, '_menu_item_url', true );
			$pid = eg_pub_internal_links_resolve( $url, $hosts, $why );
			if ( null === $pid ) {
				continue;
			}
			$row = array( 'item' => $item->ID, 'title' => $item->post_title, 'url' => $url, 'post_id' => $pid );
			if ( ! $pid ) {
				$out['unresolved'][] = $row + array( 'why' => $why );
				continue;
			}
			if ( $apply ) {
				update_post_meta( $item->ID, '_menu_item_type', 'post_type' );
				update_post_meta( $item->ID, '_menu_item_object', get_post_type( $pid ) );
				update_post_meta( $item->ID, '_menu_item_object_id', $pid );
				update_post_meta( $item->ID, '_menu_item_url', '' );
				$out['converted']++;
			} else {
				$out['pending']++;
			}
			$out['menus'][] = $row;
		}
	}
	if ( $apply && class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
		\Elementor\Plugin::$instance->files_manager->clear_cache();
	}
	return new WP_REST_Response( $out, 200 );
}

/**
 * Recorre elementos de Elementor y, en cada control de enlace sin etiqueta dinámica que apunta a la web, anota
 * (y con $apply pone) la etiqueta internal-url. Mira también dentro de repetidores (listas de iconos, diapositivas…).
 */
function eg_pub_internal_links_walk( &$elements, $hosts, $apply, &$found ) {
	foreach ( $elements as &$el ) {
		if ( ! is_array( $el ) ) {
			continue;
		}
		if ( isset( $el['settings'] ) && is_array( $el['settings'] ) ) {
			eg_pub_internal_links_settings( $el['settings'], $hosts, $apply, $found, array( 'element' => $el['id'] ?? '', 'widget' => $el['widgetType'] ?? ( $el['elType'] ?? '' ) ) );
		}
		if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
			eg_pub_internal_links_walk( $el['elements'], $hosts, $apply, $found );
		}
	}
	unset( $el );
}

function eg_pub_internal_links_settings( &$settings, $hosts, $apply, &$found, $ctx ) {
	foreach ( $settings as $key => &$val ) {
		if ( ! is_array( $val ) || '__dynamic__' === $key || '__globals__' === $key ) {
			continue;
		}
		if ( isset( $val['url'] ) && is_string( $val['url'] ) && '' !== $val['url'] ) {
			if ( ! empty( $settings['__dynamic__'][ $key ] ) ) {
				continue;
			}
			$pid = eg_pub_internal_links_resolve( $val['url'], $hosts, $why );
			if ( null === $pid ) {
				continue;
			}
			$row = $ctx + array( 'key' => $key, 'url' => $val['url'], 'post_id' => $pid );
			if ( ! $pid ) {
				$row['why'] = $why;
			} elseif ( $apply ) {
				$tag = sprintf( '[elementor-tag id="%s" name="internal-url" settings="%s"]', substr( md5( $val['url'] . wp_rand() ), 0, 7 ), rawurlencode( wp_json_encode( array( 'type' => 'post', 'post_id' => (string) $pid ) ) ) );
				if ( ! isset( $settings['__dynamic__'] ) || ! is_array( $settings['__dynamic__'] ) ) {
					$settings['__dynamic__'] = array();
				}
				$settings['__dynamic__'][ $key ] = $tag;
			}
			$found[] = $row;
			continue;
		}
		// Repetidor: lista de filas con sus propios ajustes (y su propio __dynamic__).
		if ( array_values( $val ) === $val ) {
			foreach ( $val as $i => &$item ) {
				if ( is_array( $item ) ) {
					eg_pub_internal_links_settings( $item, $hosts, $apply, $found, $ctx + array( 'repeater' => $key, 'row' => $i ) );
				}
			}
			unset( $item );
		}
	}
	unset( $val );
}

/**
 * ID de la entrada de la web a la que apunta una URL; 0 si es de la web pero no corresponde a ninguna entrada
 * ($why dice por qué) y null si no es un enlace interno que se pueda convertir (externo, ancla, tel:, mailto:…).
 */
function eg_pub_internal_links_resolve( $url, $hosts, &$why = '' ) {
	$why = '';
	$url = trim( (string) $url );
	if ( '' === $url || '#' === $url[0] || preg_match( '#^(tel|mailto|javascript|whatsapp|sms):#i', $url ) ) {
		return null;
	}
	$parts = wp_parse_url( $url );
	if ( ! empty( $parts['host'] ) ) {
		$host = strtolower( preg_replace( '#^www\.#i', '', $parts['host'] ) );
		if ( empty( $hosts[ $host ] ) ) {
			return null;
		}
	} elseif ( '/' !== ( $url[0] ?? '' ) ) {
		return null;
	}
	if ( ! empty( $parts['fragment'] ) || ! empty( $parts['query'] ) ) {
		$why = 'lleva #ancla o parámetros';
		return 0;
	}
	$path = '/' . ltrim( (string) ( $parts['path'] ?? '/' ), '/' );
	if ( preg_match( '#^/wp-content/#', $path ) ) {
		return null;
	}
	if ( '/' === $path ) {
		$front = (int) get_option( 'page_on_front' );
		$why   = $front ? '' : 'la portada no es una página';
		return $front;
	}
	$pid = url_to_postid( home_url( $path ) );
	if ( ! $pid ) {
		$page = get_page_by_path( trim( $path, '/' ), OBJECT, get_post_types( array( 'public' => true ) ) );
		$pid  = $page ? (int) $page->ID : 0;
	}
	if ( ! $pid ) {
		$why = 'no corresponde a ninguna entrada';
	}
	return (int) $pid;
}

/**
 * Cadenas de WPML String Translation, p. ej. las bases de URL de la tienda que traduce WooCommerce Multilingual
 * («URL slug: product», «URL product_cat tax slug»):
 *   POST /wp-json/eg/v1/wpml-strings
 *   context  contexto de las cadenas (WordPress por defecto) y name  texto que contiene su nombre: las lista con su
 *            valor original y sus traducciones.
 *   set      [{"id": 12, "lang": "ca", "value": "producte"}] guarda esas traducciones como completas.
 *   flush    true: regenera las reglas de URL después de guardar (hace falta al traducir bases de URL).
 * Solo previsualiza; guarda con apply:true y vacía la caché de WP Rocket.
 */
function eg_pub_wpml_strings_handle( WP_REST_Request $r ) {
	global $wpdb;
	$p     = $r->get_params();
	$apply = ! empty( $p['apply'] );
	if ( ! function_exists( 'icl_add_string_translation' ) ) {
		return eg_pub_error( 'eg_wpml_st', 'WPML String Translation no está activo en esta web.' );
	}
	$strings = $wpdb->prefix . 'icl_strings';
	$trans   = $wpdb->prefix . 'icl_string_translations';
	$context = (string) ( $p['context'] ?? 'WordPress' );
	$name    = (string) ( $p['name'] ?? '' );
	$changes = array();
	$notes   = array();

	foreach ( (array) ( $p['set'] ?? array() ) as $row ) {
		$id    = (int) ( $row['id'] ?? 0 );
		$lang  = sanitize_key( $row['lang'] ?? '' );
		$value = sanitize_text_field( (string) ( $row['value'] ?? '' ) );
		$orig  = $id ? $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$strings} WHERE id = %d", $id ) ) : null; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( null === $orig || '' === $lang || '' === $value ) {
			$notes[] = 'cadena ' . $id . ': falta id válido, lang o value';
			continue;
		}
		$before    = $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$trans} WHERE string_id = %d AND language = %s", $id, $lang ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$changes[] = array( 'id' => $id, 'original' => $orig, 'lang' => $lang, 'before' => $before, 'after' => $value );
		if ( $apply && $before !== $value ) {
			icl_add_string_translation( $id, $lang, $value, defined( 'ICL_TM_COMPLETE' ) ? ICL_TM_COMPLETE : 10 );
		}
	}
	if ( $apply && $changes ) {
		if ( ! empty( $p['flush'] ) ) {
			flush_rewrite_rules( false );
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
	}

	$list = $wpdb->get_results( $wpdb->prepare( "SELECT id, context, name, value FROM {$strings} WHERE context = %s AND name LIKE %s ORDER BY id LIMIT 100", $context, '%' . $wpdb->esc_like( $name ) . '%' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	foreach ( $list as &$item ) {
		$item['id']           = (int) $item['id'];
		$item['translations'] = array();
		foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT language, value, status FROM {$trans} WHERE string_id = %d", $item['id'] ), ARRAY_A ) as $t ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$item['translations'][ $t['language'] ] = array( 'value' => $t['value'], 'status' => (int) $t['status'] );
		}
	}
	unset( $item );
	return new WP_REST_Response( array(
		'ok'      => true,
		'applied' => $apply && $changes,
		'changes' => $changes,
		'notes'   => $notes,
		'strings' => $list,
	), 200 );
}
