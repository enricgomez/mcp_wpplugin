<?php
/**
 * enricgomez.com: 404 heredados de la web antigua (10/11/2026).
 * 1) URL antiguas con «/enricgomez.com» pegado al final (enlace relativo de la web vieja): 301 a la URL limpia,
 *    que a su vez ya redirige a su destino nuevo.
 * 2) Posts de spam de la infección de octubre ya borrados: 410 para que Google los olvide.
 */
add_action('template_redirect', function () {
  if (!is_404()) return;
  $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
  if (preg_match('#^(.*?)/+(?:www\.)?enricgomez\.com/?$#i', $path, $m)) {
    $dest = $m[1] === '' ? '/' : trailingslashit($m[1]);
    wp_redirect(home_url($dest), 301, 'EG legado');
    exit;
  }
  $dec = rawurldecode($path);
  if (preg_match('#(zs777|x777|betrupees|pkrbet|ikibu|casino|free-spins|escort|welcome-offer|might-of-ra|ksa-license|πώς)#iu', $dec)) {
    status_header(410);
    nocache_headers();
    header('X-Robots-Tag: noindex');
    echo '<!doctype html><meta charset="utf-8"><title>Contenido eliminado</title><p>Este contenido ya no existe. <a href="/">Ir a enricgomez Studio</a></p>';
    exit;
  }
}, 99);
