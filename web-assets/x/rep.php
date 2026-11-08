<?php
$R = json_decode( file_get_contents( '/tmp/eg-rep.json' ), true );
global $wpdb;
$ids = $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_elementor_data' AND (meta_value LIKE '%1.290%' OR meta_value LIKE '%paquetes de precio cerrado%')" );
function eg_rep( $v, $R, &$n ) { if ( is_array( $v ) ) { foreach ( $v as $k => $x ) $v[ $k ] = eg_rep( $x, $R, $n ); return $v; } if ( is_string( $v ) ) { foreach ( $R as $r ) { if ( false !== strpos( $v, $r[0] ) ) { $v = str_replace( $r[0], $r[1], $v ); $n++; } } } return $v; }
foreach ( $ids as $id ) {
	$d = json_decode( get_post_meta( $id, '_elementor_data', true ), true ); $n = 0; $d = eg_rep( $d, $R, $n );
	if ( $n ) { update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( $d ) ) ); delete_post_meta( $id, '_elementor_css' ); delete_post_meta( $id, '_elementor_element_cache' ); }
	echo get_post_type( $id ) . " $id " . get_the_title( $id ) . ": $n\n";
}
