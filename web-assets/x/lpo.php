<?php
foreach ( get_posts( [ 'post_type' => 'page', 'numberposts' => -1, 'post_status' => 'publish' ] ) as $p ) { $u = get_page_uri( $p ); if ( preg_match( '/legal|privac|cookie|condicion|aviso|contact|estudio|sobre|about|servic/i', $u ) ) echo $p->ID, ' ', $u, ' | ', $p->post_title, ' | ', strlen( $p->post_content ), "\n"; }
