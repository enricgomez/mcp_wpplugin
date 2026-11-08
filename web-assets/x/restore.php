<?php
$K = 5;
$ids = get_post_meta( $K, '_elementor_global_classes_post_ids', true );
$pri = json_decode( file_get_contents( '/tmp/eg-prio.json' ), true );
$labels = []; $miss = [];
foreach ( $ids as $gid => $pid ) { $p = get_post( $pid ); if ( ! $p || 'e_global_class' !== $p->post_type ) { $miss[] = "$gid:$pid"; continue; } $labels[ $gid ] = $p->post_title; }
echo 'ids=', count( $ids ), ' labels=', count( $labels ), ' sin post=', implode( ',', $miss ), "\n";
$by = []; foreach ( $labels as $gid => $l ) { if ( isset( $by[ $l ] ) ) echo "label repetido $l\n"; $by[ $l ] = $gid; }
$order = []; foreach ( $pri as $l ) if ( isset( $by[ $l ] ) && ! in_array( $by[ $l ], $order, true ) ) $order[] = $by[ $l ];
$extra = array_values( array_diff( array_keys( $labels ), $order ) ); echo 'ordenadas por css=', count( $order ), ' añadidas al final=', count( $extra ), ': ', implode( ' ', array_map( fn( $g ) => $labels[ $g ], $extra ) ), "\n";
$order = array_merge( $order, $extra );
$clean = array_intersect_key( $ids, $labels );
update_post_meta( $K, '_elementor_global_classes_post_ids', $clean );
update_post_meta( $K, '_elementor_global_classes_labels', $labels );
update_post_meta( $K, '_elementor_global_classes_order', $order );
update_post_meta( $K, '_elementor_global_classes_order_preview', $order );
wp_cache_flush(); \Elementor\Plugin::$instance->files_manager->clear_cache();
$h = wp_remote_retrieve_body( wp_remote_get( home_url( '/?nc=' . time() ), [ 'sslverify' => false ] ) );
echo 'home eg-btn: ', substr_count( $h, '"eg-btn ' ), ' global css: ', preg_match( '#global-6-frontend-desktop#', $h ), "\n";
