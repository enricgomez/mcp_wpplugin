<?php
// Exporta los posts del blog antiguo a /tmp/eg-blog.json y copia sus imágenes a /tmp/eg-blogimg/ (legibles por egcloud)
$dir = '/tmp/eg-blogimg'; if (!is_dir($dir)) mkdir($dir, 0755); chmod($dir, 0755);
$up = wp_upload_dir(); $out = []; $files = 0; $miss = [];
$copy = function ($path) use ($dir, &$files) { if (!$path || !is_file($path)) return null; $n = md5($path) . '.' . strtolower(pathinfo($path, PATHINFO_EXTENSION)); $d = "$dir/$n"; if (!is_file($d)) { copy($path, $d); chmod($d, 0644); $files++; } return $n; };
foreach (get_posts(['post_type'=>'post','post_status'=>'publish','numberposts'=>-1]) as $p) {
  $imgs = [];
  $tid = get_post_thumbnail_id($p); if ($tid) $imgs['t'] = ['f'=>$copy(get_attached_file($tid)), 'alt'=>get_post_meta($tid,'_wp_attachment_image_alt',true)];
  if (preg_match_all('/image="(\d+)"/', $p->post_content, $m)) foreach ($m[1] as $id) $imgs['id'.$id] = ['f'=>$copy(get_attached_file((int)$id)), 'alt'=>get_post_meta((int)$id,'_wp_attachment_image_alt',true)];
  if (preg_match_all('/<img[^>]+src="([^"]+)"/', $p->post_content, $m)) foreach ($m[1] as $u) {
    $path = null; if (preg_match('#/wp-content/uploads/(.+)$#', parse_url($u, PHP_URL_PATH) ?? '', $mm)) $path = $up['basedir'] . '/' . $mm[1];
    $f = $copy($path); if (!$f) { $full = preg_replace('/-\d+x\d+(\.\w+)$/', '$1', (string)$path); $f = $copy($full); } if (!$f) $miss[] = $u; $imgs['u'.md5($u)] = ['f'=>$f, 'u'=>$u];
  }
  $out[] = ['id'=>$p->ID, 'slug'=>$p->post_name, 'title'=>$p->post_title, 'date'=>$p->post_date, 'date_gmt'=>$p->post_date_gmt, 'modified'=>$p->post_modified, 'excerpt'=>$p->post_excerpt, 'content'=>$p->post_content,
    'cats'=>wp_get_post_categories($p->ID, ['fields'=>'slugs']), 'tags'=>wp_get_post_tags($p->ID, ['fields'=>'names']),
    'seo'=>['title'=>get_post_meta($p->ID,'_yoast_wpseo_title',true), 'desc'=>get_post_meta($p->ID,'_yoast_wpseo_metadesc',true), 'kw'=>get_post_meta($p->ID,'_yoast_wpseo_focuskw',true), 'noindex'=>get_post_meta($p->ID,'_yoast_wpseo_meta-robots-noindex',true), 'canon'=>get_post_meta($p->ID,'_yoast_wpseo_canonical',true)],
    'imgs'=>$imgs, 'url'=>get_permalink($p)];
}
file_put_contents('/tmp/eg-blog.json', json_encode($out, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); chmod('/tmp/eg-blog.json', 0644);
echo count($out), " posts | ficheros copiados: $files | json ", filesize('/tmp/eg-blog.json'), " | faltan: ", count($miss), "\n", implode("\n", array_slice($miss, 0, 12)), "\n";
echo trim(shell_exec("du -sh $dir")), "\n";
// muestras de shortcodes raros
$all = implode("\n", array_column($out, 'content'));
foreach (['dt_default_button','products','ult_content_box','vc_raw_html','vc_custom_heading','vc_video','dm_code_snippet','vc_single_image'] as $s) { preg_match('/\[' . $s . '[^\]]*\]/', $all, $m); echo "$s: ", substr($m[0] ?? '-', 0, 260), "\n"; }
