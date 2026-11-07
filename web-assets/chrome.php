<?php
// Crea logo (media), clases gancho, plantillas Cabecera/Pie del Theme Builder y las publica.
do_action( 'rest_api_init' );
$cfg = json_decode( file_get_contents( $args[0] ), true );
$run = function ( $n, $in ) { $r = wp_get_ability( $n )->execute( $in ); return is_wp_error( $r ) ? [ 'error' => $r->get_error_code(), 'msg' => $r->get_error_message(), 'data' => $r->get_error_data() ] : $r; };
$o = [];
// 1. logo del tema como medio (svg)
$q = get_posts( [ 'post_type' => 'attachment', 'name' => 'eg-logo-cabecera', 'numberposts' => 1, 'post_status' => 'any' ] );
if ( $q ) { $logo = $q[0]->ID; } else {
	$up = wp_upload_dir( '2026/11' ); $dst = $up['path'] . '/eg-logo-cabecera.svg';
	copy( get_stylesheet_directory() . '/logo.svg', $dst );
	$logo = wp_insert_attachment( [ 'post_title' => 'eg-logo-cabecera', 'post_name' => 'eg-logo-cabecera', 'post_mime_type' => 'image/svg+xml', 'post_status' => 'inherit' ], $dst );
	update_post_meta( $logo, '_wp_attachment_image_alt', 'enricgomez Studio' );
}
$o['logo'] = $logo;
// 2. clases (las existentes fallan sin romper nada)
$o['classes'] = [];
$have = wp_json_encode( $run( 'elementor/global-classes-resource', [] ) );
$new = array_values( array_filter( $cfg['classes'], fn( $c ) => false === strpos( $have, '"' . $c['label'] . '"' ) && false === strpos( $have, '\\"' . $c['label'] . '\\"' ) ) );
foreach ( array_chunk( $new, 40 ) as $ch ) { $r = $run( 'elementor/manage-classes', [ 'operations' => $ch ] ); $o['classes'][] = substr( json_encode( $r ), 0, 300 ); }
// 3. plantillas
foreach ( [ 'header' => 'Cabecera', 'footer' => 'Pie' ] as $t => $title ) {
	$ex = get_posts( [ 'post_type' => 'elementor_library', 'title' => $title, 'numberposts' => 1, 'post_status' => 'any', 'meta_key' => '_elementor_template_type', 'meta_value' => $t ] );
	if ( $ex ) { $id = $ex[0]->ID; } else {
		$r = $run( 'elementor/manage-site-parts', [ 'operations' => [ [ 'action' => 'create', 'type' => $t, 'title' => $title, 'conditions' => [ 'include/general' ] ] ] ] );
		$o[ "create_$t" ] = $r;
		$id = $r['results'][0]['post_id'] ?? $r['results'][0]['id'] ?? $r[0]['post_id'] ?? null;
		if ( ! $id ) { $ex = get_posts( [ 'post_type' => 'elementor_library', 'title' => $title, 'numberposts' => 1, 'post_status' => 'any' ] ); $id = $ex ? $ex[0]->ID : null; }
	}
	$o[ $t ] = $id;
	if ( ! $id ) continue;
	$in = $cfg[ $t ]['input']; $in['post_id'] = (int) $id;
	$in = json_decode( str_replace( '{"id":999}', '{"id":' . $logo . '}', json_encode( $in ) ), true );
	$o[ "build_$t" ] = substr( json_encode( $run( 'elementor/build-composition', $in ) ), 0, 700 );
	$o[ "pub_$t" ] = substr( json_encode( $run( 'elementor/publish-document', [ 'post_id' => (int) $id ] ) ), 0, 300 );
}
$o['parts'] = $run( 'elementor/list-site-parts', [] );
echo json_encode( $o, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
