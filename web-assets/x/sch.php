<?php
$em = \Elementor\Plugin::$instance->elements_manager; $wm = \Elementor\Plugin::$instance->widgets_manager;
foreach (['e-collection-loop','e-pagination'] as $t) {
  $c = $wm->get_widget_types($t) ?: $em->get_element_types($t);
  if (!$c) { echo "$t: no\n"; continue; }
  $s = method_exists($c,'get_props_schema') ? $c::get_props_schema() : [];
  echo "$t: "; foreach ($s as $k=>$v) echo $k, '=', substr(json_encode($v->jsonSerialize()),0,500), "\n  "; echo "\n";
}
echo 'posts_per_page=', get_option('posts_per_page'), ' page_for_posts=', get_option('page_for_posts'), ' show_on_front=', get_option('show_on_front'), "\n";
