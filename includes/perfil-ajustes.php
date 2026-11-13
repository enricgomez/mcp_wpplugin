<?php
/**
 * Ajustes de rendimiento del servidor que se activan con /perf `fixes` (opción `eg_pub_perf_fixes`).
 *   jet_validator  JetElements (2.9.2.7) normaliza los ajustes de cada elemento de Elementor al pintarlo, al generar
 *                  su CSS y al cargar cada documento, y para cada uno vuelve a montar la lista completa de controles
 *                  y los registros de iconos y animaciones, sin guardarla. En miriamtirado.com eran 0,7 s por página
 *                  sin caché (2 s en frío). Este ajuste sustituye esos tres ganchos por los mismos pasos con la lista
 *                  de controles guardada por tipo de elemento durante la petición; el resultado es el mismo.
 * Si la versión de JetElements cambia y no se encuentran los tres ganchos, no toca nada (ver eg_pub_perf_fixes_state()).
 *   cdn_fonts      Las fuentes locales de Elementor (uploads/elementor/google-fonts) llevan la URL absoluta de la web dentro
 *                  de su CSS, y el CDN de WP Rocket no la cambia: el CSS sale del CDN pero las fuentes se piden al servidor.
 *                  Con este ajuste las URL de fuentes (woff2, woff, ttf, otf, eot) de wp-content pasan al primer CNAME del
 *                  CDN de Rocket en el CSS minificado y en el HTML cacheado (precargas). Al activarlo se corrigen también
 *                  los CSS minificados que ya existen, sin vaciar la caché. Solo actúa si el CDN de Rocket está activo.
 *   img_sizes      {"642": "(max-width: 767px) 62vw, 768px", "595": "(max-width: 767px) 180px, 600px"}: atributo `sizes`
 *                  de las imágenes de esos adjuntos (id de la biblioteca de medios). WordPress pone «100vw hasta el ancho
 *                  del tamaño elegido», y si en el móvil la imagen ocupa menos que la pantalla el navegador descarga una
 *                  versión mayor de la necesaria. Vale para cualquier imagen pintada con wp_get_attachment_image
 *                  (widgets de imagen de Elementor, miniaturas…). `false` o `{}` lo quita.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_PERF_FIXES = array( 'jet_validator', 'cdn_fonts', 'img_sizes' );

final class EG_Pub_Jet_Validator_Cache {
	private static $controls = array();
	public static $active    = false;

	/** Controles ya filtrados (como en el original) de un tipo de elemento, calculados una vez por petición. */
	public static function controls( $element ) {
		$key = get_class( $element ) . '|' . $element->get_name();
		if ( ! isset( self::$controls[ $key ] ) ) {
			$controls = Jet_Elements_Settings_Validator::controls( $element );
			if ( ! $element instanceof \Elementor\Jet_Elements_Base ) {
				$controls = Jet_Elements_Settings_Validator::extension_controls( $controls );
			}
			self::$controls[ $key ] = $controls;
		}
		return self::$controls[ $key ];
	}

	public static function normalize_element( $element ) {
		$element->set_settings( Jet_Elements_Settings_Validator::normalize( $element->get_settings(), self::controls( $element ) ) );
	}

	public static function before_parse_css( $file, $element ) {
		self::normalize_element( $element );
	}

	public static function document_elements( $elements ) {
		if ( ! is_array( $elements ) ) {
			return $elements;
		}
		foreach ( $elements as &$data ) {
			if ( ! is_array( $data ) ) {
				continue;
			}
			$element = \Elementor\Plugin::$instance->elements_manager->create_element_instance( $data );
			if ( $element && isset( $data['settings'] ) ) {
				$data['settings'] = Jet_Elements_Settings_Validator::normalize( $data['settings'], self::controls( $element ) );
			}
			if ( isset( $data['elements'] ) ) {
				$data['elements'] = self::document_elements( $data['elements'] );
			}
		}
		return $elements;
	}

	/** Cambia los ganchos del validador por los de esta clase. Solo si están los tres y los métodos que usa son públicos. */
	public static function swap() {
		$class = 'Jet_Elements_Settings_Validator';
		if ( ! class_exists( $class ) || ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}
		foreach ( array( 'controls', 'normalize', 'extension_controls' ) as $method ) {
			if ( ! method_exists( $class, $method ) || ! ( new ReflectionMethod( $class, $method ) )->isPublic() ) {
				return false;
			}
		}
		$closure = null;
		global $wp_filter;
		$hook = $wp_filter['elementor/element/before_parse_css'] ?? null;
		if ( $hook instanceof WP_Hook && isset( $hook->callbacks[1] ) ) {
			foreach ( $hook->callbacks[1] as $cb ) {
				if ( $cb['function'] instanceof Closure ) {
					$file = ( new ReflectionFunction( $cb['function'] ) )->getFileName();
					if ( $file && 'class-jet-elements-settings-validator.php' === basename( $file ) ) {
						$closure = $cb['function'];
						break;
					}
				}
			}
		}
		$render = array( $class, 'normalize_element' );
		$load   = array( $class, 'document_elements' );
		if ( ! $closure || 1 !== has_action( 'elementor/frontend/before_render', $render ) || 10 !== has_filter( 'elementor/document/load/data', $load ) ) {
			return false;
		}
		remove_action( 'elementor/frontend/before_render', $render, 1 );
		remove_action( 'elementor/element/before_parse_css', $closure, 1 );
		remove_filter( 'elementor/document/load/data', $load, 10 );
		add_action( 'elementor/frontend/before_render', array( __CLASS__, 'normalize_element' ), 1 );
		add_action( 'elementor/element/before_parse_css', array( __CLASS__, 'before_parse_css' ), 1, 2 );
		add_filter( 'elementor/document/load/data', array( __CLASS__, 'document_elements' ) );
		self::$active = true;
		return true;
	}
}

function eg_pub_perf_fixes() {
	$fixes = get_option( 'eg_pub_perf_fixes', array() );
	return is_array( $fixes ) ? array_intersect_key( $fixes, array_flip( EG_PUB_PERF_FIXES ) ) : array();
}

/** Qué ajustes están pedidos y si se han podido aplicar en esta petición. */
function eg_pub_perf_fixes_state() {
	$fixes = eg_pub_perf_fixes();
	$state = array();
	foreach ( EG_PUB_PERF_FIXES as $fix ) {
		$state[ $fix ] = array( 'enabled' => ! empty( $fixes[ $fix ] ) );
	}
	$state['jet_validator']['active'] = EG_Pub_Jet_Validator_Cache::$active;
	$state['cdn_fonts']['cdn']        = eg_pub_rocket_cdn_base();
	$state['img_sizes']['sizes']      = is_array( $fixes['img_sizes'] ?? null ) ? $fixes['img_sizes'] : array();
	if ( defined( 'JET_ELEMENTS_VERSION' ) ) {
		$state['jet_validator']['jet_elements'] = JET_ELEMENTS_VERSION;
	}
	return $state;
}

/** Primer CNAME del CDN de WP Rocket (con esquema, sin barra final), o '' si el CDN no está activo. */
function eg_pub_rocket_cdn_base() {
	$opts = get_option( 'wp_rocket_settings', array() );
	if ( empty( $opts['cdn'] ) || empty( $opts['cdn_cnames'] ) || ! is_array( $opts['cdn_cnames'] ) ) {
		return '';
	}
	$cname = trim( (string) reset( $opts['cdn_cnames'] ) );
	if ( '' === $cname ) {
		return '';
	}
	if ( ! preg_match( '#^https?://#i', $cname ) ) {
		$cname = 'https://' . ltrim( $cname, '/' );
	}
	return untrailingslashit( $cname );
}

/** Cambia a CDN las URL absolutas de ficheros de fuentes de wp-content de esta web. */
function eg_pub_cdn_fonts_rewrite( $content ) {
	$cdn = eg_pub_rocket_cdn_base();
	if ( '' === $cdn || ! is_string( $content ) || false === stripos( $content, 'wp-content' ) ) {
		return $content;
	}
	$hosts = array_unique( array_filter( array(
		wp_parse_url( home_url(), PHP_URL_HOST ),
		wp_parse_url( site_url(), PHP_URL_HOST ),
	) ) );
	foreach ( $hosts as $host ) {
		$pattern = '~(?:https?:)?//' . preg_quote( $host, '~' ) . '(/wp-content/[^\'"()\s]+?\.(?:woff2?|ttf|otf|eot))(?=[?#\'"()\s])~i';
		$content = preg_replace( $pattern, $cdn . '$1', $content );
	}
	return $content;
}

/** Corrige en su sitio los CSS minificados de Rocket que ya existen. Devuelve los ficheros cambiados (rutas relativas). */
function eg_pub_cdn_fonts_patch_min() {
	$base = WP_CONTENT_DIR . '/cache/min';
	$done = array();
	if ( '' === eg_pub_rocket_cdn_base() || ! is_dir( $base ) ) {
		return $done;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( ! $file->isFile() || 'css' !== strtolower( $file->getExtension() ) || $file->getSize() > 2 * MB_IN_BYTES ) {
			continue;
		}
		$path    = $file->getPathname();
		$current = (string) file_get_contents( $path );
		$source  = $current;
		// Copias minificadas de las fuentes locales de Elementor: si Elementor ha regenerado sus ficheros de fuentes
		// (nombres nuevos), la copia de Rocket apunta a fuentes que ya no existen. Se rehace desde el CSS original.
		$rel = substr( $path, strlen( $base ) );
		if ( preg_match( '#^/[^/]+(/wp-content/uploads/elementor/google-fonts/css/[^/]+\.css)$#', $rel, $m ) ) {
			$src = ABSPATH . ltrim( $m[1], '/' );
			$fresh = is_file( $src ) ? (string) file_get_contents( $src ) : '';
			if ( '' !== $fresh ) {
				$source = $fresh;
			}
		}
		$target = eg_pub_cdn_fonts_rewrite( $source );
		if ( ! is_string( $target ) || '' === $target ) {
			continue;
		}
		$rel_out = substr( $path, strlen( WP_CONTENT_DIR ) );
		if ( $target !== $current && false !== file_put_contents( $path, $target ) ) {
			$done[] = $rel_out;
		}
		// Rocket deja al lado una copia .gz que nginx sirve tal cual (gzip_static): tiene que decir lo mismo.
		if ( is_file( $path . '.gz' ) && function_exists( 'gzdecode' ) && function_exists( 'gzencode' ) ) {
			$gz = gzdecode( (string) file_get_contents( $path . '.gz' ) );
			if ( $gz !== $target && false !== file_put_contents( $path . '.gz', gzencode( $target, 9 ) ) ) {
				$done[] = $rel_out . '.gz';
			}
		}
	}
	return $done;
}

/**
 * WP Rocket guarda en {prefijo}wpr_preload_fonts las fuentes que precarga en cada página (las detecta el navegador).
 * Si Elementor ha renombrado sus ficheros de fuentes, esas filas siguen pidiendo los nombres viejos (404) y Rocket
 * no las vuelve a medir mientras existan. Borra solo las filas que apuntan a fuentes de wp-content que no existen;
 * Rocket las recoge de nuevo en las siguientes visitas. Devuelve cuántas filas ha borrado (o null si no hay tabla).
 */
function eg_pub_cdn_fonts_clean_preloads() {
	global $wpdb;
	$table = $wpdb->prefix . 'wpr_preload_fonts';
	if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
		return null;
	}
	$cols = (array) $wpdb->get_col( "SHOW COLUMNS FROM `$table`" );
	if ( ! in_array( 'id', $cols, true ) || ! in_array( 'fonts', $cols, true ) ) {
		return null;
	}
	$deleted = 0;
	$rows    = (array) $wpdb->get_results( "SELECT id, fonts FROM `$table`", ARRAY_A );
	foreach ( $rows as $row ) {
		$text = str_replace( '\\/', '/', (string) $row['fonts'] );
		if ( ! preg_match_all( '~(/wp-content/[^\'"()\s?#\\\\]+?\.(?:woff2?|ttf|otf|eot))~i', $text, $m ) ) {
			continue;
		}
		foreach ( array_unique( $m[1] ) as $rel ) {
			if ( ! file_exists( ABSPATH . ltrim( $rel, '/' ) ) ) {
				$deleted += (int) $wpdb->delete( $table, array( 'id' => (int) $row['id'] ), array( '%d' ) );
				break;
			}
		}
	}
	return $deleted;
}

/** Lista de `sizes` por id de adjunto, limpia: solo ids numéricos y textos con caracteres de una media query. */
function eg_pub_img_sizes_clean( $map ) {
	$out = array();
	foreach ( (array) $map as $id => $sizes ) {
		$sizes = trim( (string) $sizes );
		if ( (int) $id > 0 && '' !== $sizes && preg_match( '/^[a-z0-9 ().,:%-]+$/i', $sizes ) ) {
			$out[ (string) (int) $id ] = $sizes;
		}
	}
	return $out;
}

add_action( 'plugins_loaded', function () {
	$fixes = eg_pub_perf_fixes();
	if ( ! empty( $fixes['img_sizes'] ) && is_array( $fixes['img_sizes'] ) ) {
		$map = $fixes['img_sizes'];
		add_filter( 'wp_calculate_image_sizes', function ( $sizes, $size, $src, $meta, $attachment_id ) use ( $map ) {
			return isset( $map[ (string) (int) $attachment_id ] ) ? $map[ (string) (int) $attachment_id ] : $sizes;
		}, 99, 5 );
	}
	if ( ! empty( $fixes['cdn_fonts'] ) ) {
		add_filter( 'rocket_css_content', 'eg_pub_cdn_fonts_rewrite', 99 );
		add_filter( 'rocket_buffer', 'eg_pub_cdn_fonts_rewrite', 99 );
	}
} );

// JetElements registra el validador antes de wp_loaded; Elementor no pinta ni carga documentos hasta después.
add_action( 'wp_loaded', function () {
	$fixes = eg_pub_perf_fixes();
	if ( ! empty( $fixes['jet_validator'] ) ) {
		EG_Pub_Jet_Validator_Cache::swap();
	}
}, 1 );
