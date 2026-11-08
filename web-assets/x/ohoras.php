<?php
$q = new WP_Query(['post_type'=>['product','page','post'],'s'=>'horas','posts_per_page'=>20,'post_status'=>'any']);
foreach ($q->posts as $p) { echo "== {$p->ID} {$p->post_type} {$p->post_status} {$p->post_name} | {$p->post_title}\n";
 if ($p->post_type=='product') { $pr = wc_get_product($p->ID); echo "precio: ".$pr->get_price()." tipo: ".$pr->get_type()."\n";
  if ($pr->is_type('variable')) foreach ($pr->get_available_variations() as $v) echo "  var: ".json_encode($v['attributes'],JSON_UNESCAPED_UNICODE)." ".$v['display_price']."\n";
  echo "corta: ".wp_strip_all_tags($pr->get_short_description())."\n"; echo "desc: ".mb_substr(wp_strip_all_tags($p->post_content),0,2500)."\n";
  $y=get_post_meta($p->ID,'rank_math_title',true).' | '.get_post_meta($p->ID,'rank_math_description',true).' | '.get_post_meta($p->ID,'rank_math_focus_keyword',true); echo "seo: $y\n"; } }
