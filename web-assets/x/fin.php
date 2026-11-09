<?php
echo file_get_contents('/tmp/eg-p54.log');
include '/tmp/eg-ord2.php';
global $wpdb;
// imágenes antiguas de Adrián (capturas sin cabecera)
$old = $wpdb->get_col("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_eg_src' AND meta_value LIKE 'cap-out-adriansanchezgarrido-%'");
$g = get_post_meta(1786, 'galeria', true); $th = get_post_thumbnail_id(1786);
foreach ($old as $id) { $id = (int) $id; if ($id === (int) $th || in_array($id, array_map('intval', explode(',', $g)), true)) { echo "en uso $id\n"; continue; } if (get_post_type($id) === 'attachment') { wp_delete_attachment($id, true); echo "borrada $id\n"; } }
// Imagify de las nuevas
$new = $wpdb->get_col("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_eg_src' AND (meta_value LIKE 'cap-out-adrianb-%' OR meta_value REGEXP '^cap-out-(africaselectfund|igcat|hotelneptuno|analisisgerencial|cbmdisseny|dulcelucca|miriamtirado|desirevips)-')");
echo 'nuevas: ', count($new), "\n";
foreach ($new as $id) { $p = imagify_get_optimization_process((int) $id, 'wp'); if (!$p->is_valid()) continue; if ($p->get_data()->is_optimized()) continue; $r = $p->optimize(); echo "$id ", is_wp_error($r) ? 'ERR ' . $r->get_error_message() : 'cola', "\n"; }
@unlink(ABSPATH . 'rx-p54.php'); echo is_file(ABSPATH . 'rx-p54.php') ? "rx sigue\n" : "rx borrado\n";
if (function_exists('rocket_clean_domain')) rocket_clean_domain();
