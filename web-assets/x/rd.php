<?php
// Redirecciones 301 de la web antigua a la nueva (Rank Math)
$mods = get_option( 'rank_math_modules', [] ); if ( ! in_array( 'redirections', $mods, true ) ) { $mods[] = 'redirections'; update_option( 'rank_math_modules', $mods ); echo "modulo redirections activado\n"; }
global $wpdb; $t = $wpdb->prefix . 'rank_math_redirections';
if ( $wpdb->get_var( "SHOW TABLES LIKE '$t'" ) !== $t ) { if ( class_exists( '\RankMath\Installer' ) ) { ( new \RankMath\Installer() )->create_tables( [ 'redirections' ] ); } }
if ( $wpdb->get_var( "SHOW TABLES LIKE '$t'" ) !== $t ) { echo "SIN TABLA $t\n"; return; }
$H = home_url(); $R = [];
$add = function ( $from, $to, $cmp = 'exact' ) use ( &$R ) { $from = ltrim( $from, '/' ); if ( 'exact' === $cmp ) $from = untrailingslashit( $from ); $R[ $cmp . '|' . $from ] = [ $from, $to, $cmp ]; };
// entradas y proyectos importados
foreach ( [ 'post', 'proyecto' ] as $pt ) foreach ( get_posts( [ 'post_type' => $pt, 'numberposts' => -1, 'post_status' => 'publish', 'meta_key' => '_eg_old_url' ] ) as $p ) {
	$old = wp_parse_url( get_post_meta( $p->ID, '_eg_old_url', true ), PHP_URL_PATH ); $new = wp_make_link_relative( get_permalink( $p ) );
	if ( $old && untrailingslashit( $old ) !== untrailingslashit( $new ) ) $add( $old, $new );
}
$map = [ 'acceso-no-permitido' => '/', 'consultor-wordpress' => '/servicios/consultoria/', 'descarga-calendario-cm' => '/blog/', 'links' => '/',
	'plugins-woocommerce' => '/servicios/web/', 'plugins-wordpress' => '/servicios/web/', 'kit-digital' => '/servicios/', 'proyectos-web' => '/proyectos/',
	'quien-somos' => '/estudio/', 'servicios/diseno-grafico' => '/servicios/web/', 'servicios/diseno-web' => '/servicios/web/', 'servicios/marketing-online' => '/servicios/consultoria/',
	'soporte-wordpress' => '/servicios/web/', 'soporte-wordpress/cursos' => '/servicios/consultoria/', 'soporte-wordpress/emergencias-wordpress' => '/servicios/web/',
	'soporte-wordpress/mantenimiento-wordpress' => '/servicios/web/', 'soporte-wordpress/modificaciones' => '/servicios/web/', 'soporte-wordpress/web-en-24-horas' => '/servicios/web/',
	'politica-de-privacidad' => '/privacidad/', 'politica-de-cookies' => '/cookies/', 'terminos-y-condiciones-de-uso' => '/condiciones/', 'novedades-ia' => '/blog/categoria/ia/' ];
foreach ( $map as $f => $to ) $add( $f, $to );
foreach ( [ '^shop(/.*)?$' => '/servicios/web/', '^producto/.*' => '/servicios/web/', '^categoria-producto/.*' => '/servicios/web/', '^etiqueta-producto/.*' => '/servicios/web/',
	'^categoria/.*' => '/blog/', '^etiqueta/.*' => '/blog/', '^project-category/.*' => '/proyectos/', '^proyecto/.*' => '/proyectos/' ] as $f => $to ) $add( $f, $to, 'regex' );
$ok = 0; $skip = 0;
foreach ( $R as $r ) {
	[ $from, $to, $cmp ] = $r;
	$ex = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $t WHERE sources LIKE %s", '%' . $wpdb->esc_like( '"' . $from . '"' ) . '%' ) );
	if ( $ex ) { $skip++; continue; }
	$red = \RankMath\Redirections\Redirection::from( [ 'sources' => [ [ 'pattern' => $from, 'comparison' => $cmp ] ], 'url_to' => $H . $to, 'header_code' => 301, 'status' => 'active' ] );
	$id = $red->save(); if ( $id ) $ok++; else echo "fallo $from\n";
}
echo "redirecciones: nuevas=$ok existentes=$skip total_mapa=", count( $R ), " en_tabla=", $wpdb->get_var( "SELECT COUNT(*) FROM $t" ), "\n";
foreach ( [ '/quien-somos/', '/ha-muerto-el-seo-con-la-ia/', '/categoria/comunicacion/', '/producto/ventas-de-entradas-para-wordpress/', '/proyecto/proyecto-polaris/', '/politica-de-cookies/', '/novedades-ia/', '/shop/' ] as $u ) {
	$r = wp_remote_get( $H . $u, [ 'sslverify' => false, 'redirection' => 0 ] ); echo $u, ' -> ', wp_remote_retrieve_response_code( $r ), ' ', wp_remote_retrieve_header( $r, 'location' ), "\n"; }
$r = wp_remote_get( $H . '/area-de-clientes/', [ 'sslverify' => false, 'redirection' => 0 ] ); echo "/area-de-clientes/ ", wp_remote_retrieve_response_code( $r ), "\n";
