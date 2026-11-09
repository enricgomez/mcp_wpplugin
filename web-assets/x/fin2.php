<?php
@unlink('/tmp/eg-p54.log');
include '/tmp/eg-proj2.php';
echo file_get_contents('/tmp/eg-p54.log');
global $wpdb;
$p = get_page_by_path('oktoberfest-calella', OBJECT, 'proyecto'); $use = array_map('intval', array_merge([get_post_thumbnail_id($p->ID)], explode(',', get_post_meta($p->ID, 'galeria', true))));
foreach ($wpdb->get_col("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_eg_src' AND meta_value LIKE 'cap-out-oktoberfestcalella-%'") as $id) { $id = (int) $id; if (in_array($id, $use, true)) { echo "en uso $id\n"; continue; } if (get_post_type($id) === 'attachment') { wp_delete_attachment($id, true); echo "borrada $id\n"; } }
include '/tmp/eg-fechas.php';
foreach ($wpdb->get_col("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_eg_src' AND meta_value REGEXP '^cap-out-(shamaspa|oktoberfestb)-'") as $id) { $pr = imagify_get_optimization_process((int) $id, 'wp'); if ($pr->is_valid() && !$pr->get_data()->is_optimized()) { $r = $pr->optimize(); echo "$id ", is_wp_error($r) ? 'ERR' : 'cola', "\n"; } }
@unlink(ABSPATH . 'rx-p54.php'); echo is_file(ABSPATH . 'rx-p54.php') ? "rx sigue\n" : "rx borrado\n";
