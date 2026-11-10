<?php
/* Imagen de portada (LCP) sin lazy y con prioridad alta. 10/11/2026 */
add_filter('rocket_buffer', function ($h) {
  return preg_replace('/<img loading="lazy"([^>]*eg-hero-fondo)/', '<img fetchpriority="high"$1', $h, 1);
}, 20);
