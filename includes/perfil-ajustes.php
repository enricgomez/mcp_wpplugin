<?php
/**
 * Ajustes de rendimiento del servidor que se activan con /perf `fixes` (opción `eg_pub_perf_fixes`).
 *   jet_validator  JetElements (2.9.2.7) normaliza los ajustes de cada elemento de Elementor al pintarlo, al generar
 *                  su CSS y al cargar cada documento, y para cada uno vuelve a montar la lista completa de controles
 *                  y los registros de iconos y animaciones, sin guardarla. En miriamtirado.com eran 0,7 s por página
 *                  sin caché (2 s en frío). Este ajuste sustituye esos tres ganchos por los mismos pasos con la lista
 *                  de controles guardada por tipo de elemento durante la petición; el resultado es el mismo.
 * Si la versión de JetElements cambia y no se encuentran los tres ganchos, no toca nada (ver eg_pub_perf_fixes_state()).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_PERF_FIXES = array( 'jet_validator' );

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
	if ( defined( 'JET_ELEMENTS_VERSION' ) ) {
		$state['jet_validator']['jet_elements'] = JET_ELEMENTS_VERSION;
	}
	return $state;
}

// JetElements registra el validador antes de wp_loaded; Elementor no pinta ni carga documentos hasta después.
add_action( 'wp_loaded', function () {
	$fixes = eg_pub_perf_fixes();
	if ( ! empty( $fixes['jet_validator'] ) ) {
		EG_Pub_Jet_Validator_Cache::swap();
	}
}, 1 );
