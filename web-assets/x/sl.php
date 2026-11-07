<?php
do_action('rest_api_init');
$req = json_decode(file_get_contents('/tmp/eg-slider.json'), true); $pid = $req['input']['post_id'];
$d = json_decode(get_post_meta($pid,'_elementor_data',true), true); $pista=null;
$f = function($els) use (&$f, &$pista) { foreach ($els as $e) { if (($e['editor_settings']['title'] ?? '') === 'Proyectos pista') $pista = $e['id']; if (!empty($e['elements'])) $f($e['elements']); } }; $f($d);
echo "pista=$pista\n"; if (!$pista) return;
$req['input']['parent_id'] = $pista;
$r = wp_get_ability($req['ability'])->execute($req['input']); echo substr(json_encode(is_wp_error($r)?[$r->get_error_message(),$r->get_error_data()]:$r, JSON_UNESCAPED_UNICODE),0,600),"\n";
copy('/tmp/eg-slider-dyn.json','/tmp/eg-dyn.json'); include '/tmp/eg-dynfix.php';
$r = wp_get_ability('elementor/publish-document')->execute(['post_id'=>$pid]); echo is_wp_error($r)?$r->get_error_message():'publicado',"\n";
// orden de fechas: Bowling, Miami, Taxi
foreach (['bowling-golf-calella-sistema-pistas'=>'2026-11-05 10:00:00','hotel-miami-calella-web'=>'2026-10-20 10:00:00','taxi-santa-susanna-blog'=>'2026-10-01 10:00:00'] as $sl=>$dt) { $p=get_page_by_path($sl,OBJECT,'proyecto'); wp_update_post(['ID'=>$p->ID,'post_date'=>$dt,'post_date_gmt'=>get_gmt_from_date($dt)]); }
\Elementor\Plugin::$instance->files_manager->clear_cache(); echo "ok\n";
