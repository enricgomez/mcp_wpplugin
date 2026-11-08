<?php
global $wpdb;
echo 'element_cache: ', $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_elementor_element_cache','_elementor_page_assets','_elementor_css')" ), "\n";
foreach ( $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%elementor%cache%' OR option_name LIKE '_transient%elementor%'" ) as $o ) { delete_option( $o ); echo "opt $o\n"; }
wp_cache_flush(); \Elementor\Plugin::$instance->files_manager->clear_cache();
$h = wp_remote_retrieve_body( wp_remote_get( home_url( '/?nc=' . time() ), [ 'sslverify' => false ] ) );
echo 'home eg-btn: ', substr_count( $h, '"eg-btn ' ), ' global css: ', preg_match( '#global-6-frontend-desktop#', $h ), "\n";
$l = get_post_meta( 5, '_elementor_global_classes_labels', true ); echo 'labels=', count( (array) $l ), "\n";
