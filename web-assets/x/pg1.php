<?php
do_action( 'rest_api_init' );
foreach ( [ 'e-form', 'e-form-input', 'e-form-textarea', 'e-form-submit-button', 'e-form-checkbox', 'e-form-label', 'e-accordion', 'e-accordion-item' ] as $w ) {
	$r = wp_get_ability( 'elementor/get-widget-schema' )->execute( [ 'widget_type' => $w ] );
	if ( is_wp_error( $r ) ) $r = wp_get_ability( 'elementor/get-widget-schema' )->execute( [ 'name' => $w ] );
	$j = json_encode( $r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	echo "=== $w ", strlen( $j ), "\n", substr( preg_replace( '/"(style|styles)":\{.*?\}\}\}/', '', $j ), 0, 2500 ), "\n";
}
