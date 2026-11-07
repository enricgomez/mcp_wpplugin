<?php
copy('/tmp/eg-slider-dyn.json','/tmp/eg-dyn.json'); include '/tmp/eg-dynfix.php';
$h = wp_remote_retrieve_body(wp_remote_get(home_url('/?nc='.time()), ['sslverify'=>false,'timeout'=>30]));
preg_match_all('/class="[^"]*eg-caso[^"]*"/', $h, $m); echo 'casos: ', count($m[0]), "\n";
preg_match_all('/<h2[^>]*>(.*?)<\/h2>/s', $h, $m); echo implode(" | ", array_map('strip_tags', $m[1])), "\n";
echo (strpos($h,'{{')!==false?'QUEDAN MARCADORES':'sin marcadores'), "\n";
