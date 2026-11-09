<?php
global $wpdb;
$map = ['Ingeniero multimedia' => 'Graduado en Multimedia (UPC)', 'ingeniero multimedia' => 'graduado en Multimedia (UPC)'];
$rep = function ($v) use (&$rep, $map) {
  if (is_string($v)) return strtr($v, $map);
  if (is_array($v)) { foreach ($v as $k => $x) $v[$k] = $rep($x); return $v; }
  return $v;
};
$n = 0;
foreach ($wpdb->get_results("SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type<>'revision' AND (p.post_content LIKE '%ngeniero multimedia%' OR p.post_excerpt LIKE '%ngeniero multimedia%' OR p.post_title LIKE '%ngeniero multimedia%')") as $r) {
  $p = get_post($r->ID);
  $wpdb->update($wpdb->posts, ['post_content' => strtr($p->post_content, $map), 'post_excerpt' => strtr($p->post_excerpt, $map), 'post_title' => strtr($p->post_title, $map)], ['ID' => $p->ID]);
  clean_post_cache($p->ID); $n++; echo "post {$p->ID}\n";
}
foreach ($wpdb->get_results("SELECT m.meta_id, m.post_id, m.meta_key, m.meta_value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE p.post_type<>'revision' AND m.meta_value LIKE '%ngeniero multimedia%'") as $m) {
  $v = maybe_unserialize($m->meta_value);
  $nv = is_array($v) ? serialize($rep($v)) : strtr($m->meta_value, $map);
  $wpdb->update($wpdb->postmeta, ['meta_value' => $nv], ['meta_id' => $m->meta_id]);
  clean_post_cache($m->post_id); delete_post_meta($m->post_id, '_elementor_element_cache'); $n++; echo "meta {$m->post_id} {$m->meta_key}\n";
}
foreach ($wpdb->get_results("SELECT option_name FROM {$wpdb->options} WHERE option_value LIKE '%ngeniero multimedia%'") as $o) {
  $v = get_option($o->option_name); update_option($o->option_name, $rep($v)); echo "opt {$o->option_name}\n";
}
echo "quedan: ", $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE p.post_type<>'revision' AND m.meta_value LIKE '%ngeniero multimedia%'"), "\n";
\Elementor\Plugin::$instance->files_manager->clear_cache(); wp_cache_flush();
if (function_exists('rocket_clean_domain')) rocket_clean_domain();
echo "ok $n\n";
