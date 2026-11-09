<?php
// php cfg.php old_config new_config  -> reescribe new_config con la BD del antiguo y ajustes de producción
$old=file_get_contents($argv[1]); $new=file_get_contents($argv[2]);
foreach(['DB_NAME','DB_USER','DB_PASSWORD','DB_HOST'] as $k){
  preg_match("/define\(\s*['\"]{$k}['\"]\s*,\s*(['\"])(.*?)\\1\s*\)/",$old,$m); if(!$m) die("falta $k\n");
  $val=$m[2];
  $new=preg_replace_callback("/define\(\s*['\"]{$k}['\"]\s*,\s*(['\"])(.*?)\\1\s*\)/",fn($x)=>"define( '{$k}', ".var_export(stripcslashes($val),true)." )",$new,1,$c); if(!$c) die("no $k en nuevo\n");
}
$new=preg_replace("/define\(\s*'WP_REDIS_PREFIX'.*?\);/","define( 'WP_REDIS_PREFIX', 'enricgomez:w2026:' );",$new);
$salt=bin2hex(random_bytes(24));
$new=preg_replace("/define\(\s*'WP_CACHE_KEY_SALT'.*?\);/","define( 'WP_CACHE_KEY_SALT', '$salt' );",$new);
$new=preg_replace("/^.*define\(\s*'(WP_HOME|WP_SITEURL)'.*\n/m","",$new);
$new=str_replace("/* That's all, stop editing!","define( 'WP_HOME', 'https://www.enricgomez.com' );\ndefine( 'WP_SITEURL', 'https://www.enricgomez.com' );\n\n/* That's all, stop editing!",$new,$c2);
if(!$c2) die("sin marcador stop editing\n");
file_put_contents($argv[2],$new); echo "cfg ok\n";
