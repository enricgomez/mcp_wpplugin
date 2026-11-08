<?php
foreach ( get_posts( [ 'post_type' => 'proyecto', 'numberposts' => -1, 'post_status' => 'publish' ] ) as $p ) {
	$g = array_filter( array_map( 'intval', explode( ',', (string) get_post_meta( $p->ID, 'galeria', true ) ) ) );
	$info = array_map( function ( $i ) { $m = wp_get_attachment_metadata( $i ); return $i . ':' . ( $m['width'] ?? '?' ) . 'x' . ( $m['height'] ?? '?' ) . ':' . basename( get_attached_file( $i ) ); }, $g );
	echo $p->ID, ' | ', $p->post_name, ' | thumb ', get_post_thumbnail_id( $p ), ' | n=', count( $g ), "\n   ", implode( "\n   ", $info ), "\n";
}
