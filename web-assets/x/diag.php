<?php
use Elementor\Modules\GlobalClasses\Global_Classes_Repository;
$r = Global_Classes_Repository::make();
$all = $r->all( true ); $items = $all->get_items()->all(); echo 'items=', count( $items ), "\n";
$lab = $r->all_labels(); echo 'labels=', count( $lab ), ' eg-btn=', $lab['g-093f75b'] ?? '-', ' hero=', $lab['g-85c2a24'] ?? '-', "\n";
echo 'order=', count( (array) get_post_meta( 5, '_elementor_global_classes_order', true ) ), "\n";
$k = \Elementor\Plugin::$instance->kits_manager->get_active_kit(); echo 'kit=', $k ? $k->get_id() : 'none', ' meta order via kit=', count( (array) $k->get_meta( '_elementor_global_classes_order' ) ), "\n";
