<?php
// Lista proyectos con su web y fecha actual
foreach (get_posts(['post_type'=>'proyecto','post_status'=>'publish','numberposts'=>-1,'orderby'=>'date','order'=>'DESC']) as $p)
  echo $p->ID, "\t", $p->post_name, "\t", substr($p->post_date,0,10), "\t", get_post_meta($p->ID,'web',true), "\t", get_post_meta($p->ID,'anio',true), "\t", get_post_meta($p->ID,'_eg_old_url',true) ? 'old' : '', "\n";
