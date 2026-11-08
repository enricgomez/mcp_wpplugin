<?php
global $wpdb; $t = $wpdb->prefix . 'rank_math_redirections'; $H = home_url(); $to = $H . '/servicios/web/bolsa-de-horas/';
foreach ( [ 'producto/bolsa-5-horas-soporte-tecnico-wordpress', 'producto/bolsa-10-horas-soporte-tecnico-wordpress', 'soporte-wordpress/modificaciones', 'bolsa-de-horas' ] as $from ) {
	$ex = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE sources LIKE %s", '%' . $wpdb->esc_like( '"' . $from . '"' ) . '%' ) );
	if ( $ex ) { $wpdb->update( $t, [ 'url_to' => $to ], [ 'id' => $ex ] ); echo "act $from\n"; continue; }
	$red = \RankMath\Redirections\Redirection::from( [ 'sources' => [ [ 'pattern' => $from, 'comparison' => 'exact' ] ], 'url_to' => $to, 'header_code' => 301, 'status' => 'active' ] );
	echo $red->save() ? "nueva $from\n" : "fallo $from\n";
}
if ( class_exists( '\RankMath\Redirections\Cache' ) ) \RankMath\Redirections\Cache::purge_by_object_id( 0, 'any' );
