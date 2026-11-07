<?php
// Crea/actualiza proyectos con textos, SEO e imágenes (capturas). Idempotente. También pone el favicon.
require_once ABSPATH . 'wp-admin/includes/image.php'; require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/media.php';
$P = json_decode( file_get_contents( '/tmp/eg-pdata.json' ), true );
$img = function ( $f, $pid, $alt ) {
	global $wpdb; $k = 'cap-' . $f;
	$e = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key='_eg_src' AND meta_value=%s LIMIT 1", $k ) ); if ( $e ) { update_post_meta( $e, '_wp_attachment_image_alt', $alt ); return (int) $e; }
	$src = '/tmp/eg-' . $f; if ( ! is_file( $src ) ) { echo " FALTA $f\n"; return 0; }
	$tmp = wp_tempnam( $f ); copy( $src, $tmp ); $ed = wp_get_image_editor( $tmp );
	if ( ! is_wp_error( $ed ) && ! str_ends_with( $f, '.png' ) ) { $ed->resize( 1200, null ); $ed->set_quality( 80 ); $r = $ed->save( $tmp . '.jpg', 'image/jpeg' ); @unlink( $tmp ); $tmp = $r['path']; }
	$name = str_ends_with( $f, '.png' ) ? 'favicon-enricgomez.png' : sanitize_title( $alt ) . '-' . preg_replace( '/^out-[a-z]+-|\.jpg$/', '', $f ) . '.jpg';
	$id = media_handle_sideload( [ 'name' => $name, 'tmp_name' => $tmp ], $pid, $alt ); if ( is_wp_error( $id ) ) { echo ' ERR ', $id->get_error_message(), "\n"; return 0; }
	update_post_meta( $id, '_eg_src', $k ); update_post_meta( $id, '_wp_attachment_image_alt', $alt ); return $id;
};
foreach ( $P as $x ) {
	$p = get_page_by_path( $x['slug'], OBJECT, 'proyecto' );
	$arr = [ 'post_type' => 'proyecto', 'post_status' => 'publish', 'post_name' => $x['slug'], 'post_content' => $x['html'], 'post_excerpt' => $x['resumen'] ];
	if ( ! empty( $x['title'] ) ) $arr['post_title'] = $x['title'];
	if ( $p ) { $arr['ID'] = $p->ID; $pid = wp_update_post( wp_slash( $arr ), true ); } else { $arr['post_author'] = get_user_by( 'login', 'enric' )->ID ?? 1; $pid = wp_insert_post( wp_slash( $arr ), true ); }
	if ( is_wp_error( $pid ) ) { echo 'ERROR ', $x['slug'], ' ', $pid->get_error_message(), "\n"; continue; }
	update_post_meta( $pid, 'titular', $x['titular'] ); update_post_meta( $pid, 'resumen', $x['resumen'] );
	foreach ( [ 'cliente', 'servicio', 'sector', 'anio', 'web' ] as $k ) if ( array_key_exists( $k, $x ) ) update_post_meta( $pid, $k, $x[ $k ] );
	if ( ! empty( $x['datos'] ) ) { $r = []; foreach ( $x['datos'] as $i => $d ) $r[ 'item-' . $i ] = [ 'etiqueta' => $d[0], 'valor' => $d[1] ]; update_post_meta( $pid, 'datos', $r ); }
	if ( ! empty( $x['tips'] ) ) wp_set_object_terms( $pid, $x['tips'], 'tipologia' );
	[ $st, $sd, $kw ] = $x['seo']; update_post_meta( $pid, 'rank_math_title', $st . ' %sep% %sitename%' ); update_post_meta( $pid, 'rank_math_description', $sd ); update_post_meta( $pid, 'rank_math_focus_keyword', $kw );
	$n = 0;
	if ( ! empty( $x['imgs'] ) ) {
		$cli = $x['cliente'] ?? get_post_meta( $pid, 'cliente', true ); $ids = [];
		foreach ( $x['imgs'] as $i => $f ) { $alt = $cli . ( 0 === $i ? ': web en ordenador y móvil' : ( false !== strpos( $f, 'moviles' ) ? ': versión móvil' : ': página interior de la web' ) ); $id = $img( $f, $pid, $alt ); if ( $id ) $ids[] = $id; }
		if ( $ids ) { set_post_thumbnail( $pid, $ids[0] ); update_post_meta( $pid, 'galeria', implode( ',', array_slice( $ids, 1 ) ) ); $n = count( $ids ); }
	}
	echo $pid, ' ', $x['slug'], ' ok', $n ? " imgs=$n" : '', "\n";
}
// favicon = símbolo de la marca
$fid = $img( 'favicon-512.png', 0, 'enricgomez Studio' );
if ( $fid ) { update_option( 'site_icon', $fid ); echo "favicon $fid\n"; }
\Elementor\Plugin::$instance->files_manager->clear_cache(); wp_cache_flush();
