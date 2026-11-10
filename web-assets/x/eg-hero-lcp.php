<?php
/* Imagen de portada (LCP) sin lazy y con prioridad alta. 10/11/2026 */
add_filter('rocket_buffer', function ($h) {
  return preg_replace_callback('/<img\b[^>]*eg-hero-fondo[^>]*>/', function ($m) {
    $t = preg_replace('/\sloading="lazy"/', '', $m[0]);
    return strpos($t, 'fetchpriority') === false ? preg_replace('/^<img\s+/', '<img fetchpriority="high" ', $t) : $t;
  }, $h, 1);
}, 20);
