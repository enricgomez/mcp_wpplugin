<?php
$p = get_post( 15 ); echo json_encode( [ $p->post_type, $p->post_status, $p->post_name, $p->post_title, $p->post_parent, substr( $p->post_content, 0, 600 ) ], JSON_UNESCAPED_UNICODE ), "\n";
foreach ( get_post_meta( 15 ) as $k => $v ) echo "meta $k = ", substr( $v[0], 0, 700 ), "\n";
foreach ( [ 14, 410, 2026, 0 ] as $i ) { $q = get_post( $i ); echo "post $i: ", $q ? $q->post_type . ' ' . $q->post_title : 'NO EXISTE', "\n"; }
$o = get_post_meta( 5, '_elementor_global_classes_order', true ); echo 'order tiene g-093f75b: ', false !== strpos( is_string( $o ) ? $o : json_encode( $o ), 'g-093f75b' ) ? 'si' : 'no', "\n";
