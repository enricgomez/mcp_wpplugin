<?php
include '/tmp/eg-lgup.php';
$css = get_stylesheet_directory().'/assets/eg.css'; $c = file_get_contents($css);
if (strpos($c,'eg-tabla td::before')===false) { file_put_contents($css, $c."html .eg-tabla table{font-family:inherit}\n@media (max-width:640px){html .eg-tabla table{min-width:0}html .eg-tabla thead{display:none}html .eg-tabla tr{display:block;padding:1rem 0;border-bottom:1px solid rgba(24,36,43,.12)}html .eg-tabla td{display:block;border:0;padding:.15rem 0}html .eg-tabla td+td::before{content:attr(data-label);display:block;margin-top:.6rem;font-weight:600;font-size:.78em;letter-spacing:.04em;text-transform:uppercase;opacity:.6}}\n"); echo "css2 ok\n"; }
wp_cache_flush_group('posts');
