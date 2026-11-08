<?php
do_action( 'rest_api_init' );
$ops = json_decode( file_get_contents( '/tmp/eg-fixcls.json' ), true );
$r = wp_get_ability( 'elementor/manage-classes' )->execute( [ 'operations' => $ops ] );
echo substr( json_encode( is_wp_error( $r ) ? $r->get_error_message() : $r, JSON_UNESCAPED_UNICODE ), 0, 1500 ), "\n";
\Elementor\Plugin::$instance->files_manager->clear_cache(); wp_cache_flush();
$h = wp_remote_retrieve_body( wp_remote_get( home_url( '/?nc=' . time() ), [ 'sslverify' => false ] ) );
preg_match( '#global-6-frontend-desktop\.css\?ver=[^\'"]+#', $h, $m ); echo $m[0] ?? 'sin css', "\n";
