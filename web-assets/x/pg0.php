<?php
$t = array_keys( \Elementor\Plugin::$instance->elements_manager->get_element_types() );
$w = array_keys( \Elementor\Plugin::$instance->widgets_manager->get_widget_types() );
echo 'EL: ', implode( ' ', $t ), "\n";
echo 'ATOM: ', implode( ' ', array_filter( $w, fn( $x ) => 0 === strpos( $x, 'e-' ) ) ), "\n";
echo 'FORMISH: ', implode( ' ', array_filter( $w, fn( $x ) => false !== stripos( $x, 'form' ) ) ), "\n";
$f = get_post_meta( 162, '_elementor_data', true ); preg_match_all( '/(mailto:[^"\\\\]+|tel:[^"\\\\]+|\+?34[ 0-9]{9,})/', $f, $m ); echo 'PIE: ', implode( ' | ', array_unique( $m[0] ) ), "\n";
echo 'abilities: ', implode( ' ', array_filter( array_map( fn( $a ) => $a->get_name(), wp_get_abilities() ), fn( $n ) => 0 === strpos( $n, 'elementor/' ) ) ), "\n";
