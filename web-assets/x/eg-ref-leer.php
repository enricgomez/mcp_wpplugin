<?php
// Solo lectura: ajustes de los widgets que cambia el refuerzo de diseño web.
$ids = [6 => ['7eceb463','4e983283','35c6fc7d','3b6c81d6','4370412a','37d2a1ef','1b4c6ab1','55e9f640','310df3a1','7f6bf472','4c9879e8','36faac10','6bec2b27','659a09be','13dfe621','65bded69'],
        1431 => ['a203430','4eed6d39','6b6da12d','4306b536'], 1433 => ['958cdc4','74be136b','53c7203d','69d73431','6996e84e']];
foreach ($ids as $pid => $want) {
  $d = json_decode(get_post_meta($pid, '_elementor_data', true), true);
  $walk = function ($els, $parent) use (&$walk, $want, $pid) {
    foreach ($els as $i => $e) {
      if (in_array($e['id'] ?? '', $want, true)) echo "$pid {$e['id']} parent=$parent i=$i " . ($e['widgetType'] ?? $e['elType']) . ' ' . wp_json_encode($e['settings'], JSON_UNESCAPED_UNICODE) . "\n";
      if (!empty($e['elements'])) $walk($e['elements'], $e['id'] ?? '');
    }
  };
  $walk($d, 'root');
  echo "$pid RM: " . get_post_meta($pid, 'rank_math_title', true) . ' || ' . get_post_meta($pid, 'rank_math_description', true) . ' || ' . get_post_meta($pid, 'rank_math_rich_snippet', true) . "\n";
}
echo 'llms: ' . substr((string) get_option('llms_extra_content'), 0, 300) . "\n";
echo 'rm titles keys: ' . implode(',', array_filter(array_keys((array) get_option('rank-math-options-titles')), fn($k) => preg_match('/local|knowledge|org|pt_page_default_rich|website_name|phone|url|logo|social/', $k))) . "\n";
