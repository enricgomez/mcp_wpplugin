<?php
require_once ABSPATH . 'wp-admin/includes/image.php'; require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/media.php';
$NUEVOS = [ 1345 => 'clautic', 296 => 'cdv', 447 => 'miami', 448 => 'taxi' ];
$sube = function ( $file, $name, $pid, $alt, $src ) {
	$ex = get_posts( [ 'post_type' => 'attachment', 'meta_key' => '_eg_src', 'meta_value' => $src, 'fields' => 'ids' ] ); if ( $ex ) return $ex[0];
	$tmp = wp_tempnam( $name ); copy( $file, $tmp ); $id = media_handle_sideload( [ 'name' => $name, 'tmp_name' => $tmp ], $pid, $alt );
	if ( is_wp_error( $id ) ) { echo 'ERR ', $id->get_error_message(), "\n"; return 0; }
	update_post_meta( $id, '_wp_attachment_image_alt', $alt ); update_post_meta( $id, '_eg_src', $src ); return $id;
};
foreach ( get_posts( [ 'post_type' => 'proyecto', 'numberposts' => -1, 'post_status' => 'publish' ] ) as $p ) {
	$g = array_values( array_filter( array_map( 'intval', explode( ',', (string) get_post_meta( $p->ID, 'galeria', true ) ) ) ) ); if ( ! $g ) continue;
	$antes = count( $g ); $fuera = [];
	$g = array_values( array_filter( $g, function ( $i ) use ( &$fuera ) { $m = wp_get_attachment_metadata( $i ); $w = $m['width'] ?? 0; $h = $m['height'] ?? 0; if ( $h < 200 || $w < 450 ) { $fuera[] = $i; return false; } return true; } ) );
	if ( $fuera ) update_post_meta( $p->ID, '_eg_galeria_descartes', implode( ',', array_unique( array_merge( array_filter( explode( ',', (string) get_post_meta( $p->ID, '_eg_galeria_descartes', true ) ) ), $fuera ) ) ) );
	$nuevo = 0;
	if ( ( count( $g ) - 1 ) % 2 ) {
		$t = get_the_title( $p ) . ' · detalle';
		if ( isset( $NUEVOS[ $p->ID ] ) ) { $k = $NUEVOS[ $p->ID ]; $nuevo = $sube( "/tmp/eg-det-$k.jpg", $p->post_name . '-detalle.jpg', $p->ID, $t, "detalle-$k" ); }
		else {
			$best = 0; $ba = 0;
			foreach ( array_slice( $g, 1 ) as $i ) { $m = wp_get_attachment_metadata( $i ); $w = $m['width']; $h = $m['height']; $r = $w / max( 1, $h ); if ( $w >= 900 && $r > 1.1 && $r < 2 && $w * $h > $ba ) { $ba = $w * $h; $best = $i; } }
			if ( ! $best ) foreach ( array_slice( $g, 1 ) as $i ) { $m = wp_get_attachment_metadata( $i ); if ( $m['width'] * $m['height'] > $ba ) { $ba = $m['width'] * $m['height']; $best = $i; } }
			$ex = get_posts( [ 'post_type' => 'attachment', 'meta_key' => '_eg_src', 'meta_value' => "detalle-$best", 'fields' => 'ids' ] );
			if ( $ex ) $nuevo = $ex[0]; else {
				$ed = wp_get_image_editor( get_attached_file( $best ) ); $s = $ed->get_size(); $cw = (int) round( $s['width'] * 0.6 ); $ch = (int) round( $cw * 0.75 ); if ( $ch > $s['height'] ) { $ch = $s['height']; $cw = (int) round( $ch / 0.75 ); }
				$x = (int) ( ( $s['width'] - $cw ) / 2 ); $y = (int) max( 0, min( $s['height'] - $ch, ( $s['height'] - $ch ) * 0.35 ) );
				$ed->crop( $x, $y, $cw, $ch ); $ed->set_quality( 82 ); $f = '/tmp/eg-detalle-' . $best . '.jpg'; $ed->save( $f, 'image/jpeg' );
				$nuevo = $sube( $f, $p->post_name . '-detalle.jpg', $p->ID, $t, "detalle-$best" ); @unlink( $f );
			}
		}
		if ( $nuevo ) $g[] = $nuevo;
	}
	update_post_meta( $p->ID, 'galeria', implode( ',', $g ) );
	echo $p->post_name, ": $antes -> ", count( $g ), ( $fuera ? ' fuera=' . implode( ',', $fuera ) : '' ), ( $nuevo ? " detalle=$nuevo" : '' ), "\n";
}
wp_cache_flush();
