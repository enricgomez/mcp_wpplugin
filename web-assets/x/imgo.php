<?php
$L = fopen( '/tmp/eg-imgo.log', 'w' ); $log = function ( $s ) use ( $L ) { fwrite( $L, $s . "\n" ); fflush( $L ); };
$ids = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => -1, 'fields' => 'ids', 'include' => range( 1528, 1557 ) ] );
foreach ( $ids as $id ) {
	$p = imagify_get_optimization_process( $id, 'wp' ); if ( $p->is_locked() ) $p->unlock();
	$m = wp_get_attachment_metadata( $id ); $sizes = array_merge( [ 'full' ], array_keys( $m['sizes'] ?? [] ) ); $ok = 0; $err = '';
	foreach ( $sizes as $s ) foreach ( [ $s, $s . '@imagify-webp' ] as $z ) { $r = $p->optimize_size( $z, 2 ); if ( is_wp_error( $r ) ) $err = $r->get_error_message(); else $ok++; }
	$log( "$id ok=$ok " . ( $err ? "err=$err" : '' ) . ' estado=' . get_post_meta( $id, '_imagify_status', true ) . ' webp=' . ( $p->has_next_gen() ? 'si' : 'no' ) );
}
$log( 'FIN' );
