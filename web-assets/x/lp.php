<?php
foreach ( get_posts( [ 'post_type' => 'page', 'numberposts' => -1, 'post_status' => 'publish' ] ) as $p ) echo $p->ID, ' ', get_page_uri( $p ), ' | ', $p->post_title, ' | ', strlen( $p->post_content ), "\n";
echo "privacy page: ", get_option( 'wp_page_for_privacy_policy' ), "\n";
