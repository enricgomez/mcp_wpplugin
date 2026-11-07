<?php
$d = WP_PLUGIN_DIR . '/wp-rocket'; echo 'licence-data: ', file_exists( "$d/licence-data.php" ) ? 'si' : 'no', "\n";
$o = get_option( 'wp_rocket_settings' ); echo 'settings: ', $o ? 'si' : 'no', ' key: ', ! empty( $o['consumer_key'] ) ? 'si' : 'no', ' email: ', ! empty( $o['consumer_email'] ) ? 'si' : 'no', "\n";
$p = get_plugin_data( "$d/wp-rocket.php" ); echo 'ver: ', $p['Version'], "\n";
