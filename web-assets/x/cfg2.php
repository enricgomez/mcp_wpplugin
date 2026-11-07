<?php
update_post_meta( 6, 'rank_math_title', 'enricgomez Studio %sep% Webs, IA, consultoría y sistemas en Calella' );
update_post_meta( 6, 'rank_math_description', 'Estudio de tecnología en Calella desde 2006: webs y tiendas WordPress, inteligencia artificial aplicada, consultoría digital y sistemas a medida para empresas, hoteles y entidades.' );
update_post_meta( 6, 'rank_math_focus_keyword', 'diseño web calella' );
flush_rewrite_rules( false ); wp_cache_flush();
$r = wp_remote_get( home_url( '/sitemap_index.xml' ), [ 'sslverify' => false ] ); echo 'sitemap ', wp_remote_retrieve_response_code( $r ), ' ', substr_count( wp_remote_retrieve_body( $r ), '<sitemap>' ), "\n";
preg_match_all( '/<loc>([^<]+)/', wp_remote_retrieve_body( $r ), $m ); echo implode( "\n", $m[1] ), "\n";
$h = wp_remote_retrieve_body( wp_remote_get( home_url( '/?nc=' . time() ), [ 'sslverify' => false ] ) ); preg_match( '/<title>(.*?)<\/title>/s', $h, $t ); echo 'home: ', $t[1], "\n";
