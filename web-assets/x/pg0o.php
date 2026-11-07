<?php
foreach ( [ 22514, 54515, 54243, 30297, 69109 ] as $id ) { $p = get_post( $id ); $c = $p->post_content; echo "=== $id ", $p->post_title, ' len=', strlen( $c ), "\n", substr( preg_replace( '/\s+/', ' ', wp_strip_all_tags( do_shortcode( $c ) ) ), 0, 700 ), "\n"; }
