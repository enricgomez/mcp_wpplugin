<?php
$old = [ 1346,1347,1348,1349,1350,1488,1352,1353,1354,1355,1356,1491,1358,1359,1360,1361,1362,1489,1364,1365,1366,1367,1368,1490 ];
$quedan = array_filter( $old, fn( $i ) => get_post( $i ) ); echo 'antiguas que quedan: ', implode( ',', $quedan ) ?: 'ninguna', "\n";
$new = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => -1, 'fields' => 'ids', 'date_query' => [ [ 'after' => '30 minutes ago' ] ] ] );
echo 'nuevas: ', count( $new ), ' ', implode( ',', $new ), "\n";
echo 'imagify: ', function_exists( 'imagify_get_optimization_process' ) ? 'si' : 'no', "\n";
foreach ( $new as $id ) {
	$p = imagify_get_optimization_process( $id, 'wp' ); if ( ! $p->is_valid() ) { echo "$id no valido\n"; continue; }
	$d = $p->get_data(); if ( $d->is_optimized() ) { echo "$id ya\n"; continue; }
	$r = $p->optimize(); echo "$id ", is_wp_error( $r ) ? 'ERR ' . $r->get_error_message() : 'en cola', "\n";
}
