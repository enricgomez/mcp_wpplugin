<?php
$D = 'acat.cat adfaltmaresme.cat adorna.es adriansanchezgarrido.com andresdiez.com apintesa.com aulablanes.cat aulacalella.cat bibliotecacardedeu.cat biecoaigua.com biomaimar.com bolsastermicas.es bonacompra.cat bowling-golf.cat byko.es cbcalella.com cfcalella.com clautic.com clinicadentalrais.es construccionspalet.com construmatpinalba.com coocoolab.com culturavivacalella.cat dance-socks.com dancesocksbcn.com dblanc.es dentipol.cat difusio.cat electrosol.cat estimemcalella.cat evaespallargas.es fetamida.cat freesowl.com hotelsorradauradasplash.com interlama.com isiegas.cat joventutartistica.com jrcabogada.com kadvanced.com mallorcaperegrina.com marxalallopa.cat mondmarketing.com navarroaubanell.com ngveterinaris.cat nilacakes.com nuriagrima.cat oidfa-spain.org oktoberfestcalella.com orivegestors.com patronatpremia.cat pizzeriadoncarlo.com sadvincles.cat shm.cat solidseedsphotocontest.com sonovet.net stargroup-home.com summer-sorra.com summerhotelcalella.com taxi-calella.com taxi-santasusanna.com teatro-de-empresa.com techostensados.com tikiburgerblanes.com trasterscalella.com uniformes-sediferent.com verofood.com viverssalicru.cat';
$L = fopen( '/tmp/eg-webs2.log', 'w' ); $out = [];
foreach ( explode( ' ', $D ) as $d ) {
	$r = wp_remote_get( "https://$d/", [ 'timeout' => 8, 'redirection' => 5, 'sslverify' => false, 'user-agent' => 'Mozilla/5.0 (EG revision)' ] );
	if ( is_wp_error( $r ) ) { $out[] = [ $d, 'ERR', $r->get_error_message() ]; continue; }
	$b = wp_remote_retrieve_body( $r ); $code = wp_remote_retrieve_response_code( $r );
	preg_match( '/<title[^>]*>(.*?)<\/title>/is', $b, $t ); preg_match( '/<meta name="generator" content="([^"]+)"/i', $b, $g );
	$ip = gethostbyname( $d ); $flags = [];
	foreach ( [ 'elementor' => 'elementor', 'wp-content' => 'wp', 'woocommerce' => 'woo', 'Default Page' => 'PLESK_DEFAULT', 'suspended' => 'SUSPENDIDA', 'Site under construction' => 'construccion', 'maintenance' => 'mantenimiento', 'noindex' => 'noindex', 'Coming Soon' => 'proximamente' ] as $k => $v ) if ( stripos( $b, $k ) !== false ) $flags[] = $v;
	$out[] = [ $d, $code, html_entity_decode( trim( preg_replace( '/\s+/', ' ', $t[1] ?? '' ) ) ), $g[1] ?? '', $ip, implode( ',', $flags ), strlen( $b ) ];
}
foreach ( $out as $o ) fwrite( $L, implode( ' | ', $o ) . "\n" ); fwrite( $L, "FIN\n" ); fclose( $L );
echo 'server ip: ', gethostbyname( 'server.egserver.cloud' ), "\n";
