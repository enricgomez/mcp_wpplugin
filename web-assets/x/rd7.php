<?php
global $wpdb; $t = $wpdb->prefix . 'rank_math_redirections'; $H = home_url();
foreach ( [ 'soporte-wordpress' => '/servicios/web/mantenimiento/', 'soporte-wordpress/mantenimiento-wordpress' => '/servicios/web/mantenimiento/', 'soporte-wordpress/emergencias-wordpress' => '/urgencias-web/' ] as $f => $to ) {
	$n = $wpdb->query( $wpdb->prepare( "UPDATE $t SET url_to=%s WHERE sources LIKE %s", $H . $to, '%' . $wpdb->esc_like( '"' . $f . '"' ) . '%' ) ); echo "$f -> $to: $n\n";
}
$wpdb->query( "DELETE FROM {$wpdb->prefix}rank_math_redirections_cache" );
wp_cache_flush();
foreach ( [ '/soporte-wordpress/', '/soporte-wordpress/emergencias-wordpress/', '/soporte-wordpress/mantenimiento-wordpress/' ] as $u ) { $r = wp_remote_get( $H . $u, [ 'sslverify' => false, 'redirection' => 0 ] ); echo $u, ' -> ', wp_remote_retrieve_response_code( $r ), ' ', wp_remote_retrieve_header( $r, 'location' ), "\n"; }
