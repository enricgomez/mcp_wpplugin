<?php
$d = get_post_meta( 444, '_elementor_data', true ); echo 'len ', strlen( $d ), ' faq? ', substr_count( $d, 'e-accordion' ), ' form? ', substr_count( $d, 'e-form' ), "\n";
echo 'revs: '; foreach ( wp_get_post_revisions( 444, [ 'numberposts' => 2 ] ) as $r ) echo $r->ID, ' ', $r->post_status, ' ', $r->post_modified, ' / '; echo "\n";
$a = get_posts( [ 'post_parent' => 444, 'post_type' => 'revision', 'post_status' => 'any', 'numberposts' => 1 ] ); if ( $a ) echo 'last rev data has faq: ', substr_count( get_post_meta( $a[0]->ID, '_elementor_data', true ), 'e-accordion' ), "\n";
\Elementor\Plugin::$instance->files_manager->clear_cache();
$h = wp_remote_retrieve_body( wp_remote_get( get_permalink( 444 ) . '?nc=' . time(), [ 'sslverify' => false ] ) ); echo 'html details: ', substr_count( $h, '<details' ), ' form: ', substr_count( $h, '<form' ), "\n";
