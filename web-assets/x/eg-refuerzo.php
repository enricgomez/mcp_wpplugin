<?php
// Refuerzo de diseño web en enricgomez.com (13/11/2026). Copias: meta _eg_edata_bak_refuerzo_20261113 y _eg_rm_bak_refuerzo_20261113.
$H = 'https://www.enricgomez.com/';
$CONT = $H . 'contacto/';
function eg_bak($pid) {
  if (!get_post_meta($pid, '_eg_edata_bak_refuerzo_20261113', true)) add_post_meta($pid, '_eg_edata_bak_refuerzo_20261113', wp_slash(get_post_meta($pid, '_elementor_data', true)));
  if (!get_post_meta($pid, '_eg_rm_bak_refuerzo_20261113', true)) add_post_meta($pid, '_eg_rm_bak_refuerzo_20261113', [get_post_meta($pid, 'rank_math_title', true), get_post_meta($pid, 'rank_math_description', true), get_post_meta($pid, 'rank_math_focus_keyword', true)]);
}
// $map: id => [clave => valor] (clave 'link' = url; 'replace' = [de => a] dentro de cualquier valor del widget)
function eg_apply(&$els, $map, &$hits) {
  foreach ($els as &$e) {
    $id = $e['id'] ?? '';
    if (isset($map[$id])) {
      foreach ($map[$id] as $k => $v) {
        if ($k === 'link') { $e['settings']['link']['value']['destination'] = ['$$type' => 'url', 'value' => $v]; }
        elseif ($k === 'replace') { $j = wp_json_encode($e['settings'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); $j = str_replace(array_keys($v), array_values($v), $j); $e['settings'] = json_decode($j, true); }
        elseif ($k === 'classes') { $e['settings']['classes']['value'] = $v; }
        else { $e['settings'][$k] = ['$$type' => 'escaped-html', 'value' => $v]; }
      }
      $hits[$id] = true;
    }
    if (!empty($e['elements'])) eg_apply($e['elements'], $map, $hits);
  }
}
function eg_save($pid, $d) { update_post_meta($pid, '_elementor_data', wp_slash(wp_json_encode($d))); }
function eg_rm($pid, $t, $desc, $kw = null) { update_post_meta($pid, 'rank_math_title', $t); update_post_meta($pid, 'rank_math_description', $desc); if ($kw) update_post_meta($pid, 'rank_math_focus_keyword', $kw); }
function eg_report($name, $map, $hits) { $miss = array_diff(array_keys($map), array_keys($hits)); echo "$name: " . count($hits) . '/' . count($map) . ($miss ? ' FALTAN ' . implode(',', $miss) : '') . "\n"; }

/* ---------- Portada (6) ---------- */
eg_bak(6);
$d = json_decode(get_post_meta(6, '_elementor_data', true), true);
$map = [
  '4e983283' => ['title' => 'Diseño web que <span>vende.</span><br>Y tecnología para tu negocio.'],
  '35c6fc7d' => ['paragraph' => 'Estudio de diseño web en Calella desde 2006. Diseñamos y desarrollamos webs y tiendas online en WordPress y WooCommerce. Y cuando tu negocio lo pide, también inteligencia artificial, consultoría y sistemas a medida. Te lo explicamos, lo montamos y lo mantenemos.'],
  '3b6c81d6' => ['text' => 'Pide presupuesto para tu web <span>→</span>', 'link' => $CONT],
  '4370412a' => ['text' => 'Ver proyectos web <span>→</span>', 'link' => $H . 'proyectos/'],
  '37d2a1ef' => ['paragraph' => '<b>+300</b><br>webs hechas'],
  '1b4c6ab1' => ['paragraph' => '<b>20 años</b><br>con WordPress'],
  '55e9f640' => ['paragraph' => '<b>WooCommerce</b><br>tiendas online'],
  '1e81c0c8' => ['replace' => ['Escápate al mar' => 'Vive una auténtica experiencia']],
  '4c9879e8' => ['title' => 'Y además, todo lo que tu negocio digital necesita.'],
  '36faac10' => ['paragraph' => 'Lo que necesites, con una sola persona al otro lado. Cada servicio es una pieza del símbolo: puedes contratar una o combinarlas.'],
  '6bec2b27' => ['title' => 'Diseño web y tiendas online.'],
  '659a09be' => ['paragraph' => 'Webs y tiendas WordPress y WooCommerce con diseño propio, a medida o por una cuota mensual con hosting, mantenimiento y soporte incluidos.'],
  '13dfe621' => ['title' => 'Un estudio de diseño web pequeño, con 20 años de oficio.'],
  '65bded69' => ['paragraph' => 'Soy Enric Gómez. Desde 2006 diseño y desarrollo webs para empresas, hoteles y entidades del Maresme, de Barcelona y de fuera. Hoy eso significa también IA, automatización y sistemas a medida, con el mismo trato cercano de siempre.'],
];
$hits = []; eg_apply($d, $map, $hits); eg_report('portada', $map, $hits);

// Sección nueva «Diseño y desarrollo web» clonada de la de servicios (1f16e433), justo antes de ella.
$idx = null; foreach ($d as $i => $t) if (($t['id'] ?? '') === '1f16e433') $idx = $i;
$ya = strpos(wp_json_encode($d), 'nuestra especialidad') !== false;
if ($idx !== null && !$ya) {
  $sec = $d[$idx];
  unset($sec['settings']['_cssid']);
  $cards = [
    ['Diseño web a medida', 'Diseño propio con tu marca, pensado para que el cliente reserve, compre o llame. Nada de plantillas genéricas.', 'Diseño propio', $H . 'servicios/web/'],
    ['Tiendas online WooCommerce', 'Catálogo, pagos, envíos, facturas y stock, con una tienda que puedes gestionar tú.', 'Desde 149 €/mes', $H . 'servicios/web/tiendas-online-woocommerce/'],
    ['Expertos en WordPress', 'Desarrollo a medida, rescates, migraciones, velocidad y seguridad de webs que ya existen.', 'Rescates y migraciones', $H . 'servicios/web/experto-wordpress/'],
    ['Mantenimiento y soporte', 'Tu web siempre al día: actualizaciones, copias, seguridad y cambios cuando los necesitas.', 'Desde 29 €/mes', $H . 'servicios/web/mantenimiento/'],
  ];
  $n = 0;
  $re = function (&$e) use (&$re, &$n, $cards) {
    $e['id'] = substr(md5('egref' . ($e['id'] ?? '') . $n++), 0, 7);
    $t = $e['editor_settings']['title'] ?? '';
    if ($t) $e['editor_settings']['title'] = 'Especialidad web · ' . $t;
    if (!empty($e['elements'])) {
      // quitar la imagen de cada tarjeta
      $e['elements'] = array_values(array_filter($e['elements'], fn($c) => !preg_match('/ media$/', $c['editor_settings']['title'] ?? '')));
      foreach ($e['elements'] as &$c) $re($c);
    }
  };
  $re($sec);
  // textos: cabecera
  $sj = wp_json_encode($sec, JSON_UNESCAPED_UNICODE);
  $sec = json_decode($sj, true);
  $grid = null;
  $walk = function (&$els) use (&$walk, &$grid, $cards) {
    foreach ($els as &$e) {
      $t = $e['editor_settings']['title'] ?? '';
      if ($t === 'Especialidad web · Servicios kicker') $e['settings']['paragraph']['value'] = '<b>eg</b>&nbsp;web · nuestra especialidad';
      if ($t === 'Especialidad web · Servicios título') $e['settings']['title']['value'] = 'Diseño y desarrollo web, desde 2006.';
      if ($t === 'Especialidad web · Servicios entradilla') $e['settings']['paragraph']['value'] = 'Es lo que hemos hecho siempre y lo que mejor sabemos hacer: webs que se entienden, cargan rápido, aparecen en Google y en los asistentes de IA, y venden. Diseño propio, desarrollo en WordPress y una persona que te atiende antes, durante y después.';
      if ($t === 'Especialidad web · Servicios rejilla') {
        $k = 0;
        foreach ($e['elements'] as &$card) {
          [$h3, $txt, $precio, $url] = $cards[$k++];
          $card['settings']['link']['value']['destination'] = ['$$type' => 'url', 'value' => $url];
          $card['settings']['classes']['value'] = ['g-e3f5e68', 'g-f69678c'];
          $j = wp_json_encode($card, JSON_UNESCAPED_UNICODE);
          $c = json_decode($j, true);
          $fill = function (&$x) use (&$fill, $h3, $txt, $precio) {
            foreach ($x as &$w) {
              $tt = $w['editor_settings']['title'] ?? '';
              if (preg_match('/ marca$/', $tt)) $w['settings']['paragraph']['value'] = '<span></span><b>eg</b>&nbsp;web';
              if (preg_match('/ título$/', $tt)) $w['settings']['title']['value'] = $h3;
              if (preg_match('/ texto$/', $tt)) $w['settings']['paragraph']['value'] = $txt;
              if (preg_match('/ precio$/', $tt)) $w['settings']['paragraph']['value'] = $precio;
              if (!empty($w['elements'])) $fill($w['elements']);
            }
          };
          $fill($c['elements']);
          $card = $c;
        }
      }
      if (!empty($e['elements'])) $walk($e['elements']);
    }
  };
  $tmp = [$sec]; $walk($tmp); $sec = $tmp[0];
  array_splice($d, $idx, 0, [$sec]);
  echo "seccion especialidad insertada en $idx\n";
} else echo "seccion especialidad: " . ($ya ? 'ya estaba' : 'NO encontrada') . "\n";
eg_save(6, $d);
eg_rm(6, 'Diseño web WordPress y WooCommerce en Calella %sep% enricgomez Studio', 'Estudio de diseño y desarrollo web en Calella (Maresme) desde 2006: webs y tiendas online en WordPress y WooCommerce, y también IA, consultoría y sistemas a medida.', 'diseño web calella');

/* ---------- Servicios (1431) y Web (1433) ---------- */
eg_bak(1431);
$d = json_decode(get_post_meta(1431, '_elementor_data', true), true);
$map = [
  'a203430' => ['paragraph' => 'Cuatro piezas, un estudio'],
  '4eed6d39' => ['title' => 'Diseño web <span>y todo lo que lo rodea.</span>'],
  '6b6da12d' => ['paragraph' => 'Empezamos por la web, que es nuestra especialidad desde 2006, y seguimos con lo que haga falta: inteligencia artificial, consultoría y marketing online, y sistemas a medida. Además, diseño gráfico, fotografía y vídeo, con una sola persona al otro lado.'],
  '4306b536' => ['title' => 'Diseño web y tiendas online.'],
];
$hits = []; eg_apply($d, $map, $hits); eg_report('servicios', $map, $hits); eg_save(1431, $d);
eg_rm(1431, 'Servicios: diseño web, WordPress, IA y consultoría %sep% enricgomez Studio', 'Diseño web y tiendas online en WordPress y WooCommerce, inteligencia artificial para empresas, consultoría y marketing online y sistemas a medida. Desde Calella, desde 2006.');

eg_bak(1433);
$d = json_decode(get_post_meta(1433, '_elementor_data', true), true);
$map = ['958cdc4' => ['paragraph' => '<span></span><b>eg</b>&nbsp;web · diseño y desarrollo web']];
$hits = []; eg_apply($d, $map, $hits); eg_report('web', $map, $hits); eg_save(1433, $d);
eg_rm(1433, 'Diseño web WordPress con cuota mensual %sep% enricgomez Studio', 'Diseño web profesional en WordPress y WooCommerce por una cuota mensual: diseño propio, hosting, dominio, correo, mantenimiento y soporte incluidos. Desde Calella, desde 2006.', 'diseño web cuota mensual,web con cuota mensual,diseño web wordpress');

/* ---------- Páginas nuevas, clonadas de Experto en WordPress (1959) ---------- */
function eg_clone_page($slug, $parent, $title, $map, $rm) {
  $src = 1959;
  $ex = get_posts(['post_type' => 'page', 'name' => $slug, 'post_parent' => $parent, 'post_status' => 'any', 'numberposts' => 1]);
  $pid = $ex ? $ex[0]->ID : wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => $title, 'post_name' => $slug, 'post_parent' => $parent, 'post_author' => get_post_field('post_author', $src)]);
  foreach (['_elementor_edit_mode', '_elementor_template_type', '_elementor_version', '_elementor_pro_version', '_wp_page_template'] as $k) update_post_meta($pid, $k, get_post_meta($src, $k, true));
  $d = json_decode(get_post_meta($src, '_elementor_data', true), true);
  $hits = []; eg_apply($d, $map, $hits); eg_report($slug, $map, $hits);
  eg_save($pid, $d);
  eg_rm($pid, $rm[0], $rm[1], $rm[2]);
  wp_update_post(['ID' => $pid, 'post_excerpt' => $rm[1]]);
  echo "$slug id=$pid " . get_permalink($pid) . "\n";
  return $pid;
}
$H2 = $H;
$tiendas = eg_clone_page('tiendas-online-woocommerce', 1433, 'Tiendas online WooCommerce', [
  '64a6739' => ['title' => 'Tiendas online WooCommerce <span>que venden solas.</span>'],
  '2380bf64' => ['paragraph' => 'Una tienda que tus clientes encuentran, entienden y compran sin dudas, y que tú gestionas sin depender de nadie. Diseño y desarrollo de tiendas online en WooCommerce desde 2006.'],
  '2030591d' => ['text' => 'Pide presupuesto para tu tienda <span>→</span>', 'link' => $CONT],
  '1e7cbb2e' => ['text' => 'Formas de contratar <span>↓</span>'],
  '7f18ecca' => ['paragraph' => 'WooCommerce desde 2006 · tiendas nuevas o ya existentes'],
  '5fc0a2c6' => ['paragraph' => 'Qué incluye'],
  '1b0bf154' => ['title' => 'Todo lo que tu tienda necesita.'],
  '4e54fe88' => ['title' => 'Diseño de tienda y fichas de producto'],
  '62992f5e' => ['paragraph' => 'Una tienda con tu marca, fichas claras y un proceso de compra corto, pensado para que el cliente compre sin dudas.'],
  '407b119' => ['title' => 'Pagos y envíos'],
  '7e5915f8' => ['paragraph' => 'Tarjeta, Bizum, PayPal o transferencia, y tarifas de envío por zona, peso o importe.'],
  '69697add' => ['title' => 'Facturas e impuestos'],
  '6f3db97a' => ['paragraph' => 'Facturas automáticas en cada pedido, impuestos bien configurados y conexión con tu programa de facturación.'],
  '41301740' => ['title' => 'Catálogo y stock'],
  '2cde8dc2' => ['paragraph' => 'Variaciones, stock, catálogos grandes e importaciones, con integraciones con tu programa de gestión o con marketplaces.'],
  '7a2a02a8' => ['title' => 'SEO de producto y velocidad'],
  'a690a56' => ['paragraph' => 'Categorías y fichas preparadas para Google y los asistentes de IA, con caché, CDN e imágenes WebP para que la tienda cargue rápido.'],
  '7db02278' => ['title' => 'Multidioma'],
  '45d7cb4d' => ['paragraph' => 'Tu tienda en varios idiomas, posicionada en cada uno de ellos.'],
  '6ce5a085' => ['paragraph' => 'Formas de contratar'],
  '71e9a9f' => ['paragraph' => '149 €/mes'],
  '6eb46dd2' => ['title' => 'Plan Tienda online'],
  '43312bbc' => ['paragraph' => 'Tu tienda WooCommerce con diseño, hosting, mantenimiento y soporte incluidos en una cuota mensual.'],
  'd26b3' => ['text' => 'Ver el plan <span>→</span>', 'link' => $H . 'servicios/web/'],
  '3ff6f58e' => ['paragraph' => 'Presupuesto cerrado'],
  '71643647' => ['title' => 'Tienda a medida'],
  '61ec16fd' => ['paragraph' => 'Para catálogos grandes, integraciones o necesidades especiales, con precio y plazo por escrito antes de empezar.'],
  '3e7f7d7f' => ['text' => 'Pedir presupuesto <span>→</span>', 'link' => $CONT],
  'd84c50b' => ['paragraph' => 'Ya tienes tienda'],
  '36bc4133' => ['title' => 'Mantenimiento y rescates'],
  '4271e9d9' => ['paragraph' => '¿Tu WooCommerce va lento, falla o necesita una migración? Nos ponemos con él.'],
  '656b79ce' => ['text' => 'Experto en WordPress <span>→</span>', 'link' => $H . 'servicios/web/experto-wordpress/'],
  '4ac5d057' => ['paragraph' => '¿WooCommerce o Shopify?'],
  'a2876a7' => ['paragraph' => 'Para la mayoría de negocios, WooCommerce: la tienda es tuya, no pagas comisiones por venta a la plataforma y puedes crecer sin cambiar de sistema.'],
  '3208c984' => ['paragraph' => '¿Podré gestionar yo los productos?'],
  '38a7bacf' => ['paragraph' => 'Sí. Te enseñamos a dar de alta productos, cambiar precios y stock y gestionar pedidos tú mismo.'],
  '1498b79' => ['paragraph' => '¿Cuánto se tarda en tener la tienda?'],
  '49fcef45' => ['paragraph' => 'Entre seis y diez semanas desde que tenemos los productos, las fotos y los textos.'],
  '60ad73ff' => ['paragraph' => '¿Podéis migrar mi tienda actual?'],
  '58797838' => ['paragraph' => 'Sí. Pasamos productos, clientes y pedidos, y redirigimos las direcciones antiguas para conservar el posicionamiento.'],
], ['Tiendas online WooCommerce: diseño y desarrollo %sep% enricgomez Studio', 'Diseñamos y desarrollamos tiendas online en WooCommerce desde 2006: catálogo, pagos, envíos, facturas, stock y SEO. Plan Tienda por 149 €/mes o proyecto a medida.', 'tienda online woocommerce,desarrollo woocommerce,diseño tienda online']);

$calella = eg_clone_page('diseno-web-calella', 0, 'Diseño web en Calella', [
  '64a6739' => ['title' => 'Diseño web en Calella, <span>para todo el Maresme.</span>'],
  '2380bf64' => ['paragraph' => 'Somos un estudio de diseño web en Calella desde 2006. Diseñamos y desarrollamos webs y tiendas online en WordPress y WooCommerce para empresas, hoteles y entidades del Maresme, con reuniones en el estudio cuando las necesitas.'],
  '2030591d' => ['text' => 'Ven a conocernos <span>→</span>', 'link' => $CONT],
  '1e7cbb2e' => ['text' => 'Cómo trabajamos <span>↓</span>'],
  '7f18ecca' => ['paragraph' => 'C/ Costa i Fornaguera, 27 · 08370 Calella (Barcelona)'],
  '5fc0a2c6' => ['paragraph' => 'Por qué un estudio cerca'],
  '1b0bf154' => ['title' => 'Diseño web con alguien a quien puedes ver.'],
  '4e54fe88' => ['title' => 'Estudio en Calella'],
  '62992f5e' => ['paragraph' => 'Nos ves en persona en C/ Costa i Fornaguera, 27. Primero hablamos de tu negocio y después de la web.'],
  '407b119' => ['title' => '20 años en el Maresme'],
  '7e5915f8' => ['paragraph' => 'Webs para hoteles, campings, taxis, ocio y empresas de la comarca: Hotel Miami, Hotel Neptuno, Camping El Far, Taxi Calella, Taxi Santa Susanna o Bowling-Golf Calella.'],
  '69697add' => ['title' => 'Turismo como especialidad'],
  '6f3db97a' => ['paragraph' => 'Webs que venden directo, con motor de reservas, guías del destino y contenidos que atraen visitas todo el año.'],
  '41301740' => ['title' => 'Multidioma de verdad'],
  '2cde8dc2' => ['paragraph' => 'Webs en castellano, catalán, inglés, francés o alemán, posicionadas en cada idioma.'],
  '7a2a02a8' => ['title' => 'WordPress y WooCommerce'],
  'a690a56' => ['paragraph' => 'Webs y tiendas que puedes gestionar tú, con hosting, mantenimiento y soporte desde aquí.'],
  '7db02278' => ['title' => 'SEO local'],
  '45d7cb4d' => ['paragraph' => 'Ficha de Google, datos estructurados y contenidos locales para aparecer cuando te buscan en Calella, Pineda, Malgrat, Santa Susanna o Mataró.'],
  '6ce5a085' => ['paragraph' => 'Cómo trabajamos'],
  '71e9a9f' => ['paragraph' => 'Desde 49 €/mes'],
  '6eb46dd2' => ['title' => 'Web con cuota mensual'],
  '43312bbc' => ['paragraph' => 'Diseño, hosting, dominio, correo, mantenimiento y soporte en una cuota fija.'],
  'd26b3' => ['text' => 'Ver planes <span>→</span>', 'link' => $H . 'servicios/web/'],
  '3ff6f58e' => ['paragraph' => 'Desde 149 €/mes'],
  '71643647' => ['title' => 'Tienda online'],
  '61ec16fd' => ['paragraph' => 'Tu tienda WooCommerce con todo incluido, o un proyecto a medida con presupuesto cerrado.'],
  '3e7f7d7f' => ['text' => 'Ver tiendas online <span>→</span>', 'link' => $H . 'servicios/web/tiendas-online-woocommerce/'],
  'd84c50b' => ['paragraph' => 'Desde 29 €/mes'],
  '36bc4133' => ['title' => 'Ya tienes web'],
  '4271e9d9' => ['paragraph' => 'Mantenimiento, rescates y migraciones de webs WordPress que ya existen.'],
  '656b79ce' => ['text' => 'Ver mantenimiento <span>→</span>', 'link' => $H . 'servicios/web/mantenimiento/'],
  '4ac5d057' => ['paragraph' => '¿Puedo ir al estudio?'],
  'a2876a7' => ['paragraph' => 'Sí, con cita previa. Estamos en C/ Costa i Fornaguera, 27, en Calella. También trabajamos en remoto con clientes de toda España.'],
  '3208c984' => ['paragraph' => '¿Trabajáis solo en el Maresme?'],
  '38a7bacf' => ['paragraph' => 'No. El estudio está en Calella, pero tenemos clientes en Barcelona, en el resto de España y fuera.'],
  '1498b79' => ['paragraph' => '¿Hacéis webs para hoteles y turismo?'],
  '49fcef45' => ['paragraph' => 'Sí, es una de nuestras especialidades: webs que venden directo, multidioma y con motor de reservas.'],
  '60ad73ff' => ['paragraph' => '¿Cuánto cuesta una web?'],
  '58797838' => ['paragraph' => 'Desde 49 €/mes con todo incluido, o con presupuesto cerrado si es un proyecto a medida.'],
], ['Diseño web en Calella y el Maresme %sep% enricgomez Studio', 'Estudio de diseño web en Calella desde 2006: webs y tiendas online en WordPress y WooCommerce para empresas, hoteles y entidades del Maresme. Ven a conocernos.', 'diseño web calella,diseño web maresme,páginas web calella']);

/* ---------- SEO/GEO ---------- */
$u = (int) get_post_field('post_author', 6);
wp_update_user(['ID' => $u, 'display_name' => 'Enric Gómez', 'first_name' => 'Enric', 'last_name' => 'Gómez', 'description' => 'Diseñador y desarrollador web. Estudió Multimedia en el CITM (UPC) y desde 2006 dirige enricgomez Studio en Calella: diseño web, WordPress, WooCommerce, IA y sistemas a medida.']);
$t = get_option('rank-math-options-titles');
if (!get_option('_eg_rm_titles_bak_20261113')) add_option('_eg_rm_titles_bak_20261113', $t, '', false);
$t['pt_page_default_rich_snippet'] = 'off';
$t['knowledgegraph_type'] = 'company';
$t['knowledgegraph_name'] = 'enricgomez Studio';
update_option('rank-math-options-titles', $t);
$g = get_option('rank-math-options-general');
if (!get_option('_eg_llms_bak_20261113')) add_option('_eg_llms_bak_20261113', $g['llms_extra_content'] ?? '', '', false);
$intro = "enricgomez Studio es un estudio de diseño y desarrollo web en Calella (Barcelona) especializado en WordPress y WooCommerce desde 2006. Diseña webs y tiendas online a medida o con cuota mensual, y también ofrece inteligencia artificial, consultoría digital y sistemas a medida. Fundador: Enric Gómez.\n\n- Diseño web con cuota mensual: {$H}servicios/web/\n- Tiendas online WooCommerce: {$H}servicios/web/tiendas-online-woocommerce/\n- Experto en WordPress: {$H}servicios/web/experto-wordpress/\n- Diseño web en Calella y el Maresme: {$H}diseno-web-calella/\n";
$old = (string) ($g['llms_extra_content'] ?? '');
if (strpos($old, 'especializado en WordPress y WooCommerce') === false) $g['llms_extra_content'] = $intro . ($old ? "\n" . $old : '');
update_option('rank-math-options-general', $g);
echo "seo ok\n";
\Elementor\Plugin::$instance->files_manager->clear_cache();
