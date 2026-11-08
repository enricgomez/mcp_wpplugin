<?php
$only = '/servicios/diseno-web/,/soporte-wordpress/,/soporte-wordpress/mantenimiento-wordpress/,/soporte-wordpress/emergencias-wordpress/';
foreach ( explode( ',', $only ) as $u ) {
	$h = wp_remote_retrieve_body( wp_remote_get( 'https://enricgomez.com' . $u, [ 'sslverify' => false, 'timeout' => 30 ] ) );
	$h = preg_replace( '#<(script|style|noscript|svg)[^>]*>.*?</\1>#si', '', $h );
	if ( preg_match( '#<div id="main".*#si', $h, $m ) ) $h = $m[0];
	$h = preg_replace( '#<footer.*#si', '', $h );
	preg_match_all( '#<img[^>]+src="([^"]+)"#i', $h, $im );
	$t = preg_replace( '#<(br|/p|/h\d|/li|/div|/td|/tr)[^>]*>#i', "\n", $h ); $t = html_entity_decode( strip_tags( $t ) );
	$t = preg_replace( "/[ \t]+/", ' ', $t ); $t = preg_replace( "/\n\s*\n+/", "\n", $t );
	echo "===== $u\n", trim( $t ), "\nIMGS: ", implode( ' ', array_unique( array_map( 'basename', $im[1] ) ) ), "\n";
}
