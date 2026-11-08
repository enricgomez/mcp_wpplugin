<?php
require_once ABSPATH . 'wp-admin/includes/image.php'; require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/media.php';
$O = '/var/www/vhosts/enricgomez.com/httpdocs/wp-content/uploads/';
$F = [ [ '2018/08/equipo.enric4psd.jpg', 'enric-gomez-enricgomez-studio.jpg', 'Enric Gómez, fundador de enricgomez Studio' ],
       [ '2018/08/equipo-nuria.jpg', 'nuria-grima-contenidos-seo.jpg', 'Núria Grima, contenidos y SEO' ],
       [ '2021/03/enricgomez-studio-2021.jpg', 'estudio-enricgomez-calella.jpg', 'Estudio de enricgomez Studio en Calella' ] ];
foreach ( $F as $f ) {
	$ex = get_posts( [ 'post_type' => 'attachment', 'meta_key' => '_eg_src', 'meta_value' => $f[0], 'fields' => 'ids' ] );
	if ( $ex ) { echo $f[0], ' ya ', $ex[0], ' ', wp_get_attachment_url( $ex[0] ), "\n"; continue; }
	if ( ! is_readable( $O . $f[0] ) ) { echo "NO LEGIBLE $f[0]\n"; continue; }
	$tmp = wp_tempnam( $f[1] ); copy( $O . $f[0], $tmp );
	$id = media_handle_sideload( [ 'name' => $f[1], 'tmp_name' => $tmp ], 0, $f[2] );
	if ( is_wp_error( $id ) ) { echo 'ERR ', $id->get_error_message(), "\n"; continue; }
	update_post_meta( $id, '_wp_attachment_image_alt', $f[2] ); update_post_meta( $id, '_eg_src', $f[0] );
	$m = wp_get_attachment_metadata( $id ); echo $f[0], " -> $id ", wp_get_attachment_url( $id ), ' ', $m['width'], 'x', $m['height'], "\n";
}
echo file_get_contents( '/tmp/eg-pg.log' ) ? substr( file_get_contents( '/tmp/eg-pg.log' ), -1500 ) : 'sin log';
