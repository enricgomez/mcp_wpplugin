<?php
// Convierte marcadores en _elementor_data a etiquetas dinámicas propias.
// Texto "{{campo}}" -> eg-dato; imágenes/enlaces por etiqueta del elemento (mapa) -> eg-imagen / eg-url / post-url
if ( ! function_exists( 'eg_dyn' ) ) { function eg_dyn($name, $campo = null) { $v = ['name'=>$name,'group'=>'eg','settings'=>$campo===null?[]:['campo'=>['$$type'=>'string','value'=>$campo]]]; if ($campo===null) $v['group']='post'; return ['$$type'=>'dynamic','value'=>$v]; }
function eg_walk(&$els, $imgs, $urls, &$n) {
  foreach ($els as &$e) {
    $lab = $e['editor_settings']['title'] ?? '';
    if (!empty($e['settings']) && is_array($e['settings'])) foreach ($e['settings'] as $k => &$s) {
      if (is_array($s) && ($s['$$type'] ?? '') === 'escaped-html' && is_string($s['value']) && preg_match('/^\{\{([a-z0-9_.]+)\}\}$/', $s['value'], $m)) { $s = eg_dyn('eg-dato', $m[1]); $n++; }
      if ($k === 'image' && isset($imgs[$lab])) { $s['value']['src'] = eg_dyn('eg-imagen', $imgs[$lab]); $n++; }
      if ($k === 'link' && isset($urls[$lab])) { $s['value']['destination'] = $urls[$lab] === 'post' ? eg_dyn('post-url') : eg_dyn('eg-url', $urls[$lab]); $n++; }
    } unset($s);
    if (!empty($e['elements'])) eg_walk($e['elements'], $imgs, $urls, $n);
  } unset($e);
}
}
$cfg = json_decode(file_get_contents('/tmp/eg-dyn.json'), true);
foreach ($cfg as $c) {
  $id = (int)$c['post_id']; $d = json_decode(get_post_meta($id, '_elementor_data', true), true); $n = 0;
  eg_walk($d, $c['imgs'] ?? [], $c['urls'] ?? [], $n);
  update_post_meta($id, '_elementor_data', wp_slash(wp_json_encode($d)));
  \Elementor\Plugin::$instance->files_manager->clear_cache();
  echo "post $id: $n reemplazos\n";
}
