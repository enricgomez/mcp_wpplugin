<?php
foreach ([54515,54243,30297,69109] as $id) { $p=get_post($id); if(!$p){echo "$id NO\n";continue;}
 $el=get_post_meta($id,'_elementor_edit_mode',true);
 $c=$p->post_content; echo "$id {$p->post_name} | {$p->post_title} | {$p->post_status} | el=$el | len=".strlen($c)." | mod={$p->post_modified}\n";
 file_put_contents("/tmp/eg-legal-$id.html", $el ? \Elementor\Plugin::$instance->frontend->get_builder_content($id,false) : apply_filters('the_content',$c)); chmod("/tmp/eg-legal-$id.html",0644);
 echo "  out=".filesize("/tmp/eg-legal-$id.html")."\n"; }
