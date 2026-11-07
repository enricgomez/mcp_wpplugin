<?php
foreach ( [ 'rank_math_registration_skip', 'rank_math_connect_data', 'rank_math_wizard_completed', 'rank_math_is_configured' ] as $o ) echo $o, '=', json_encode( get_option( $o ) ), "\n";
update_option( 'rank_math_registration_skip', 1 ); update_option( 'rank_math_wizard_completed', true ); update_option( 'rank_math_is_configured', true );
wp_cache_flush();
$r = wp_remote_get( home_url( '/quien-somos/' ), [ 'sslverify' => false, 'redirection' => 0 ] ); echo 'test: ', wp_remote_retrieve_response_code( $r ), ' ', wp_remote_retrieve_header( $r, 'location' ), "\n";
