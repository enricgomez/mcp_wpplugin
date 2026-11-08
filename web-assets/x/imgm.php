<?php
$p = imagify_get_optimization_process( 1528, 'wp' ); echo get_class( $p ), "\n";
foreach ( ( new ReflectionClass( $p ) )->getMethods( ReflectionMethod::IS_PUBLIC ) as $m ) echo $m->name, '(', implode( ',', array_map( fn( $x ) => '$' . $x->name, $m->getParameters() ) ), ') ';
echo "\nopt: ", json_encode( get_option( 'imagify_settings' )['optimization_format'] ?? null ), ' lvl ', json_encode( get_option( 'imagify_settings' )['optimization_level'] ?? null ), "\n";
echo 'status 1528: ', json_encode( get_post_meta( 1528, '_imagify_status', true ) ), ' data: ', substr( json_encode( get_post_meta( 1528, '_imagify_data', true ) ), 0, 300 ), "\n";
echo 'status 1346-like viejo ejemplo 300: ', json_encode( get_post_meta( 300, '_imagify_status', true ) ), "\n";
echo 'cron next: ', json_encode( array_slice( array_map( fn( $e ) => array_keys( $e ), _get_cron_array() ), 0, 5 ) ), "\n";
$r = wp_remote_post( admin_url( 'admin-ajax.php' ), [ 'timeout' => 5, 'sslverify' => false ] ); echo 'loopback: ', is_wp_error( $r ) ? $r->get_error_message() : wp_remote_retrieve_response_code( $r ), "\n";
