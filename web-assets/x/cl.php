<?php
// limpieza: página auxiliar 444 a la papelera, imágenes temporales fuera
$p = get_post( 444 ); if ( $p && 'page' === $p->post_type ) { wp_trash_post( 444 ); echo "444 a papelera ({$p->post_title})\n"; }
wp_cache_flush(); \Elementor\Plugin::$instance->files_manager->clear_cache(); echo "ok\n";
