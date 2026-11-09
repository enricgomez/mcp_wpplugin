<?php
// Fecha de cada proyecto = creación de la web (primer contenido publicado en su WordPress). 09/11/2026
$F = [
'hotel-sorra-daurada-splash'=>'2022-03-01','summer-hotel-calella'=>'2024-02-21','oktoberfest-calella'=>'2024-05-13','taxi-calella'=>'2026-06-22',
'nila-cakes'=>'2025-06-27','koenigsegg-advanced-manufacturing'=>'2020-03-16','mallorca-peregrina'=>'2024-06-19','oidfa-spain'=>'2025-03-12',
'sonovet'=>'2022-01-13','pizzeria-don-carlo'=>'2019-11-12','verofood'=>'2025-03-26','adorna'=>'2024-02-29','bolsas-termicas'=>'2015-03-10',
'maimar-cosmetica-natural'=>'2015-05-08','dance-socks-bcn'=>'2015-09-14','difusio'=>'2018-03-06','fet-a-mida'=>'2016-11-25','freesowl'=>'2021-02-01',
'interlama'=>'2021-07-29','navarro-aubanell'=>'2014-03-18','sediferent-uniformes'=>'2017-02-24','vivers-salicru'=>'2019-12-13','apintesa'=>'2014-11-07',
'byko'=>'2018-11-26','construmat-pinalba'=>'2015-02-10','electrosol'=>'2016-06-14','mond-marketing'=>'2024-12-23','techos-tensados'=>'2019-08-15',
'teatro-de-empresa'=>'2020-05-05','orive-gestors'=>'2015-12-04','clinica-dental-rais'=>'2025-06-25','dentipol'=>'2024-04-03','andres-diez'=>'2025-05-02',
'eva-espallargas'=>'2016-02-03','jrc-abogada'=>'2015-12-02','adrian-sanchez-garrido'=>'2019-02-07','nuria-grima'=>'2026-11-03','dblanc'=>'2015-05-27',
'acat'=>'2025-11-14','adf-alt-maresme'=>'2020-04-15','aula-extensio-universitaria-blanes'=>'2024-01-11','aula-extensio-universitaria-calella'=>'2024-01-11',
'biblioteca-cardedeu'=>'2014-05-06','cf-calella'=>'2021-10-18','joventut-artistica'=>'2021-02-23','marxa-la-llopa'=>'2021-09-14','patronat-teatre-premia'=>'2021-02-23',
'vincles'=>'2024-12-04','estimem-calella'=>'2022-12-07','solid-seeds-photo-contest'=>'2025-05-12','construccions-palet'=>'2020-01-02','coocoolab'=>'2021-01-19',
'isiegas'=>'2018-06-14','neptuno-hotel-spa'=>'2024-02-07','miriam-tirado'=>'2020-07-16','desire-vips'=>'2015-05-25','dulce-lucca'=>'2015-03-10',
'igcat'=>'2015-12-30','africa-select-equity-fund'=>'2022-10-17','ags-analisis-gerencial'=>'2023-12-12','cbmdisseny'=>'2015-07-24','shama-spa'=>'2020-03-20',
];
$n = 0;
foreach ($F as $slug => $d) {
  $p = get_page_by_path($slug, OBJECT, 'proyecto'); if (!$p) { echo "FALTA $slug\n"; continue; }
  $dt = "$d 10:00:00";
  wp_update_post(['ID'=>$p->ID,'post_date'=>$dt,'post_date_gmt'=>get_gmt_from_date($dt)]);
  update_post_meta($p->ID, 'anio', substr($d,0,4)); $n++;
}
echo "fechados $n\n";
wp_cache_flush(); if (function_exists('rocket_clean_domain')) rocket_clean_domain();
foreach (get_posts(['post_type'=>'proyecto','numberposts'=>12,'orderby'=>'date','order'=>'DESC']) as $p) echo substr($p->post_date,0,10),' ',$p->post_name,"\n";
