<?php
do_action( 'rest_api_init' );
$cfg = json_decode( file_get_contents( '/tmp/eg-pr.json' ), true );
$run = function ( $n, $in ) { $r = wp_get_ability( $n )->execute( $in ); return is_wp_error( $r ) ? [ 'error' => $r->get_error_code(), 'msg' => $r->get_error_message(), 'data' => $r->get_error_data() ] : $r; };
$have = wp_json_encode( $run( 'elementor/global-classes-resource', [] ) );
$ops = array_map( function ( $o ) use ( $have ) { if ( false !== strpos( $have, '"' . $o['label'] . '"' ) || false !== strpos( $have, '\\"' . $o['label'] . '\\"' ) ) { $o['action'] = 'update'; $o['mode'] = 'replace'; } return $o; }, $cfg['classes'] );
$r = $run( 'elementor/manage-classes', [ 'operations' => $ops ] ); echo 'clases: ', substr( json_encode( $r ), 0, 300 ), "\n";
$ex = get_posts( [ 'post_type' => 'elementor_library', 'title' => 'Proyecto', 'numberposts' => 1, 'post_status' => 'any', 'meta_key' => '_elementor_template_type', 'meta_value' => 'single' ] );
if ( $ex ) $id = $ex[0]->ID; else {
	$r = $run( 'elementor/manage-site-parts', [ 'operations' => [ [ 'action' => 'create', 'type' => 'single', 'title' => 'Proyecto', 'conditions' => [ 'include/singular/proyecto' ] ] ] ] );
	echo 'crear: ', substr( json_encode( $r ), 0, 300 ), "\n";
	$id = $r['results'][0]['post_id'] ?? $r['results'][0]['id'] ?? null;
	if ( ! $id ) { $ex = get_posts( [ 'post_type' => 'elementor_library', 'title' => 'Proyecto', 'numberposts' => 1, 'post_status' => 'any' ] ); $id = $ex ? $ex[0]->ID : 0; }
}
echo "plantilla=$id\n"; if ( ! $id ) return;
$in = $cfg['build']['input']; $in['post_id'] = (int) $id;
$r = $run( 'elementor/build-composition', $in ); echo 'build: ', substr( json_encode( $r, JSON_UNESCAPED_UNICODE ), 0, 400 ), "\n";
$r = $run( 'elementor/publish-document', [ 'post_id' => (int) $id ] ); echo 'pub: ', substr( json_encode( $r ), 0, 150 ), "\n";
$cfg['dyn'][0]['post_id'] = (int) $id; file_put_contents( '/tmp/eg-dyn.json', json_encode( $cfg['dyn'] ) ); include '/tmp/eg-dynfix.php';
echo 'condiciones: ', json_encode( get_post_meta( $id, '_elementor_conditions', true ) ), "\n";
$h = wp_remote_retrieve_body( wp_remote_get( home_url( '/proyectos/hotel-miami-calella-web/?nc=' . time() ), [ 'sslverify' => false, 'timeout' => 30 ] ) );
preg_match( '/<h1[^>]*>(.*?)<\/h1>/s', $h, $m ); echo 'h1: ', strip_tags( $m[1] ?? 'NINGUNO' ), "\n";
echo ( false !== strpos( $h, '{{' ) ? 'QUEDAN MARCADORES' : 'sin marcadores' ), ' | eg-pr-ficha: ', substr_count( $h, 'eg-pr-ficha' ), ' | reto: ', substr_count( $h, 'El reto' ), "\n";
