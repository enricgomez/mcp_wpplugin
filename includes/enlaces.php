<?php
/**
 * Enlaces por idioma (tipología «Gestión de la web»):
 *   POST /wp-json/eg/v1/lang-links
 * Escribe en la web el enlace correcto de cada idioma, por ejemplo el botón Reservar de la cabecera hacia el motor
 * de reservas en cada idioma. El cambio queda guardado en el JSON de Elementor (y en el contenido) de cada
 * traducción (y en los elementos de menú con enlace personalizado): el plugin no hace nada al servir las páginas, así que si se desactiva los enlaces siguen bien.
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
	$modules['enlaces'] = array( 'type' => 'gestion', 'routes' => array( 'lang-links' ) );
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

	if ( ( $changed || $created ) && ! $dry_run ) {
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
		'elsewhere'        => $elsewhere,
		'message'          => implode( ' | ', $notes ),
	), 200 );
}
