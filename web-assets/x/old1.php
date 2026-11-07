<?php
echo "perm=", get_option('permalink_structure'), " catbase=", get_option('category_base'), " tagbase=", get_option('tag_base'), "\n";
echo "== pages\n"; foreach ( get_pages(['post_status'=>'publish']) as $p ) echo wp_make_link_relative(get_permalink($p)), " | ", $p->post_title, "\n";
echo "== tipos\n"; foreach ( get_post_types(['public'=>true]) as $t ) { $c = wp_count_posts($t); echo "$t ", $c->publish ?? 0, " | ex: "; $x=get_posts(['post_type'=>$t,'numberposts'=>2]); foreach($x as $y) echo wp_make_link_relative(get_permalink($y)), " "; echo "\n"; }
echo "== tax\n"; foreach ( get_taxonomies(['public'=>true]) as $t ) { $ts=get_terms(['taxonomy'=>$t,'hide_empty'=>true,'number'=>3]); echo "$t ", wp_count_terms(['taxonomy'=>$t,'hide_empty'=>true]), " ex: "; if(!is_wp_error($ts)) foreach($ts as $x) echo wp_make_link_relative(get_term_link($x)), " "; echo "\n"; }
echo "wpml=", defined('ICL_SITEPRESS_VERSION')?'si':'no', " polylang=", function_exists('pll_languages_list')?implode(',',pll_languages_list()):'no', "\n";
