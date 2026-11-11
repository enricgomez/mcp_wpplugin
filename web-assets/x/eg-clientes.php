<?php
// Sustituye los nombres de «Confían en el estudio» (fila 1d794580, página 6) por 15 clientes x2 (bucle continuo).
$labels = json_decode(file_get_contents('/tmp/eg-clientes.json'), true);
$raw = get_post_meta(6, '_elementor_data', true);
if (!get_post_meta(6, '_eg_edata_bak_clientes_20261111', true)) add_post_meta(6, '_eg_edata_bak_clientes_20261111', wp_slash($raw));
$d = json_decode($raw, true); $done = 0;
$walk = function (&$els) use (&$walk, $labels, &$done) {
  foreach ($els as &$e) {
    if (($e['id'] ?? '') === '1d794580') {
      $old = $e['elements'];
      echo "antes: " . count($old) . "\n";
      $tpl = wp_json_encode($old[0]);
      $find = null;
      array_walk_recursive($old[0]['settings'], function ($v) use (&$find) { if (is_string($v) && $v === 'Hotel Miami Calella') $find = $v; });
      if (!$find) { echo "NO_TEXTO\n"; return; }
      $new = [];
      foreach (array_merge($labels, $labels) as $l) {
        $w = json_decode(str_replace('"Hotel Miami Calella"', wp_json_encode($l), $tpl), true);
        $w['id'] = substr(md5('egcli' . count($new) . $l), 0, 7);
        $new[] = $w;
      }
      $e['elements'] = $new; $done++;
      echo "despues: " . count($new) . "\n";
    }
    if (!empty($e['elements'])) $walk($e['elements']);
  }
};
$walk($d);
if ($done) update_post_meta(6, '_elementor_data', wp_slash(wp_json_encode($d)));
echo "cambiado=$done\n";
\Elementor\Plugin::$instance->files_manager->clear_cache();
