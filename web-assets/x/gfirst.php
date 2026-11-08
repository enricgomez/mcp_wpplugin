<?php
foreach ( get_posts( [ 'post_type' => 'proyecto', 'numberposts' => -1, 'post_status' => 'publish' ] ) as $p ) {
	$g = array_values( array_filter( array_map( 'intval', explode( ',', (string) get_post_meta( $p->ID, 'galeria', true ) ) ) ) ); if ( count( $g ) < 2 ) continue;
	$r = function ( $i ) { $m = wp_get_attachment_metadata( $i ); return [ ( $m['width'] ?? 0 ) / max( 1, $m['height'] ?? 1 ), $m['width'] ?? 0 ]; };
	[ $r0 ] = $r( $g[0] ); if ( $r0 >= 1.2 && $r0 <= 2.2 ) continue;
	$best = -1; $bw = 0; foreach ( $g as $k => $i ) { [ $ri, $wi ] = $r( $i ); if ( $ri >= 1.3 && $ri <= 2.0 && $wi > $bw ) { $bw = $wi; $best = $k; } }
	if ( $best > 0 ) { $x = $g[ $best ]; array_splice( $g, $best, 1 ); array_unshift( $g, $x ); update_post_meta( $p->ID, 'galeria', implode( ',', $g ) ); echo $p->post_name, ": primera ahora $x (antes ratio ", round( $r0, 2 ), ")\n"; }
	else echo $p->post_name, ": sin alternativa (ratio ", round( $r0, 2 ), ")\n";
}
