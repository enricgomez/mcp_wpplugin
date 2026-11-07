<?php
wp_update_post(['ID'=>296,'post_date'=>'2024-06-01 10:00:00','post_date_gmt'=>'2024-06-01 08:00:00']);
echo "cdv fecha=".get_post_field('post_date',296)."\n";
\Elementor\Plugin::$instance->files_manager->clear_cache();
$t=wp_get_theme('eg-studio'); echo "tema ".$t->get('Version')."\n";
