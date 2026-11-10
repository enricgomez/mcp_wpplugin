<?php
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
$src = '/tmp/eg-hero-estudio.jpg';
$ex = get_posts(['post_type'=>'attachment','name'=>'estudio-enricgomez-calella-portada','posts_per_page'=>1,'post_status'=>'any']);
if ($ex) { $aid = $ex[0]->ID; } else {
  $ed = wp_get_image_editor($src); $ed->set_quality(80);
  $up = wp_upload_dir(); $dest = $up['path'] . '/estudio-enricgomez-calella-portada.jpg'; $ed->save($dest, 'image/jpeg');
  $aid = wp_insert_attachment(['post_mime_type'=>'image/jpeg','post_title'=>'Estudio de enricgomez Studio en Calella','post_name'=>'estudio-enricgomez-calella-portada','post_status'=>'inherit'], $dest);
  wp_update_attachment_metadata($aid, wp_generate_attachment_metadata($aid, $dest));
  update_post_meta($aid, '_wp_attachment_image_alt', 'Estudio de enricgomez Studio en Calella con dos puestos de trabajo');
}
echo "attachment $aid\n";
$raw = get_post_meta(6, '_elementor_data', true);
if (!get_post_meta(6, '_eg_edata_bak_hero_20261110', true)) add_post_meta(6, '_eg_edata_bak_hero_20261110', wp_slash($raw));
$d = json_decode($raw, true); $done = 0;
$walk = function (&$els) use (&$walk, $aid, &$done) {
  foreach ($els as &$e) {
    if (($e['id'] ?? '') === '1a54e9c9') {
      echo 'antes: ' . json_encode($e['settings']['image'] ?? null) . "\n";
      $img = &$e['settings']['image']['value']['src']['value'];
      $img['id'] = ['$$type'=>'image-attachment-id','value'=>$aid];
      if (isset($img['url'])) $img['url'] = null;
      $done++;
    }
    if (!empty($e['elements'])) $walk($e['elements']);
  }
};
$walk($d);
if ($done) update_post_meta(6, '_elementor_data', wp_slash(wp_json_encode($d)));
echo "cambiado=$done\n";
\Elementor\Plugin::$instance->files_manager->clear_cache();
rocket_clean_domain(); wp_cache_flush();
