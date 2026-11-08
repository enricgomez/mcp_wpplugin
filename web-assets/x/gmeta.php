<?php
foreach ( get_posts( [ 'post_type' => 'proyecto', 'numberposts' => -1 ] ) as $p ) {
	echo "== {$p->ID} {$p->post_name} thumb=", get_post_thumbnail_id( $p->ID ), " galeria=", get_post_meta( $p->ID, 'galeria', true ), "\n";
	foreach ( array_filter( explode( ',', get_post_meta( $p->ID, 'galeria', true ) . ',' . get_post_thumbnail_id( $p->ID ) ) ) as $i ) { $m = wp_get_attachment_metadata( $i ); echo "  $i ", basename( $m['file'] ?? '' ), " {$m['width']}x{$m['height']} src=", get_post_meta( $i, '_eg_src', true ), " orig=", $m['original_image'] ?? '', "\n"; }
}
echo "big_image_threshold: ", apply_filters( 'big_image_size_threshold', 2560, [ 0, 0 ], '', 0 ), "\n";
foreach ( wp_get_registered_image_subsizes() as $k => $v ) echo "$k {$v['width']}x{$v['height']} ";
