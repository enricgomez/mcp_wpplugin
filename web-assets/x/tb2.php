<?php
foreach ( [ "/tmp/eg-ba.json" ] as $CFGF ) { include "/tmp/eg-tpl.php"; }
$t = get_term_by("slug","ia","category"); echo get_term_link($t), "\n", get_option("category_base"), " ", get_option("permalink_structure"), "\n";
$h = wp_remote_retrieve_body( wp_remote_get( home_url("/blog/ha-muerto-el-seo-con-la-ia/?nc=".time()), ["sslverify"=>false] ) ); preg_match("/<h1[^>]*>(.*?)<\/h1>/s",$h,$m); echo "post h1: ", strip_tags($m[1]??"-"), " prosa=", substr_count($h,"eg-bl-prosa"), "\n";
