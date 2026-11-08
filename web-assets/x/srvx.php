<?php
// 08/11: pie con enlaces a servicios adicionales + redirecciones antiguas a las páginas nuevas
$H = home_url();
// 1. pie
$ex = get_posts( [ 'post_type' => 'elementor_library', 'title' => 'Pie', 'numberposts' => 1, 'post_status' => 'any', 'meta_key' => '_elementor_template_type', 'meta_value' => 'footer' ] );
if ( ! $ex ) { echo "SIN PIE\n"; } else {
	$id = $ex[0]->ID; $data = json_decode( get_post_meta( $id, '_elementor_data', true ), true );
	$lid = null; $tpl = null;
	$find = function ( $els, $pid ) use ( &$find, &$lid, &$tpl ) {
		foreach ( $els as $e ) {
			$j = json_encode( $e['settings'] ?? [] );
			if ( $pid && ! $lid && false !== strpos( $j, 'servicios\/sistemas\/' ) ) $lid = $pid;
			if ( ! $tpl && false !== strpos( $j, '\/blog\/"' ) && false !== strpos( $j, 'Blog<\/span>' ) ) $tpl = $e;
			if ( ! empty( $e['elements'] ) ) $find( $e['elements'], $e['id'] );
		}
	};
	$find( $data, null );
	$getl = function ( &$els ) use ( &$getl, $lid ) { foreach ( $els as &$e ) { if ( $e['id'] === $lid ) return $e; if ( ! empty( $e['elements'] ) ) { $r = $getl( $e['elements'] ); if ( $r ) return $r; } } return null; };
	$listc = $lid ? $getl( $data ) : null;
	if ( ! $listc || ! $tpl ) { echo "PIE: no encuentro lista o plantilla (", $lid ?: 'sin lista', '/', $tpl ? 'ok' : 'sin tpl', ")\n"; } else {
		$add = [];
		if ( false !== strpos( json_encode( $listc ), 'marketing-online' ) ) { echo "PIE: ya estaba\n"; } else {
			$tj = json_encode( $tpl );
			foreach ( [ [ 'Marketing online', '/servicios/marketing-online/' ], [ 'Diseño gráfico', '/servicios/diseno-grafico/' ], [ 'Experto en WordPress', '/servicios/web/experto-wordpress/' ], [ 'Dominios y hosting', '/servicios/web/dominios-y-hosting/' ] ] as $k => [ $t, $u ] ) {
				$nj = str_replace( [ '\/blog\/"', 'Blog<\/span>' ], [ str_replace( '/', '\/', $u ) . '"', $t . '<\/span>' ], $tj );
				$n = json_decode( $nj, true ); $n['id'] = substr( md5( $u . microtime() ), 0, 7 );
				if ( ! empty( $n['settings']['_cssid'] ) ) unset( $n['settings']['_cssid'] );
				$add[] = $n;
			}
			$put = function ( &$els ) use ( &$put, $lid, $add ) { foreach ( $els as &$e ) { if ( $e['id'] === $lid ) { $e['elements'] = array_merge( $e['elements'], $add ); return true; } if ( ! empty( $e['elements'] ) && $put( $e['elements'] ) ) return true; } return false; };
			echo 'insertado=', $put( $data ) ? 1 : 0, "\n";
			update_post_meta( $id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) ); delete_post_meta( $id, '_elementor_element_cache' );
			echo "PIE: 4 enlaces añadidos (plantilla $id)\n";
		}
	}
}
// 2. redirecciones
global $wpdb; $t = $wpdb->prefix . 'rank_math_redirections';
foreach ( [ 'servicios/diseno-grafico', 'servicios/marketing-online' ] as $f ) { $n = $wpdb->query( $wpdb->prepare( "DELETE FROM $t WHERE sources LIKE %s", '%' . $wpdb->esc_like( '"' . $f . '"' ) . '%' ) ); echo "borrada redirección $f: $n\n"; }
$n = $wpdb->query( $wpdb->prepare( "UPDATE $t SET url_to=%s WHERE sources LIKE %s", $H . '/servicios/web/experto-wordpress/', '%' . $wpdb->esc_like( '"consultor-wordpress"' ) . '%' ) ); echo "consultor-wordpress actualizada: $n\n";
if ( $wpdb->get_var( "SHOW TABLES LIKE '{$t}_cache'" ) ) { $wpdb->query( "DELETE FROM {$t}_cache" ); echo "caché redirecciones vaciada\n"; }
\Elementor\Plugin::$instance->files_manager->clear_cache(); wp_cache_flush();
foreach ( [ '/servicios/diseno-grafico/', '/servicios/marketing-online/', '/consultor-wordpress/', '/servicios/web/experto-wordpress/', '/servicios/web/dominios-y-hosting/' ] as $u ) { $r = wp_remote_get( $H . $u . '?nc=' . time(), [ 'sslverify' => false, 'redirection' => 0 ] ); echo $u, ' -> ', wp_remote_retrieve_response_code( $r ), ' ', wp_remote_retrieve_header( $r, 'location' ), "\n"; }
$h = wp_remote_retrieve_body( wp_remote_get( $H . '/?nc=' . time(), [ 'sslverify' => false ] ) ); echo 'pie con marketing: ', substr_count( $h, '/servicios/marketing-online/' ), "\n";
