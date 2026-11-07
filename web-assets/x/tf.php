<?php
do_action( 'rest_api_init' );
$run = function ( $n, $in ) { $r = wp_get_ability( $n )->execute( $in ); return is_wp_error( $r ) ? [ 'error' => $r->get_error_code(), 'msg' => $r->get_error_message(), 'data' => $r->get_error_data() ] : $r; };
$c = json_decode( file_get_contents( '/tmp/eg-tf.json' ), true );
echo 'build: ', substr( json_encode( $run( 'elementor/build-composition', $c['build']['input'] ), JSON_UNESCAPED_UNICODE ), 0, 1500 ), "\n";
echo 'pub: ', substr( json_encode( $run( 'elementor/publish-document', [ 'post_id' => 444 ] ) ), 0, 200 ), "\n";
$h = wp_remote_retrieve_body( wp_remote_get( get_permalink( 444 ) . '?nc=' . time(), [ 'sslverify' => false ] ) );
$a = strpos( $h, "<details" ); echo substr( $h, $a ?: 0, 2500 ), "\n...\n"; $b = strpos( $h, '<form' ); echo substr( $h, $b ?: 0, 3500 );
