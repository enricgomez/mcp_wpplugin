<?php
$s = get_option('wp_rocket_settings');
$s['cdn']=1; $s['cdn_cnames']=['https://enricgomez.b-cdn.net']; $s['cdn_zone']=['all']; $s['cdn_reject_files']=[];
update_option('wp_rocket_settings',$s);
if (function_exists('rocket_clean_domain')) rocket_clean_domain(); if (function_exists('rocket_clean_minify')) rocket_clean_minify();
echo "cdn ok\n";
// muestra de 301 de la web antigua
global $wpdb; $urls = $wpdb->get_col("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key='_eg_old_url' ORDER BY RAND() LIMIT 12");
foreach ($urls as $u) { $u = preg_replace('#^https?://[^/]+#','https://www.enricgomez.com',$u);
 $r = wp_remote_head($u, ['redirection'=>0,'timeout'=>15,'sslverify'=>false]);
 $c = wp_remote_retrieve_response_code($r); $l = wp_remote_retrieve_header($r,'location');
 $f = $l ? wp_remote_retrieve_response_code(wp_remote_head($l,['timeout'=>15,'sslverify'=>false])) : '';
 echo "$c $f ".parse_url($u,PHP_URL_PATH)." -> ".($l?parse_url($l,PHP_URL_PATH):'')."\n"; }
echo "redir=", $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}rank_math_redirections WHERE status='active'"), "\n";
