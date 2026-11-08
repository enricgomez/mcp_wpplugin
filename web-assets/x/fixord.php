<?php
use Elementor\Modules\GlobalClasses\Global_Classes_Repository;
foreach ( [ '_elementor_global_classes_order', '_elementor_global_classes_order_preview' ] as $k ) { $o = get_post_meta( 5, $k, true ); if ( isset( $o[0] ) ) update_post_meta( 5, $k, [ 'order' => array_values( $o ) ] ); }
wp_cache_flush(); \Elementor\Plugin::$instance->files_manager->clear_cache();
$r = Global_Classes_Repository::make(); echo 'items=', count( $r->all( true )->get_items()->all() ), ' labels=', count( $r->all_labels() ), "\n";
$h = wp_remote_retrieve_body( wp_remote_get( home_url( '/?nc=' . time() ), [ 'sslverify' => false ] ) );
echo 'home eg-btn: ', substr_count( $h, '"eg-btn ' ), ' global css: ', preg_match( '#global-6-frontend-desktop#', $h ), "\n";
