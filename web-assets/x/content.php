<?php
global $wpdb;
$map = [];
foreach ($wpdb->get_results("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key='_wp_attached_file'") as $r) {
  $b = strtolower(preg_replace('/(-scaled)?\.(jpe?g|png|webp|gif)$/i','',basename($r->meta_value)));
  $map[$b] = (int)$r->post_id;
}
$key = function($src){ $b = strtolower(basename(parse_url($src, PHP_URL_PATH) ?? '')); $b = rawurldecode($b);
  $b = preg_replace('/\.(jpe?g|png|webp|gif)$/i','',$b); $b = preg_replace('/-\d+x\d+$/','',$b); return preg_replace('/-scaled$/','',$b); };
$c=0;$imgs=0;$miss=[];
foreach ($wpdb->get_results("SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type IN ('post','page','proyecto') AND post_status='publish' AND post_content LIKE '%<img%'") as $r) {
  $h = new WP_HTML_Tag_Processor($r->post_content); $ch=false;
  while ($h->next_tag('img')) {
    $id = 0; if (preg_match('/wp-image-(\d+)/',(string)$h->get_attribute('class'),$m)) $id=(int)$m[1];
    if (!$id) { $k=$key((string)$h->get_attribute('src')); $id = $map[$k] ?? 0; if(!$id) $miss[]=$r->ID.':'.$k; }
    if (!$id) continue;
    $alt = get_post_meta($id,'_wp_attachment_image_alt',true);
    if ($alt && $h->get_attribute('alt')!==$alt) { $h->set_attribute('alt',$alt); $ch=true; $imgs++; }
  }
  if ($ch) {
    if (!metadata_exists('post',$r->ID,'_eg_content_alt_bak')) add_post_meta($r->ID,'_eg_content_alt_bak',wp_slash($r->post_content));
    $wpdb->update($wpdb->posts,['post_content'=>$h->get_updated_html()],['ID'=>$r->ID]); clean_post_cache($r->ID); $c++;
  }
}
echo "CONTENT posts=$c imgs=$imgs miss=".count($miss)."\n".implode("\n",array_slice($miss,0,15))."\n";
// cuántos posts tienen imágenes en Elementor
echo "POSTS_ELEMENTOR ".$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE p.post_type='post' AND m.meta_key='_elementor_data' AND LENGTH(m.meta_value)>10")."\n";
echo "POSTS_IMG ".$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish' AND post_content LIKE '%<img%'")."\n";
