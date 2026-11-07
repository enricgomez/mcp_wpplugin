<?php
global $wpdb; $c = $wpdb->prefix . 'rank_math_redirections_cache';
echo 'cache tabla: ', $wpdb->get_var( "SHOW TABLES LIKE '$c'" ) ?: 'no', ' filas=', $wpdb->get_var( "SELECT COUNT(*) FROM $c" ), "\n";
$wpdb->query( "TRUNCATE $c" ); wp_cache_flush(); echo "flush ok\n";
