<?php
$rep = [
 'Graduado en Multimedia (UPC), 20 años haciendo webs para empresas de todo tipo.' => 'Un estudio pequeño, 20 años haciendo webs para empresas de todo tipo.',
 'Graduado en Multimedia (UPC), <span>20 años</span> haciendo webs para empresas de todo tipo.' => '<span>20 años</span> haciendo webs para empresas de todo tipo.',
 'Soy Enric Gómez, graduado en Multimedia (UPC). Empecé a hacer webs en 2006 y desde entonces' => 'Soy Enric Gómez. Estudié Multimedia en el CITM, la facultad de la Universitat Politècnica de Catalunya (UPC), empecé a hacer webs en 2006 y desde entonces',
 'Graduado en Multimedia (UPC) con veinte años de experiencia en empresas de todo tipo.' => 'Veinte años de experiencia con empresas de todo tipo.',
 'Graduado en Multimedia (UPC) y fundador de enricgomez Studio.' => 'Fundador de enricgomez Studio, formado en Multimedia en el CITM-UPC.',
];
$walk = function (&$v) use (&$walk, $rep, &$n) {
  if (is_array($v)) { foreach ($v as &$x) $walk($x); return; }
  if (is_string($v)) { $o = $v; $v = strtr($v, $rep); if ($o !== $v) $n++; }
};
foreach ([6, 1443, 1325] as $id) {
  $raw = get_post_meta($id, '_elementor_data', true);
  if (!get_post_meta($id, '_eg_edata_bak_20261110', true)) add_post_meta($id, '_eg_edata_bak_20261110', wp_slash($raw));
  $d = json_decode($raw, true); $n = 0; $walk($d);
  if ($n) update_post_meta($id, '_elementor_data', wp_slash(wp_json_encode($d)));
  echo "$id cambios=$n\n";
}
$desc = 'Estudio de Enric Gómez (Multimedia, CITM-UPC) en Calella. Desde 2006 haciendo webs multidioma para empresas de todo tipo, y hoy también IA, consultoría y sistemas a medida.';
update_post_meta(1443, '_eg_rm_bak_20261110', [get_post_meta(1443,'rank_math_title',true), get_post_meta(1443,'rank_math_description',true)]);
update_post_meta(1443, 'rank_math_title', 'El estudio: Enric Gómez y 20 años haciendo webs | enricgomez Studio');
update_post_meta(1443, 'rank_math_description', $desc);
wp_update_post(['ID' => 1443, 'post_excerpt' => $desc]);
$o = get_option('rank-math-options-general');
$o['llms_extra_content'] = str_replace('graduado en Multimedia (UPC)', 'graduado en Multimedia por el CITM (Universitat Politècnica de Catalunya)', $o['llms_extra_content']);
update_option('rank-math-options-general', $o);
\Elementor\Plugin::$instance->files_manager->clear_cache();
rocket_clean_domain(); wp_cache_flush();
echo "ok\n";
