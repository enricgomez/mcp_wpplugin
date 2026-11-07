<?php
require_once ABSPATH . 'wp-admin/includes/media.php'; require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/image.php';
// imagen social por defecto
$og = get_posts( [ 'post_type' => 'attachment', 'meta_key' => '_eg_src', 'meta_value' => 'og-enricgomez', 'numberposts' => 1 ] );
if ( $og ) $oid = $og[0]->ID; else { $tmp = wp_tempnam(); copy( '/tmp/eg-og-enricgomez.jpg', $tmp ); $oid = media_handle_sideload( [ 'name' => 'enricgomez-studio-og.jpg', 'tmp_name' => $tmp ], 0, 'enricgomez Studio' ); update_post_meta( $oid, '_eg_src', 'og-enricgomez' ); update_post_meta( $oid, '_wp_attachment_image_alt', 'enricgomez Studio: tecnología que vende para tu negocio' ); }
echo "og=$oid\n";
$logo = wp_get_attachment_url( (int) get_option( 'site_icon' ) );
$t = get_option( 'rank-math-options-titles', [] );
$t = array_merge( $t, [ 'title_separator' => '|', 'knowledgegraph_type' => 'company', 'knowledgegraph_name' => 'enricgomez Studio', 'knowledgegraph_logo' => $logo, 'knowledgegraph_logo_id' => (int) get_option( 'site_icon' ),
	'local_business_type' => 'Organization', 'email' => 'info@enricgomez.com', 'url' => 'https://enricgomez.com',
	'local_address' => [ 'streetAddress' => 'C/ Costa i Fornaguera, 27', 'addressLocality' => 'Calella', 'addressRegion' => 'Barcelona', 'postalCode' => '08370', 'addressCountry' => 'ES' ],
	'open_graph_image' => wp_get_attachment_url( $oid ), 'open_graph_image_id' => $oid,
	'disable_author_archives' => 'on', 'disable_date_archives' => 'on',
	'pt_proyecto_title' => '%title% %sep% %sitename%', 'pt_proyecto_description' => '%excerpt%', 'pt_proyecto_robots' => [], 'pt_proyecto_add_meta_box' => 'on',
	'tax_post_tag_robots' => [ 'noindex' ], 'tax_post_tag_custom_robots' => 'on',
	'pt_attachment_robots' => [ 'noindex' ], 'pt_attachment_custom_robots' => 'on' ] );
update_option( 'rank-math-options-titles', $t );
$g = get_option( 'rank-math-options-general', [] ); $g = array_merge( $g, [ 'attachment_redirect_urls' => 'on', 'attachment_redirect_default' => home_url( '/' ), 'strip_category_base' => 'off', 'redirections_post_redirect' => 'on' ] ); update_option( 'rank-math-options-general', $g );
$s = get_option( 'rank-math-options-sitemap', [] ); $s = array_merge( $s, [ 'pt_proyecto_sitemap' => 'on', 'pt_page_sitemap' => 'on', 'pt_post_sitemap' => 'on', 'tax_category_sitemap' => 'on', 'tax_post_tag_sitemap' => 'off', 'pt_attachment_sitemap' => 'off', 'include_images' => 'on' ] ); update_option( 'rank-math-options-sitemap', $s );
echo "rank math ok\n";
// Imagify: WebP + etiqueta picture, redimensionar a 2560
$i = get_option( 'imagify_settings', [] ); $i = array_merge( $i, [ 'optimization_format' => 'webp', 'convert_to_webp' => 1, 'display_nextgen' => 1, 'display_nextgen_method' => 'picture', 'auto_optimize' => 1, 'resize_larger' => 1, 'resize_larger_w' => 2560, 'optimization_level' => 2 ] ); update_option( 'imagify_settings', $i );
echo "imagify ok\n";
// home y comprobaciones
wp_cache_flush(); \Elementor\Plugin::$instance->files_manager->clear_cache();
foreach ( [ '/', '/proyectos/clautic/', '/blog/ha-muerto-el-seo-con-la-ia/' ] as $u ) { $h = wp_remote_retrieve_body( wp_remote_get( home_url( $u . '?nc=' . time() ), [ 'sslverify' => false ] ) );
	preg_match( '/<title>(.*?)<\/title>/s', $h, $m ); preg_match( '/og:image" content="([^"]+)/', $h, $o ); echo $u, ' | ', $m[1] ?? '-', ' | og=', basename( $o[1] ?? '-' ), ' | picture=', substr_count( $h, '<picture' ), "\n"; }
$r = wp_remote_get( home_url( '/sitemap_index.xml' ), [ 'sslverify' => false ] ); echo 'sitemap ', wp_remote_retrieve_response_code( $r ), ' ', substr_count( wp_remote_retrieve_body( $r ), '<sitemap>' ), " sitemaps\n";
