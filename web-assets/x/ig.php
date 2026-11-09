<?php
$p = get_page_by_path('igcat', OBJECT, 'proyecto'); $dt = '2026-01-01 10:00:00';
wp_update_post(['ID'=>$p->ID,'post_date'=>$dt,'post_date_gmt'=>get_gmt_from_date($dt)]); update_post_meta($p->ID,'anio','2026');
wp_cache_flush(); if (function_exists('rocket_clean_domain')) rocket_clean_domain();
echo get_post($p->ID)->post_date, "\n";
