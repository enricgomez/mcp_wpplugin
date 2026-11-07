<?php
$L = json_decode( file_get_contents( '/tmp/eg-legal.json' ), true ); $conds = [];
foreach ( $L as $pg ) {
	$p = get_page_by_path( $pg['slug'] );
	$arr = [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $pg['title'], 'post_name' => $pg['slug'], 'post_content' => $pg['html'], 'post_author' => 1 ];
	if ( $p ) { $arr['ID'] = $p->ID; $id = wp_update_post( $arr ); } else $id = wp_insert_post( $arr );
	update_post_meta( $id, 'rank_math_title', $pg['seo'][0] ); update_post_meta( $id, 'rank_math_description', $pg['seo'][1] );
	$conds[] = 'include/singular/page/' . $id; echo "$id /{$pg['slug']}/\n";
}
$c = json_decode( file_get_contents( '/tmp/eg-lt.json' ), true ); $c['conditions'] = $conds; file_put_contents( '/tmp/eg-lt2.json', json_encode( $c ) );
$CFGF = '/tmp/eg-lt2.json'; include '/tmp/eg-tpl.php';
$tid = $id; // plantilla
$ex = get_posts( [ 'post_type' => 'elementor_library', 'title' => 'Legal', 'numberposts' => 1, 'post_status' => 'any' ] );
if ( $ex ) { update_post_meta( $ex[0]->ID, '_elementor_conditions', $conds ); }
$CFGF = '/tmp/eg-l404.json'; include '/tmp/eg-tpl.php';
if ( class_exists( '\ElementorPro\Plugin' ) ) { \ElementorPro\Plugin::instance()->modules_manager->get_modules( 'theme-builder' )->get_conditions_manager()->get_cache()->regenerate(); echo "cache condiciones ok\n"; }
\Elementor\Plugin::$instance->files_manager->clear_cache();
foreach ( [ '/aviso-legal/', '/privacidad/', '/cookies/', '/condiciones/', '/no-existe-xyz/' ] as $u ) { $r = wp_remote_get( home_url( $u . '?nc=' . time() ), [ 'sslverify' => false, 'timeout' => 30 ] ); $h = wp_remote_retrieve_body( $r );
	preg_match( '/<h1[^>]*>(.*?)<\/h1>/s', $h, $m ); echo $u, ' ', wp_remote_retrieve_response_code( $r ), ' h1=', trim( strip_tags( $m[1] ?? 'NINGUNO' ) ), ' h2=', substr_count( $h, '<h2' ), "\n"; }
