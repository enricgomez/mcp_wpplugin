<?php
// Descarga candidatos de logo de webs de clientes a una carpeta temporal pública (10/11/2026).
$out = '/var/www/vhosts/enricgomez.com/httpdocs/wp-content/eg-logos-tmp';
@mkdir($out, 0755, true);
$doms = ['hotelmiamicalella.com','hotelneptuno.com','shamaspa.com','campingelfar.com','igcat.org','viajestauro.com','sonovet.net','miriamtirado.com','mcapitalp.com','oidfa-spain.org','byko.es','nilacakes.com','bowlinggolfcalella.com','bowling-golfcalella.com','taxi-calella.com','taxi-santasusanna.com'];
function get($u){ $c=curl_init($u); curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>1,CURLOPT_FOLLOWLOCATION=>1,CURLOPT_TIMEOUT=>20,CURLOPT_SSL_VERIFYPEER=>0,CURLOPT_USERAGENT=>'Mozilla/5.0 (Macintosh) AppleWebKit/537.36 Chrome/130 Safari/537.36']); $b=curl_exec($c); $i=curl_getinfo($c); curl_close($c); return [$b,$i['url'],$i['http_code'],$i['content_type']??'']; }
$man = [];
foreach ($doms as $d) {
  [$h,$final,$code] = get("https://www.$d/");
  if ($code != 200 || !$h) [$h,$final,$code] = get("https://$d/");
  if ($code != 200 || !$h) { $man[$d] = "ERR $code"; continue; }
  $c = [];
  preg_match_all('/<img\b[^>]*>/i', $h, $m);
  foreach ($m[0] as $t) {
    if (!preg_match('/logo/i', $t)) continue;
    foreach (['data-lazy-src','data-src','src'] as $a) if (preg_match('/\s'.$a.'=["\']([^"\']+)/i', $t, $s) && strpos($s[1],'data:')!==0) { $c[] = $s[1]; break; }
    if (preg_match('/srcset=["\']([^"\']+)/i', $t, $s)) { $p = array_map('trim', explode(',', $s[1])); $c[] = preg_split('/\s+/', end($p))[0]; }
  }
  if (preg_match_all('/<link[^>]+rel=["\'](?:apple-touch-icon|icon)["\'][^>]*>/i', $h, $m2)) foreach ($m2[0] as $t) if (preg_match('/href=["\']([^"\']+)/i',$t,$s)) $c[] = $s[1];
  // svg inline con logo
  if (preg_match('/<svg[^>]*(?:logo)[^>]*>.*?<\/svg>/is', $h, $sv)) { file_put_contents("$out/$d-inline.svg", $sv[0]); }
  $c = array_values(array_unique($c)); $saved = [];
  foreach (array_slice($c, 0, 6) as $k => $u) {
    $abs = preg_match('#^https?://#', $u) ? $u : (strpos($u,'//')===0 ? "https:$u" : rtrim(preg_replace('#^(https?://[^/]+).*#','$1',$final),'/').'/'.ltrim($u,'/'));
    [$b,,$cc,$ct] = get($abs);
    if ($cc != 200 || !$b) continue;
    $ext = preg_match('/svg/',$ct) ? 'svg' : (preg_match('/png/',$ct)?'png':(preg_match('/webp/',$ct)?'webp':(preg_match('/ico/',$ct)?'ico':'jpg')));
    $f = "$d-$k.$ext"; file_put_contents("$out/$f", $b); $saved[] = "$f <- $abs";
  }
  $man[$d] = $saved;
}
file_put_contents("$out/manifest.json", json_encode($man, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode($man, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
