<?php
// Importa el blog antiguo (/tmp/eg-blog.json + /tmp/eg-blogimg/) a la web nueva. Idempotente: salta los posts ya importados (_eg_old_id).
// Pensado para correr en segundo plano: escribe el progreso en /tmp/eg-blog.log
require_once ABSPATH . 'wp-admin/includes/media.php'; require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/image.php';
$L = fopen( '/tmp/eg-blog.log', 'a' ); $log = function ( $s ) use ( $L ) { fwrite( $L, date( 'H:i:s ' ) . $s . "\n" ); fflush( $L ); };
$posts = json_decode( file_get_contents( '/tmp/eg-blog.json' ), true );
$user = get_user_by( 'login', 'enric' ); $uid = $user ? $user->ID : 1;

// permalinks /blog/<post>/ y categorías /blog/categoria/<cat>/
if ( get_option( 'permalink_structure' ) !== '/blog/%postname%/' ) { update_option( 'permalink_structure', '/blog/%postname%/' ); update_option( 'category_base', 'categoria' ); $log( 'permalinks cambiados' ); }
// categorías nuevas = las cuatro piezas
$CATS = [ 'web' => 'Web', 'ia' => 'IA', 'consultoria' => 'Consultoría', 'sistemas' => 'Sistemas' ]; $cid = [];
foreach ( $CATS as $s => $n ) { $t = get_term_by( 'slug', $s, 'category' ); $cid[ $s ] = $t ? $t->term_id : wp_insert_term( $n, 'category', [ 'slug' => $s ] )['term_id']; }
$unc = get_term_by( 'slug', 'uncategorized', 'category' ) ?: get_term_by( 'slug', 'sin-categoria', 'category' );
if ( $unc && ! in_array( $unc->term_id, $cid, true ) ) update_option( 'default_category', $cid['web'] );

$cat_for = function ( $p ) {
	$t = mb_strtolower( $p['title'] . ' ' . ( $p['seo']['kw'] ?? '' ) );
	if ( preg_match( '/inteligencia artificial|\bia\b|chatgpt|\bllm|gemini|openai|copilot|perplexity|automatiza/u', $t ) ) return 'ia';
	$c = $p['cats'];
	if ( array_intersect( $c, [ 'wordpress', 'woocommerce', 'diseno-web', 'ecommerce', 'hosting-dominios', 'seguridad', 'posicionamiento-web', 'seo', 'diseno-grafico' ] ) ) return 'web';
	if ( array_intersect( $c, [ 'software', 'music-audio', 'impresion' ] ) ) return 'sistemas';
	return 'consultoria';
};
$img = function ( $f, $alt, $pid ) use ( $log ) {
	if ( ! $f ) return 0;
	$q = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_eg_src', 'meta_value' => $f, 'numberposts' => 1, 'fields' => 'ids' ] ); if ( $q ) return $q[0];
	$src = '/tmp/eg-blogimg/' . $f; if ( ! is_file( $src ) ) return 0;
	$tmp = wp_tempnam( $f ); copy( $src, $tmp );
	$ed = wp_get_image_editor( $tmp );
	if ( ! is_wp_error( $ed ) ) { $s = $ed->get_size(); if ( $s['width'] > 1200 ) $ed->resize( 1200, null ); $ed->set_quality( 80 ); $r = $ed->save( $tmp ); if ( ! is_wp_error( $r ) && $r['path'] !== $tmp ) rename( $r['path'], $tmp ); }
	$name = sanitize_file_name( $alt ? mb_substr( $alt, 0, 60 ) : pathinfo( $f, PATHINFO_FILENAME ) ) . '.' . pathinfo( $f, PATHINFO_EXTENSION );
	$id = media_handle_sideload( [ 'name' => $name, 'tmp_name' => $tmp ], $pid, $alt ?: null );
	if ( is_wp_error( $id ) ) { $log( 'img error ' . $f . ' ' . $id->get_error_message() ); @unlink( $tmp ); return 0; }
	update_post_meta( $id, '_eg_src', $f ); if ( $alt ) update_post_meta( $id, '_wp_attachment_image_alt', $alt );
	return $id;
};
$slugs = array_column( $posts, 'slug' );
$conv = function ( $html, $p, $pid ) use ( $img, $slugs ) {
	$html = preg_replace_callback( '/\[vc_raw_html[^\]]*\](.*?)\[\/vc_raw_html\]/s', fn( $m ) => rawurldecode( base64_decode( trim( strip_tags( $m[1] ) ) ) ), $html );
	$html = preg_replace_callback( '/\[dm_code_snippet[^\]]*\](.*?)\[\/dm_code_snippet\]/s', fn( $m ) => '<pre><code>' . trim( $m[1] ) . '</code></pre>', $html );
	$html = preg_replace_callback( '/\[vc_single_image[^\]]*image="(\d+)"[^\]]*\]/', function ( $m ) use ( $p, $img, $pid ) {
		$i = $p['imgs'][ 'id' . $m[1] ] ?? null; $id = $i ? $img( $i['f'], $i['alt'] ?: $p['title'], $pid ) : 0;
		return $id ? "\n<figure class=\"eg-fig\">" . wp_get_attachment_image( $id, 'large', false, [ 'loading' => 'lazy' ] ) . "</figure>\n" : ''; }, $html );
	$html = preg_replace_callback( '/\[vc_custom_heading[^\]]*text="([^"]*)"[^\]]*\]/', fn( $m ) => "\n<h3>" . str_replace( [ '``', "''" ], [ '«', '»' ], $m[1] ) . "</h3>\n", $html );
	$html = preg_replace_callback( '/\[vc_video[^\]]*link="([^"]*)"[^\]]*\]/', fn( $m ) => "\n\n" . html_entity_decode( $m[1] ) . "\n\n", $html );
	$html = preg_replace_callback( '/\[dt_default_button([^\]]*)\](.*?)\[\/dt_default_button\]/s', function ( $m ) {
		if ( ! preg_match( '/link="url:([^|"]*)/', $m[1], $u ) ) return ''; $u = rawurldecode( $u[1] ); $t = trim( strip_tags( $m[2] ) );
		return ( $u && '#' !== $u && $t ) ? "\n<p class=\"eg-boton-post\"><a href=\"" . esc_url( $u ) . "\">" . esc_html( $t ) . "</a></p>\n" : ''; }, $html );
	$html = preg_replace( '/\[wpml_language[^\]]*\].*?\[\/wpml_language\]/s', '', $html );
	$html = preg_replace( '/\[products[^\]]*\]/', '', $html );
	$html = preg_replace( '/\[\/?(vc_|ult_|dt_)[^\]]*\]/', '', $html );
	$html = preg_replace( '/\s(data-start|data-end|data-pm-slice|data-sourcepos)="[^"]*"/', '', $html );
	// imágenes en línea de la web antigua
	$html = preg_replace_callback( '/<img[^>]+src="([^"]+)"[^>]*>/', function ( $m ) use ( $p, $img, $pid ) {
		$i = $p['imgs'][ 'u' . md5( $m[1] ) ] ?? null; if ( ! $i || ! $i['f'] ) return false !== strpos( $m[1], 'enricgomez.com' ) ? '' : $m[0];
		preg_match( '/alt="([^"]*)"/', $m[0], $a ); $id = $img( $i['f'], ( $a[1] ?? '' ) ?: $p['title'], $pid );
		return $id ? wp_get_attachment_image( $id, 'large', false, [ 'loading' => 'lazy' ] ) : ''; }, $html );
	// enlaces internos a posts: /<slug>/ -> /blog/<slug>/
	$html = preg_replace_callback( '~https?://(?:www\.)?enricgomez\.com/([a-z0-9-]+)/?(?=["\'#?])~', fn( $m ) => in_array( $m[1], $slugs, true ) ? home_url( '/blog/' . $m[1] . '/' ) : $m[0], $html );
	$html = preg_replace( "/(\r?\n){3,}/", "\n\n", $html );
	return trim( $html );
};

$n = 0; $t0 = time();
foreach ( $posts as $p ) {
	$ex = get_posts( [ 'post_type' => 'post', 'post_status' => 'any', 'meta_key' => '_eg_old_id', 'meta_value' => $p['id'], 'numberposts' => 1, 'fields' => 'ids' ] );
	if ( $ex ) continue;
	$cat = $cat_for( $p );
	$pid = wp_insert_post( [ 'post_type' => 'post', 'post_status' => 'draft', 'post_title' => $p['title'], 'post_name' => $p['slug'], 'post_author' => $uid, 'post_date' => $p['date'], 'post_date_gmt' => $p['date_gmt'], 'post_excerpt' => $p['excerpt'], 'comment_status' => 'closed', 'ping_status' => 'closed', 'post_category' => [ $cid[ $cat ] ] ], true );
	if ( is_wp_error( $pid ) ) { $log( 'ERROR ' . $p['slug'] . ' ' . $pid->get_error_message() ); continue; }
	update_post_meta( $pid, '_eg_old_id', $p['id'] ); update_post_meta( $pid, '_eg_old_url', $p['url'] ); update_post_meta( $pid, '_eg_old_cats', implode( ',', $p['cats'] ) );
	$content = $conv( $p['content'], $p, $pid ); if ( '' === $content ) $log( 'AVISO contenido vacío ' . $p['slug'] );
	if ( ! empty( $p['imgs']['t'] ) ) { $tid = $img( $p['imgs']['t']['f'], $p['imgs']['t']['alt'] ?: $p['title'], $pid ); if ( $tid ) set_post_thumbnail( $pid, $tid ); }
	$y = fn( $s ) => preg_replace( '/%%(\w+)%%/', '%$1%', (string) $s );
	if ( $p['seo']['title'] ) update_post_meta( $pid, 'rank_math_title', $y( $p['seo']['title'] ) );
	if ( $p['seo']['desc'] ) update_post_meta( $pid, 'rank_math_description', $y( $p['seo']['desc'] ) );
	if ( $p['seo']['kw'] ) update_post_meta( $pid, 'rank_math_focus_keyword', $p['seo']['kw'] );
	if ( '1' === (string) $p['seo']['noindex'] ) update_post_meta( $pid, 'rank_math_robots', [ 'noindex' ] );
	wp_update_post( [ 'ID' => $pid, 'post_content' => wp_slash( $content ), 'post_status' => 'publish', 'post_date' => $p['date'], 'post_date_gmt' => $p['date_gmt'], 'edit_date' => true ] );
	global $wpdb; $wpdb->update( $wpdb->posts, [ 'post_modified' => $p['modified'], 'post_modified_gmt' => get_gmt_from_date( $p['modified'] ) ], [ 'ID' => $pid ] );
	$n++; $log( "ok $n " . $p['slug'] . " [$cat]" );
}
flush_rewrite_rules( false );
$log( "FIN importados=$n en " . ( time() - $t0 ) . 's' );
