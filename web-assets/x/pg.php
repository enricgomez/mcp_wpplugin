<?php
do_action( 'rest_api_init' );
$cfg = json_decode( file_get_contents( '/tmp/eg-pg.json' ), true ); $only = getenv( 'EGONLY' );
$run = function ( $n, $in ) { $r = wp_get_ability( $n )->execute( $in ); return is_wp_error( $r ) ? [ 'error' => $r->get_error_code(), 'msg' => $r->get_error_message() ] : $r; };
$have = wp_json_encode( $run( 'elementor/global-classes-resource', [] ) );
$ops = array_map( function ( $o ) use ( $have ) { if ( false !== strpos( $have, '"' . $o['label'] . '"' ) || false !== strpos( $have, '\\"' . $o['label'] . '\\"' ) ) { $o['action'] = 'update'; $o['mode'] = 'replace'; } return $o; }, $cfg['classes'] );
$r = $run( 'elementor/manage-classes', [ 'operations' => $ops ] ); echo 'clases: ', substr( json_encode( $r ), 0, 200 ), "\n";
$ids = [];
foreach ( $cfg['pages'] as $pg ) {
	$path = ( $pg['parent'] ? $pg['parent'] . '/' : '' ) . $pg['slug']; if ( $only && $only !== $pg['slug'] ) continue;
	$p = get_page_by_path( $path );
	if ( $p ) $id = $p->ID; else {
		$parent = $pg['parent'] ? get_page_by_path( $pg['parent'] ) : null;
		$id = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $pg['title'], 'post_name' => $pg['slug'], 'post_parent' => $parent ? $parent->ID : 0, 'post_author' => get_user_by( 'login', 'enric' )->ID ] );
	}
	foreach ( [ '_wp_page_template', '_elementor_edit_mode', '_elementor_template_type', '_elementor_version', '_elementor_pro_version', '_elementor_page_settings' ] as $k ) { $v = get_post_meta( 6, $k, true ); if ( '' !== $v ) update_post_meta( $id, $k, $v ); }
	$err = '';
	foreach ( $pg['reqs'] as $i => $in ) { $in['post_id'] = $id; $r = $run( 'elementor/build-composition', $in ); if ( empty( $r['success'] ) ) { $err .= " [req $i: " . substr( json_encode( $r, JSON_UNESCAPED_UNICODE ), 0, 400 ) . ']'; break; } }
	$run( 'elementor/publish-document', [ 'post_id' => $id ] );
	update_post_meta( $id, 'rank_math_title', $pg['seo'][0] ); update_post_meta( $id, 'rank_math_description', $pg['seo'][1] );
	$ids[ $path ] = $id; echo "$id /$path/", $err ?: ' ok', "\n";
}
\Elementor\Plugin::$instance->files_manager->clear_cache(); wp_cache_flush();
foreach ( $ids as $path => $id ) { $h = wp_remote_retrieve_body( wp_remote_get( home_url( "/$path/?nc=" . time() ), [ 'sslverify' => false, 'timeout' => 30 ] ) ); preg_match( '/<h1[^>]*>(.*?)<\/h1>/s', $h, $m ); echo "/$path/ h1=", trim( strip_tags( $m[1] ?? 'NINGUNO' ) ), ' len=', strlen( $h ), ' faq=', substr_count( $h, '<details' ), ' form=', substr_count( $h, '<form' ), "\n"; }
