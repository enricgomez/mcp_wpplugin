<?php
require_once ABSPATH . 'wp-admin/includes/image.php'; require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/media.php';
echo "== log hires\n", @file_get_contents( '/tmp/eg-hires.log' ), "\n== detalles antiguos\n";
global $wpdb;
foreach ( [ 1492, 1493, 1494, 1495, 1496, 1497 ] as $old ) {
	$o = get_post( $old ); if ( ! $o ) { echo "$old no existe\n"; continue; }
	$src = (int) str_replace( 'detalle-', '', get_post_meta( $old, '_eg_src', true ) ); $file = get_attached_file( $src );
	$ed = wp_get_image_editor( $file ); if ( is_wp_error( $ed ) ) { echo "$old ERR editor\n"; continue; }
	$s = $ed->get_size(); $cw = (int) round( min( $s['width'], $s['height'] * 4 / 3 ) * 0.92 ); $ch = (int) round( $cw * 0.75 );
	$x = (int) ( ( $s['width'] - $cw ) / 2 ); $y = (int) max( 0, min( $s['height'] - $ch, ( $s['height'] - $ch ) * 0.35 ) );
	$ed->crop( $x, $y, $cw, $ch ); $ed->set_quality( 84 ); $f = "/tmp/eg-det2-$src.jpg"; $ed->save( $f, 'image/jpeg' );
	$name = preg_replace( '/(-\d+)?\.jpg$/', '', basename( get_attached_file( $old ) ) ) . '.jpg'; $tmp = wp_tempnam( $name ); copy( $f, $tmp ); @unlink( $f );
	$new = media_handle_sideload( [ 'name' => $name, 'tmp_name' => $tmp ], $o->post_parent, $o->post_title ); if ( is_wp_error( $new ) ) { echo "$old ERR ", $new->get_error_message(), "\n"; continue; }
	update_post_meta( $new, '_wp_attachment_image_alt', get_post_meta( $old, '_wp_attachment_image_alt', true ) ); update_post_meta( $new, '_eg_src', "detalle-$src" );
	$p = $o->post_parent; $g = get_post_meta( $p, 'galeria', true ); update_post_meta( $p, 'galeria', implode( ',', array_map( fn( $i ) => (int) $i === $old ? $new : $i, explode( ',', $g ) ) ) );
	wp_delete_attachment( $old, true ); echo "$old -> $new {$cw}x{$ch} (proyecto $p)\n";
}
wp_cache_flush();
