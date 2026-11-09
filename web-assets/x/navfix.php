<?php
$f = get_theme_root() . '/eg-studio/assets/eg.css';
$c = file_get_contents($f);
if (strpos($c, '.eg-pr-adyacente a{box-sizing:border-box}') === false) {
  file_put_contents($f, $c . "\n/* Navegación anterior/siguiente: la tarjeta no debe desbordar su párrafo (tocaba el pie) (09/11/2026) */\nhtml .elementor .eg-pr-adyacente a{box-sizing:border-box}\n");
  echo "añadido\n";
} else echo "ya estaba\n";
if (function_exists('rocket_clean_domain')) rocket_clean_domain();
echo filesize($f), "\n";
