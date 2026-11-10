<?php
// SEO de imágenes: ALT + título de adjuntos, y ALT dentro del contenido de entradas/páginas.
global $wpdb;
$set = [];
foreach (file('/tmp/eg-alts.tsv', FILE_IGNORE_NEW_LINES) as $l) {
  if (!trim($l)) continue; [$id, $alt] = explode("\t", $l, 2); $set[(int)$id] = trim($alt);
}
// Proyectos con capturas nuevas: según el nombre del fichero
foreach (get_posts(['post_type'=>'proyecto','post_status'=>'any','numberposts'=>-1]) as $p) {
  $cli = trim(get_post_meta($p->ID,'cliente',true)) ?: $p->post_title;
  $sec = mb_strtolower(trim(get_post_meta($p->ID,'sector',true)));
  $ids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_parent=%d", $p->ID));
  $t = (int)get_post_thumbnail_id($p->ID); if ($t) $ids[] = $t;
  $n = 0;
  foreach (array_unique($ids) as $a) {
    $a=(int)$a; if (isset($set[$a])) continue;
    $f = basename(get_post_meta($a,'_wp_attached_file',true));
    if (strpos($f,'portada')!==false) $alt = "Web de $cli".($sec?" ($sec)":"")." en ordenador y móvil";
    elseif (strpos($f,'pantalla')!==false) { $n++; $alt = $n==1 ? "Página interior de la web de $cli en escritorio" : "Otra sección de la web de $cli en escritorio"; }
    elseif (strpos($f,'movil')!==false) $alt = "Web de $cli en versión móvil";
    elseif (strpos($f,'detalle')!==false) $alt = "Detalle del diseño de la web de $cli";
    elseif ($a==29) continue;
    else continue;
    $set[$a] = $alt;
  }
}
$set[1468] = 'enricgomez Studio: webs, inteligencia artificial, consultoría y sistemas';
$set[1381] = 'Icono de enricgomez Studio';
$n=0;
foreach ($set as $id=>$alt) {
  if (get_post_type($id)!=='attachment') continue;
  $old = get_post_meta($id,'_wp_attachment_image_alt',true);
  if (!metadata_exists('post',$id,'_eg_alt_old')) { update_post_meta($id,'_eg_alt_old',$old); update_post_meta($id,'_eg_title_old',get_the_title($id)); }
  update_post_meta($id,'_wp_attachment_image_alt',$alt);
  $wpdb->update($wpdb->posts,['post_title'=>$alt],['ID'=>$id]); clean_post_cache($id); $n++;
}
echo "ADJ $n\n";
// ALT dentro del contenido (img con clase wp-image-ID)
$c=0; $imgs=0;
foreach ($wpdb->get_results("SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type IN ('post','page','proyecto') AND post_status='publish' AND post_content LIKE '%<img%'") as $r) {
  $h = new WP_HTML_Tag_Processor($r->post_content); $ch=false;
  while ($h->next_tag('img')) {
    $cl = (string)$h->get_attribute('class'); $alt = null;
    if (preg_match('/wp-image-(\d+)/',$cl,$m)) $alt = get_post_meta((int)$m[1],'_wp_attachment_image_alt',true);
    if ($alt) { if ($h->get_attribute('alt')!==$alt) { $h->set_attribute('alt',$alt); $ch=true; $imgs++; } }
  }
  if ($ch) {
    if (!metadata_exists('post',$r->ID,'_eg_content_alt_bak')) update_post_meta($r->ID,'_eg_content_alt_bak',wp_slash($r->post_content));
    $wpdb->update($wpdb->posts,['post_content'=>$h->get_updated_html()],['ID'=>$r->ID]); clean_post_cache($r->ID); $c++;
  }
}
echo "CONTENT posts=$c imgs=$imgs\n";
// imágenes del contenido sin clase wp-image (para revisar)
$no = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish' AND post_content REGEXP '<img(?![^>]*wp-image-)'");
echo "SIN_CLASE $no\n";
