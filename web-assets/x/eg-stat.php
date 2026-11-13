<?php
$d = json_decode(get_post_meta(6, '_elementor_data', true), true); $n = 0;
$w = function (&$els) use (&$w, &$n) { foreach ($els as &$e) { if (($e['id'] ?? '') === '55e9f640') { $e['settings']['paragraph']['value'] = '<b>Tiendas</b><br>WooCommerce'; $n++; } if (!empty($e['elements'])) $w($e['elements']); } };
$w($d); if ($n) update_post_meta(6, '_elementor_data', wp_slash(wp_json_encode($d))); echo "stat=$n\n";
\Elementor\Plugin::$instance->files_manager->clear_cache();
