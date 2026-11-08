<?php
foreach ( [ 54167, 54159, 69669 ] as $id ) { echo $id, ' ', get_attached_file( $id ), ' ', json_encode( wp_get_attachment_metadata( $id )['width'] ?? '' ), "\n"; }
$p = get_page_by_path( 'quien-somos' ); if ( $p ) { preg_match_all( '/(?:image|img|background_image)[^0-9]{0,12}"?(\d{3,6})/', $p->post_content, $m ); echo 'qs ', $p->ID, ' ', implode( ',', array_unique( $m[1] ) ), "\n"; preg_match_all( '#wp-content/uploads/[^"\'\s\)]+\.(?:jpe?g|png|webp)#i', $p->post_content, $m2 ); echo implode( "\n", array_unique( $m2[0] ) ), "\n"; }
