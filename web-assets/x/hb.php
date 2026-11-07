<?php
do_action( 'rest_api_init' );
$cfg = json_decode( file_get_contents( '/tmp/eg-hb.json' ), true );
$run = function ( $n, $in ) { $r = wp_get_ability( $n )->execute( $in ); return is_wp_error( $r ) ? [ 'error' => $r->get_error_code(), 'msg' => $r->get_error_message() ] : $r; };
$have = wp_json_encode( $run( 'elementor/global-classes-resource', [] ) );
$ops = array_map( function ( $o ) use ( $have ) { if ( false !== strpos( $have, '"' . $o['label'] . '"' ) || false !== strpos( $have, '\\"' . $o['label'] . '\\"' ) ) { $o['action'] = 'update'; $o['mode'] = 'replace'; } return $o; }, $cfg['classes'] );
echo 'clases: ', substr( json_encode( $run( 'elementor/manage-classes', [ 'operations' => $ops ] ) ), 0, 120 ), "\n";
$d = json_decode( get_post_meta( 6, '_elementor_data', true ), true ); $pid = null;
$f = function ( $els ) use ( &$f, &$pid ) { foreach ( $els as $e ) { if ( ( $e['editor_settings']['title'] ?? '' ) === 'Blog rejilla' ) $pid = $e['id']; if ( ! empty( $e['elements'] ) ) $f( $e['elements'] ); } }; $f( $d );
echo "rejilla=$pid\n"; if ( ! $pid ) return;
$in = $cfg['build']['input']; $in['parent_id'] = $pid;
echo 'build: ', substr( json_encode( $run( 'elementor/build-composition', $in ) ), 0, 200 ), "\n";
echo 'pub: ', substr( json_encode( $run( 'elementor/publish-document', [ 'post_id' => 6 ] ) ), 0, 80 ), "\n";
file_put_contents( '/tmp/eg-dyn.json', json_encode( $cfg['dyn'] ) ); include '/tmp/eg-dynfix.php';
