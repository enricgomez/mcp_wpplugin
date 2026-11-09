<?php
@unlink('/tmp/eg-p54.log');
include '/tmp/eg-proj2.php';
echo file_get_contents('/tmp/eg-p54.log');
global $wpdb;
$D = ['world-region-of-gastronomy'=>'2025-01-14','food-film-menu'=>'2025-01-14','top-foodie-websites'=>'2025-01-14','world-food-gift-challenge'=>'2025-01-14','young-chef-awards'=>'2025-01-14','european-region-of-gastronomy'=>'2026-11-01','clautic'=>'2025-01-01'];
$h = 10; foreach ($D as $s => $d) { $p = get_page_by_path($s, OBJECT, 'proyecto'); if (!$p) { echo "FALTA $s\n"; continue; } $dt = sprintf('%s %02d:00:00', $d, $h++); wp_update_post(['ID'=>$p->ID,'post_date'=>$dt,'post_date_gmt'=>get_gmt_from_date($dt)]); update_post_meta($p->ID,'anio',substr($d,0,4)); echo "$s $dt\n"; }
foreach ($wpdb->get_col("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_eg_src' AND meta_value REGEXP '^cap-out-ig(wrg|erg|ffm|tfw|wfgc|yca)-'") as $id) { $pr = imagify_get_optimization_process((int) $id, 'wp'); if ($pr->is_valid() && !$pr->get_data()->is_optimized()) { $r = $pr->optimize(); echo "$id ", is_wp_error($r) ? 'ERR' : 'cola', "\n"; } }
@unlink(ABSPATH . 'rx-p54.php'); echo is_file(ABSPATH . 'rx-p54.php') ? "rx sigue\n" : "rx borrado\n";
wp_cache_flush(); if (function_exists('rocket_clean_domain')) rocket_clean_domain();
foreach (get_posts(['post_type'=>'proyecto','numberposts'=>14,'orderby'=>'date','order'=>'DESC']) as $p) echo substr($p->post_date,0,10),' ',$p->post_name,"\n";
