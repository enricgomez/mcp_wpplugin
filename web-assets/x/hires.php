<?php
// Sustituye las capturas de proyectos por versiones de 2400 px y borra las antiguas
require_once ABSPATH . 'wp-admin/includes/image.php'; require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/media.php';
global $wpdb; $map = [];
foreach ( [ 'clautic', 'cdv', 'miami', 'taxi' ] as $t ) foreach ( glob( "/tmp/eg-out-$t-*.jpg" ) as $f ) {
	$base = substr( basename( $f ), 3 ); $key = str_ends_with( $base, '-detalle.jpg' ) ? "detalle-$t" : "cap-$base";
	$old = (int) $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_eg_src' AND meta_value=%s LIMIT 1", $key ) );
	if ( ! $old ) { echo "sin antigua $key\n"; continue; }
	$o = get_post( $old ); $alt = get_post_meta( $old, '_wp_attachment_image_alt', true ); $name = preg_replace( '/(-\d+)?\.jpg$/', '', basename( get_attached_file( $old ) ) ) . '.jpg';
	$tmp = wp_tempnam( $name ); copy( $f, $tmp );
	$new = media_handle_sideload( [ 'name' => $name, 'tmp_name' => $tmp ], $o->post_parent, $o->post_title );
	if ( is_wp_error( $new ) ) { echo "ERR $key ", $new->get_error_message(), "\n"; continue; }
	update_post_meta( $new, '_wp_attachment_image_alt', $alt ); update_post_meta( $new, '_eg_src', $key );
	$map[ $old ] = $new; $m = wp_get_attachment_metadata( $new ); echo "$key: $old -> $new {$m['width']}x{$m['height']}\n";
}
foreach ( get_posts( [ 'post_type' => 'proyecto', 'numberposts' => -1, 'post_status' => 'any' ] ) as $p ) {
	$th = (int) get_post_thumbnail_id( $p->ID ); if ( isset( $map[ $th ] ) ) set_post_thumbnail( $p->ID, $map[ $th ] );
	$g = get_post_meta( $p->ID, 'galeria', true ); if ( $g ) { $n = implode( ',', array_map( fn( $i ) => $map[ (int) $i ] ?? $i, explode( ',', $g ) ) ); if ( $n !== $g ) { update_post_meta( $p->ID, 'galeria', $n ); echo "galeria {$p->post_name}: $n\n"; } }
}
foreach ( $map as $old => $new ) {
	$refs = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_elementor_data' AND (meta_value LIKE %s OR meta_value LIKE %s)", '%"id":' . $old . ',%', '%"id":' . $old . '}%' ) );
	if ( $refs ) echo "OJO $old usado en elementor: ", implode( ',', $refs ), "\n";
	else { wp_delete_attachment( $old, true ); echo "borrada $old\n"; }
}
\Elementor\Plugin::$instance->files_manager->clear_cache(); wp_cache_flush();
