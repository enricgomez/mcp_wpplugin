<?php
global $wpdb;
foreach ( $wpdb->get_results( "SELECT meta_id, meta_key, LEFT(meta_value, 500) v, LENGTH(meta_value) l FROM {$wpdb->postmeta} WHERE post_id=5 AND meta_key LIKE '_elementor_global_classes%'" ) as $x ) echo "$x->meta_id $x->meta_key ($x->l): $x->v\n\n";
$p = get_post( 1969 ); echo "1969: $p->post_type $p->post_status $p->post_title\n";
foreach ( get_post_meta( 1969 ) as $k => $v ) echo "  $k = ", substr( $v[0], 0, 300 ), "\n";
