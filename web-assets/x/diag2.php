<?php
use Elementor\Modules\GlobalClasses\Global_Classes_Order;
$k = \Elementor\Plugin::$instance->kits_manager->get_active_kit();
$o = Global_Classes_Order::make( $k )->get_order(); echo 'get_order=', count( $o ), ' first=', json_encode( array_slice( $o, 0, 3 ) ), "\n";
$raw = get_post_meta( 5, '_elementor_global_classes_order', true ); echo 'raw first=', json_encode( array_slice( (array) $raw, 0, 3 ) ), ' keys=', json_encode( array_slice( array_keys( (array) $raw ), 0, 3 ) ), "\n";
$p = get_posts( [ 'post_type' => 'e_global_class', 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids' ] ); echo 'posts publish=', count( $p ), "\n";
$p2 = get_posts( [ 'post_type' => 'e_global_class', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ] ); echo 'posts any=', count( $p2 ), "\n";
echo 'cpt registrado=', post_type_exists( 'e_global_class' ) ? 1 : 0, "\n";
$f = ( new ReflectionClass( Global_Classes_Order::class ) )->getFileName(); echo $f, "\n";
