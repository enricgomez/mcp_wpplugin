<?php
global $wpdb;
$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ('page','product','post') AND post_content LIKE '%295%' AND (post_content LIKE '%Starter%' OR post_content LIKE '%incluye%')" );
foreach ( $ids as $id ) { $c = get_post_field( 'post_content', $id ); echo "=== $id ", get_permalink( $id ), "\n";
	$c = preg_replace( '/\[(\/?)[a-z_0-9]+[^\]]*\]/', "\n", $c ); $c = preg_replace( '/<[^>]+>/', ' ', $c ); $c = html_entity_decode( $c ); $c = preg_replace( "/[ \t]+/", ' ', $c ); $c = preg_replace( "/\n\s*\n+/", "\n", $c );
	echo substr( trim( $c ), 0, 9000 ), "\n"; }
