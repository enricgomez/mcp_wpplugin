<?php
do_action('rest_api_init');
foreach (get_posts(['post_type'=>'proyecto','numberposts'=>-1,'orderby'=>'date','order'=>'desc']) as $p) {
  $d = get_post_meta($p->ID,'datos',true); $g = get_post_meta($p->ID,'galeria',true);
  echo $p->ID,' ',$p->post_name,' | serv=',get_post_meta($p->ID,'servicio',true),' cli=',get_post_meta($p->ID,'cliente',true),' sec=',get_post_meta($p->ID,'sector',true),' anio=',get_post_meta($p->ID,'anio',true),' web=',get_post_meta($p->ID,'web',true),' datos=',is_array($d)?count($d):json_encode($d),' gal=',$g?count(explode(',',$g)):0,' thumb=',get_post_thumbnail_id($p->ID),' len=',strlen($p->post_content),' tip=',implode(',',wp_get_post_terms($p->ID,'tipologia',['fields'=>'names'])),"\n";
}
$p = get_page_by_path('hotel-miami-calella-web',OBJECT,'proyecto'); echo "\n--- content 447:\n", substr($p->post_content,0,1500), "\n";
$p = get_page_by_path('visit-pineda',OBJECT,'proyecto'); echo "\n--- content visit-pineda:\n", substr($p->post_content,0,1500), "\n";
$w = \Elementor\Plugin::$instance->widgets_manager->get_widget_types(); echo "\nwidgets e-*: ", implode(' ', array_filter(array_keys($w), fn($k)=>str_starts_with($k,'e-'))), "\n";
$a = wp_get_ability('elementor/manage-site-parts'); echo json_encode($a->get_input_schema()), "\n";
