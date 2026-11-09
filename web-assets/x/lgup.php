<?php
$L = json_decode(file_get_contents('/tmp/eg-legal2.json'), true);
foreach ($L as $pg) { $p = get_page_by_path($pg['slug']); if (!$p) { echo "NO {$pg['slug']}\n"; continue; }
 wp_update_post(['ID'=>$p->ID,'post_content'=>$pg['html'],'post_title'=>$pg['title']]);
 update_post_meta($p->ID,'rank_math_title',$pg['seo'][0]); update_post_meta($p->ID,'rank_math_description',$pg['seo'][1]);
 echo "$p->ID /{$pg['slug']}/ ".strlen($pg['html'])."\n"; }
$css = get_stylesheet_directory().'/assets/eg.css'; $c = file_get_contents($css);
if (strpos($c,'.eg-tabla')===false) { file_put_contents($css, $c."\n/* Tablas de los textos legales (09/11/2026) */\nhtml .eg-tabla{overflow-x:auto;margin:1.5rem 0;-webkit-overflow-scrolling:touch}\nhtml .eg-tabla table{width:100%;border-collapse:collapse;font-size:.92em;line-height:1.5;min-width:36rem}\nhtml .eg-tabla th,html .eg-tabla td{text-align:left;vertical-align:top;padding:.75rem .9rem;border-bottom:1px solid rgba(24,36,43,.12)}\nhtml .eg-tabla th{font-weight:600;background:rgba(93,188,233,.12)}\nhtml .eg-tabla code{font-size:.85em;word-break:break-all}\n"); echo "css ok\n"; }
