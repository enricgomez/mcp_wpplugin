<?php
update_option( 'category_base', 'categoria' ); 
$pp = get_post( get_option( 'page_for_posts' ) ); echo 'blog page: ', $pp->ID, ' ', $pp->post_name, ' ', $pp->post_status, "\n";
foreach ( get_posts( [ 'post_type' => 'any', 'name' => 'blog', 'post_status' => 'any', 'numberposts' => 5 ] ) as $x ) echo 'slug blog: ', $x->ID, ' ', $x->post_type, "\n";
flush_rewrite_rules( false );
global $wp_rewrite; $r = get_option( 'rewrite_rules' ); echo 'regla blog/?$: ', $r['blog/?$'] ?? '-', "\n";
$h = wp_remote_retrieve_body( wp_remote_get( home_url( '/blog/?nc=' . time() ), [ 'sslverify' => false ] ) ); preg_match( '/<h1[^>]*>(.*?)<\/h1>/s', $h, $m ); echo 'blog h1: ', strip_tags( $m[1] ?? '-' ), ' tarjetas=', substr_count( $h, 'eg-bl-card-titulo' ), "\n";
echo get_term_link( get_term_by( 'slug', 'ia', 'category' ) ), "\n";
