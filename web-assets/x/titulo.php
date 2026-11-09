<?php
// Sustituye «ingeniero multimedia» por el título real en todo el contenido (datos Elementor serializados incluidos)
$pairs = ['Ingeniero multimedia' => 'Graduado en Multimedia (UPC)', 'ingeniero multimedia' => 'graduado en Multimedia (UPC)'];
foreach ($pairs as $a => $b) {
  $r = WP_CLI::runcommand("search-replace '$a' '$b' wp_posts wp_postmeta wp_options --precise --report-changed-only --format=count", ['return' => true, 'exit_error' => false]);
  echo "$a: $r\n";
}
global $wpdb;
echo 'quedan: ', $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_value LIKE '%ngeniero multimedia%'") + $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content LIKE '%ngeniero multimedia%' AND post_type<>'revision'"), "\n";
if (class_exists('\Elementor\Plugin')) \Elementor\Plugin::$instance->files_manager->clear_cache();
if (function_exists('rocket_clean_domain')) rocket_clean_domain();
echo "ok\n";
