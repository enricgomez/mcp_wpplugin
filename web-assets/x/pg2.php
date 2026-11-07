<?php
do_action( 'rest_api_init' );
$strip = function ( $x ) use ( &$strip ) { if ( ! is_array( $x ) ) return $x; if ( isset( $x['anyOf'] ) ) return $strip( $x['anyOf'][0] ); unset( $x['description'] ); foreach ( $x as $k => $v ) $x[ $k ] = $strip( $v ); return $x; };
foreach ( [ 'e-form', 'e-accordion-item-title', 'e-accordion-item-header', 'e-accordion-item-content', 'e-form-success-message', 'e-form-select' ] as $w ) {
	$r = wp_get_ability( 'elementor/get-widget-schema' )->execute( [ 'widget_type' => $w ] );
	if ( is_wp_error( $r ) ) { echo $w, ' ', $r->get_error_message(), "\n"; continue; }
	echo "=== $w\n", json_encode( $strip( $r['properties'] ?? $r ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), "\n", json_encode( $r['llm_guidance']['nesting'] ?? '' ), "\n";
}
