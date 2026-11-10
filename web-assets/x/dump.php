<?php
global $wpdb;
$part = getenv('PART');
$bn = function($id){ $f = get_post_meta($id,'_wp_attached_file',true); return basename($f); };
if ($part==='p') {
  foreach (get_posts(['post_type'=>'proyecto','post_status'=>'any','numberposts'=>-1,'orderby'=>'ID','order'=>'ASC']) as $p) {
    $g = get_post_meta($p->ID,'galeria',true);
    if (is_array($g)) $g = implode(',', array_map(fn($x)=>is_array($x)?($x['id']??''):$x, $g));
    $ids = array_filter(array_map('trim', explode(',', (string)$g)));
    $t = get_post_thumbnail_id($p->ID);
    $o = "P{$p->ID}|{$p->post_status}|{$p->post_title}|".get_post_meta($p->ID,'cliente',true)."|".get_post_meta($p->ID,'sector',true)."|T{$t}:".$bn($t)."|G:";
    foreach ($ids as $i) $o .= "$i:".$bn($i)." ";
    $kids = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_parent=%d AND post_type='attachment'",$p->ID));
    $extra = array_diff($kids, $ids, [$t]);
    if ($extra) $o .= "|X:".implode(',',$extra);
    echo $o."\n";
  }
} elseif ($part==='b') {
  $rows = $wpdb->get_results("SELECT a.ID, a.post_parent pp, p.post_title pt, p.post_status ps FROM {$wpdb->posts} a JOIN {$wpdb->posts} p ON p.ID=a.post_parent WHERE a.post_type='attachment' AND p.post_type='post' ORDER BY a.post_parent, a.ID");
  foreach ($rows as $r) { $f = (get_post_thumbnail_id($r->pp)==$r->ID)?'F':'-'; echo "{$r->ID}|{$r->pp}|{$r->ps}|$f|".$bn($r->ID)."|".mb_substr($r->pt,0,70)."\n"; }
} else {
  $rows = $wpdb->get_results("SELECT a.ID, a.post_title FROM {$wpdb->posts} a WHERE a.post_type='attachment' AND a.post_mime_type LIKE 'image/%' AND a.post_parent=0 ORDER BY a.ID");
  foreach ($rows as $r) {
    $used = $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_thumbnail_id' AND meta_value=%d LIMIT 3", $r->ID));
    $like = '%"id":'.$r->ID.'%';
    $el = $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_elementor_data' AND meta_value LIKE %s LIMIT 3", '%'.$wpdb->esc_like($bn($r->ID)).'%'));
    echo "{$r->ID}|".$bn($r->ID)."|".mb_substr($r->post_title,0,40)."|th:".implode(',',$used)."|el:".implode(',',$el)."\n";
  }
}
