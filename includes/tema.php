<?php
/**
 * Ajustes del tema y textos fijos de la web (tipología «Gestión de la web»):
 *   POST /wp-json/eg/v1/theme
 * Sin parámetros devuelve el tema activo, los textos cambiados con labels y, si el tema guarda sus ajustes en
 * una opción (The7 y otros con Options Framework), los ajustes cuyo nombre contiene alguna cadena de `find`
 * (p. ej. ["sidebar", "woocommerce"]).
 *   options  {"ajuste": valor} cambia ajustes que ya existen en la opción del tema.
 *   labels   {"Out Of Stock": "Muy pronto"} cambia un texto fijo del tema o de los plugins (el original en
 *            inglés, tal cual sale en el código); "" o null lo devuelve al original. Se aplica con gettext,
 *            así que al desactivar el plugin todo vuelve a como estaba.
 *   lang_labels {"ca": {"Ahora personalízalo...": "Ara personalitza'l..."}} cambia textos de los widgets de Elementor
 *            en ese idioma de WPML (p. ej. de plantillas que no están traducidas); "" o null quita el cambio. Se
 *            aplica al pintar el widget, así que el JSON de Elementor no cambia. También cambia en ese idioma los textos
 *            fijos de plugins (gettext) por su original o su traducción, p. ej. {"Pickup date": "Data de recollida"}.
 *   sg_exclude {"minify": ["sr7", "tp-tools"], "combine": [...], "defer": [...]} añade handles de scripts a las
 *            exclusiones de SiteGround Optimizer (minificar, combinar y cargar en diferido); "remove" los quita.
 *   gtranslate {"floating_language_selector": "no", "widget_look": "popup"} cambia ajustes que ya existen en la
 *            opción de GTranslate (solo valores simples: texto, número o sí/no); la respuesta trae los actuales.
 * Como /seo, solo previsualiza; guarda con apply:true y vacía la caché de WP Rocket y de SiteGround.
 *
 * Solo lectura, para revisar plugins a medida sin acceso a los ficheros:
 *   plugins  true        lista los plugins (nombre, carpeta, versión, activo, actualización disponible, autor).
 *   widget   "nombre"    dice qué clase y fichero pintan un widget de Elementor y de qué plugin es.
 *   read     {"plugin": "carpeta", "file": "ruta/dentro.php"}  devuelve un fichero .php/.css/.js/.json/.txt
 *            de ese plugin (máx. 300 KB); sin file, lista sus ficheros.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_LABELS_OPT = 'eg_pub_labels';
const EG_PUB_LANG_LABELS_OPT = 'eg_pub_lang_labels';
const EG_PUB_SG_EXCLUDE = array(
	'minify'  => 'siteground_optimizer_minify_javascript_exclude',
	'combine' => 'siteground_optimizer_combine_javascript_exclude',
	'defer'   => 'siteground_optimizer_async_javascript_exclude',
);

add_filter( 'eg_pub_modules', function ( $modules ) {
	$modules['tema'] = array( 'type' => 'gestion', 'routes' => array( 'theme' ) );
	return $modules;
} );

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_module_enabled( 'tema' ) ) {
		return;
	}
	register_rest_route( 'eg/v1', '/theme', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_theme_handle',
		'permission_callback' => eg_pub_module_permission( 'manage_options' ),
	) );
} );

/** Lectura de plugins: lista, widget de Elementor → fichero, y ficheros de un plugin. Nunca escribe. */
function eg_pub_theme_inspect( $p ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$out = array( 'ok' => true );
	if ( ! empty( $p['plugins'] ) ) {
		$updates = get_site_transient( 'update_plugins' );
		$out['plugins'] = array();
		foreach ( get_plugins() as $file => $data ) {
			$out['plugins'][] = array(
				'file'    => $file,
				'name'    => $data['Name'],
				'version' => $data['Version'],
				'author'  => wp_strip_all_tags( $data['Author'] ),
				'active'  => is_plugin_active( $file ),
				'update'  => isset( $updates->response[ $file ] ) ? $updates->response[ $file ]->new_version : '',
			);
		}
	}
	if ( ! empty( $p['widget'] ) && did_action( 'elementor/loaded' ) ) {
		$widget = \Elementor\Plugin::$instance->widgets_manager->get_widget_types( sanitize_key( (string) $p['widget'] ) );
		if ( $widget ) {
			$file = ( new ReflectionClass( $widget ) )->getFileName();
			$rel  = 0 === strpos( $file, WP_PLUGIN_DIR . '/' ) ? substr( $file, strlen( WP_PLUGIN_DIR ) + 1 ) : '';
			$out['widget'] = array(
				'class'  => get_class( $widget ),
				'title'  => $widget->get_title(),
				'plugin' => $rel ? strtok( $rel, '/' ) : '',
				'file'   => $rel ? $rel : basename( $file ),
			);
		} else {
			$out['widget'] = null;
		}
	}
	if ( ! empty( $p['read'] ) && is_array( $p['read'] ) ) {
		$dir  = sanitize_file_name( (string) ( $p['read']['plugin'] ?? '' ) );
		$base = realpath( WP_PLUGIN_DIR . '/' . $dir );
		if ( '' === $dir || ! $base || ! is_dir( $base ) || 0 !== strpos( $base, realpath( WP_PLUGIN_DIR ) . '/' ) ) {
			return eg_pub_error( 'eg_plugin', 'No existe esa carpeta de plugin.' );
		}
		$rel = ltrim( (string) ( $p['read']['file'] ?? '' ), '/' );
		if ( '' === $rel ) {
			$files = array();
			$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) {
				if ( count( $files ) >= 500 ) {
					break;
				}
				$files[] = array( 'file' => substr( $f->getPathname(), strlen( $base ) + 1 ), 'size' => $f->getSize() );
			}
			$out['files'] = $files;
		} else {
			$path = realpath( $base . '/' . $rel );
			$ext  = strtolower( pathinfo( (string) $path, PATHINFO_EXTENSION ) );
			if ( ! $path || 0 !== strpos( $path, $base . '/' ) || ! in_array( $ext, array( 'php', 'css', 'js', 'json', 'txt', 'md' ), true ) ) {
				return eg_pub_error( 'eg_plugin_file', 'Fichero no válido (solo .php, .css, .js, .json, .txt o .md dentro del plugin).' );
			}
			if ( filesize( $path ) > 300 * 1024 ) {
				return eg_pub_error( 'eg_plugin_file', 'El fichero pasa de 300 KB.' );
			}
			$out['file']    = $rel;
			$out['content'] = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}
	return new WP_REST_Response( $out, 200 );
}

/** Textos cambiados: se buscan por el original, en cualquier dominio de traducción. */
function eg_pub_labels_filter( $translation, $text ) {
	static $labels = null;
	if ( null === $labels ) {
		$labels = (array) get_option( EG_PUB_LABELS_OPT, array() );
	}
	return isset( $labels[ $text ] ) ? $labels[ $text ] : $translation;
}
if ( get_option( EG_PUB_LABELS_OPT ) ) {
	add_filter( 'gettext', 'eg_pub_labels_filter', 20, 2 );
	add_filter( 'gettext_with_context', function ( $translation, $text ) {
		return eg_pub_labels_filter( $translation, $text );
	}, 20, 2 );
}

// Textos de Elementor por idioma: se sustituyen en el HTML de cada widget cuando la página está en ese idioma.
function eg_pub_lang_labels_filter( $content ) {
	static $map = null;
	if ( null === $map ) {
		$all  = (array) get_option( EG_PUB_LANG_LABELS_OPT, array() );
		$lang = (string) apply_filters( 'wpml_current_language', null );
		$map  = isset( $all[ $lang ] ) ? (array) $all[ $lang ] : array();
	}
	return $map && is_string( $content ) ? strtr( $content, $map ) : $content;
}
if ( get_option( EG_PUB_LANG_LABELS_OPT ) ) {
	add_filter( 'elementor/widget/render_content', 'eg_pub_lang_labels_filter', 20 );
	// Con la caché de elementos de Elementor el widget no se vuelve a pintar: se cambia también el HTML de la plantilla entera.
	add_filter( 'elementor/frontend/the_content', 'eg_pub_lang_labels_filter', 20 );
	// También textos de plugins sin traducción a ese idioma (p. ej. «Pickup date»): por el original o por su traducción.
	add_filter( 'gettext', 'eg_pub_lang_labels_gettext', 20, 2 );
	add_filter( 'gettext_with_context', function ( $translation, $text ) {
		return eg_pub_lang_labels_gettext( $translation, $text );
	}, 20, 2 );
}

function eg_pub_lang_labels_gettext( $translation, $text ) {
	static $map = null;
	if ( null === $map ) {
		if ( ! did_action( 'wpml_loaded' ) && ! did_action( 'init' ) ) {
			return $translation;
		}
		$all  = (array) get_option( EG_PUB_LANG_LABELS_OPT, array() );
		$lang = (string) apply_filters( 'wpml_current_language', null );
		$map  = isset( $all[ $lang ] ) ? (array) $all[ $lang ] : array();
	}
	if ( isset( $map[ $text ] ) ) {
		return $map[ $text ];
	}
	return isset( $map[ $translation ] ) ? $map[ $translation ] : $translation;
}

/** Nombre de la opción donde el tema guarda sus ajustes (Options Framework, p. ej. «the7»), o ''. */
function eg_pub_theme_option_name() {
	$of = get_option( 'optionsframework' );
	return is_array( $of ) && ! empty( $of['id'] ) && is_array( get_option( $of['id'] ) ) ? (string) $of['id'] : '';
}

function eg_pub_theme_handle( WP_REST_Request $r ) {
	$p       = $r->get_params();
	if ( ! empty( $p['plugins'] ) || ! empty( $p['widget'] ) || ! empty( $p['read'] ) ) {
		return eg_pub_theme_inspect( $p );
	}
	$apply   = ! empty( $p['apply'] );
	$name    = eg_pub_theme_option_name();
	$opts    = $name ? (array) get_option( $name ) : array();
	$labels  = (array) get_option( EG_PUB_LABELS_OPT, array() );
	$langlab = (array) get_option( EG_PUB_LANG_LABELS_OPT, array() );
	$changes = array();
	$notes   = array();

	foreach ( (array) ( $p['options'] ?? array() ) as $key => $value ) {
		if ( ! array_key_exists( $key, $opts ) ) {
			$notes[] = 'el ajuste ' . $key . ' no existe en la opción del tema' . ( $name ? '' : ' (no se ha encontrado)' );
			continue;
		}
		if ( $opts[ $key ] !== $value ) {
			$changes[]    = 'options.' . $key;
			$opts[ $key ] = $value;
		}
	}
	foreach ( (array) ( $p['labels'] ?? array() ) as $from => $to ) {
		$from = (string) $from;
		if ( null === $to || '' === $to ) {
			if ( isset( $labels[ $from ] ) ) {
				unset( $labels[ $from ] );
				$changes[] = 'labels.' . $from;
			}
		} elseif ( ( $labels[ $from ] ?? null ) !== sanitize_text_field( $to ) ) {
			$labels[ $from ] = sanitize_text_field( $to );
			$changes[]       = 'labels.' . $from;
		}
	}
	foreach ( (array) ( $p['lang_labels'] ?? array() ) as $lang => $map ) {
		$lang = sanitize_key( $lang );
		foreach ( (array) $map as $from => $to ) {
			$from = (string) $from;
			if ( null === $to || '' === $to ) {
				if ( isset( $langlab[ $lang ][ $from ] ) ) {
					unset( $langlab[ $lang ][ $from ] );
					$changes[] = 'lang_labels.' . $lang . '.' . $from;
				}
			} elseif ( ( $langlab[ $lang ][ $from ] ?? null ) !== wp_kses_post( $to ) ) {
				$langlab[ $lang ][ $from ] = wp_kses_post( $to );
				$changes[]                 = 'lang_labels.' . $lang . '.' . $from;
			}
		}
		if ( isset( $langlab[ $lang ] ) && ! $langlab[ $lang ] ) {
			unset( $langlab[ $lang ] );
		}
	}

	$gt = get_option( 'GTranslate', null );
	if ( isset( $p['gtranslate'] ) && ! is_array( $gt ) ) {
		$notes[] = 'GTranslate no está instalado o no tiene ajustes guardados';
	}
	foreach ( is_array( $gt ) ? (array) ( $p['gtranslate'] ?? array() ) : array() as $key => $value ) {
		if ( ! array_key_exists( $key, $gt ) || is_array( $gt[ $key ] ) || ! is_scalar( $value ) ) {
			$notes[] = 'el ajuste ' . $key . ' de GTranslate no existe o no es un valor simple';
			continue;
		}
		$value = is_string( $value ) ? sanitize_text_field( $value ) : $value;
		if ( $gt[ $key ] !== $value ) {
			$changes[]  = 'gtranslate.' . $key;
			$gt[ $key ] = $value;
		}
	}

	$sg_new = array();
	foreach ( EG_PUB_SG_EXCLUDE as $kind => $option ) {
		$list = array_values( (array) get_option( $option, array() ) );
		$add  = array_map( 'sanitize_text_field', (array) ( $p['sg_exclude'][ $kind ] ?? array() ) );
		$del  = array_map( 'sanitize_text_field', (array) ( $p['sg_exclude']['remove'][ $kind ] ?? array() ) );
		$new  = array_values( array_unique( array_diff( array_merge( $list, $add ), $del ) ) );
		if ( $new !== $list ) {
			$changes[] = 'sg_exclude.' . $kind;
		}
		$sg_new[ $kind ] = $new;
	}

	if ( $apply && $changes ) {
		foreach ( EG_PUB_SG_EXCLUDE as $kind => $option ) {
			if ( in_array( 'sg_exclude.' . $kind, $changes, true ) ) {
				update_option( $option, $sg_new[ $kind ] );
			}
		}
		if ( function_exists( 'sg_cachepress_purge_everything' ) ) {
			sg_cachepress_purge_everything();
		}
		if ( $name ) {
			update_option( $name, $opts );
		}
		if ( preg_grep( '/^gtranslate\./', $changes ) ) {
			update_option( 'GTranslate', $gt );
		}
		if ( $labels ) {
			update_option( EG_PUB_LABELS_OPT, $labels, true );
		} else {
			delete_option( EG_PUB_LABELS_OPT );
		}
		if ( $langlab ) {
			update_option( EG_PUB_LANG_LABELS_OPT, $langlab, true );
		} else {
			delete_option( EG_PUB_LANG_LABELS_OPT );
		}
		if ( preg_grep( '/^lang_labels\./', $changes ) ) {
			// La caché de elementos de Elementor guarda el HTML ya pintado: se vacía para que salga el texto nuevo.
			delete_post_meta_by_key( '_elementor_element_cache' );
			if ( class_exists( '\\Elementor\\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			}
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
	}

	$found = array();
	foreach ( (array) ( $p['find'] ?? array() ) as $needle ) {
		foreach ( $opts as $key => $value ) {
			if ( '' !== (string) $needle && false !== stripos( (string) $key, (string) $needle ) ) {
				$found[ $key ] = $value;
			}
		}
	}

	$theme = wp_get_theme();
	return new WP_REST_Response( array(
		'ok'           => true,
		'applied'      => $apply && $changes,
		'changes'      => $changes,
		'notes'        => $notes,
		'theme'        => $theme->get( 'Name' ),
		'template'     => get_template(),
		'stylesheet'   => get_stylesheet(),
		'option_name'  => $name,
		'option_count' => count( $opts ),
		'options'      => $found,
		'labels'       => $labels,
		'lang_labels'  => $langlab,
		'sg_exclude'   => $sg_new,
		'gtranslate'   => is_array( $gt ) ? array_filter( $gt, 'is_scalar' ) : null,
		'sg_optimizer' => defined( 'SiteGround_Optimizer\VERSION' ) || function_exists( 'sg_cachepress_purge_everything' ),
	), 200 );
}
