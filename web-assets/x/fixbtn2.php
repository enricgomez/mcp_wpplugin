<?php
do_action( 'rest_api_init' );
$K = 5; $OLD = 'g-093f75b';
$get = function ( $k ) use ( $K ) { $v = get_post_meta( $K, $k, true ); return is_array( $v ) ? $v : ( json_decode( $v, true ) ?: [] ); };
$put = function ( $k, $v ) use ( $K ) { update_post_meta( $K, $k, $v ); };
$order0 = $get( '_elementor_global_classes_order' ); $pos = array_search( $OLD, $order0, true ); echo "pos=$pos\n";
// 1. quitar huérfano
$l = $get( '_elementor_global_classes_labels' ); unset( $l[ $OLD ] ); $put( '_elementor_global_classes_labels', $l );
$ids = $get( '_elementor_global_classes_post_ids' ); unset( $ids[ $OLD ] ); $put( '_elementor_global_classes_post_ids', $ids );
foreach ( [ '_elementor_global_classes_order', '_elementor_global_classes_order_preview' ] as $k ) { $o = $get( $k ); $put( $k, array_values( array_filter( $o, fn( $x ) => $x !== $OLD ) ) ); }
wp_cache_flush();
// 2. crear
$css = json_decode( file_get_contents( '/tmp/eg-fixcls.json' ), true )[0]['css'];
$r = wp_get_ability( 'elementor/manage-classes' )->execute( [ 'operations' => [ [ 'action' => 'create', 'label' => 'eg-btn', 'css' => $css ] ] ] );
$NEW = $r['results'][0]['id'] ?? null; echo 'crear: ', json_encode( $r['results'][0] ?? $r ), "\n"; if ( ! $NEW ) return;
wp_cache_flush();
// 3. devolverle el id antiguo
$ids = $get( '_elementor_global_classes_post_ids' ); $post = $ids[ $NEW ] ?? null; echo "post nuevo=$post\n"; if ( ! $post ) return;
update_post_meta( $post, '_elementor_global_class_id', $OLD );
$n = []; foreach ( $ids as $k => $v ) $n[ $k === $NEW ? $OLD : $k ] = $v; $put( '_elementor_global_classes_post_ids', $n );
$l = $get( '_elementor_global_classes_labels' ); $n = []; foreach ( $l as $k => $v ) $n[ $k === $NEW ? $OLD : $k ] = $v; $put( '_elementor_global_classes_labels', $n );
foreach ( [ '_elementor_global_classes_order', '_elementor_global_classes_order_preview' ] as $k ) { $o = array_values( array_filter( $get( $k ), fn( $x ) => $x !== $NEW ) ); if ( false !== $pos ) array_splice( $o, min( $pos, count( $o ) ), 0, [ $OLD ] ); else $o[] = $OLD; $put( $k, $o ); }
wp_cache_flush(); \Elementor\Plugin::$instance->files_manager->clear_cache();
$res = wp_json_encode( wp_get_ability( 'elementor/global-classes-resource' )->execute( [] ) ); echo 'recurso con id antiguo: ', substr_count( $res, $OLD ), ' nuevo: ', substr_count( $res, $NEW ), "\n";
$h = wp_remote_retrieve_body( wp_remote_get( home_url( '/?nc=' . time() ), [ 'sslverify' => false ] ) );
preg_match( '#(https?://[^\'"]*global-6-frontend-desktop\.css\?ver=[^\'"]+)#', $h, $m );
$c = wp_remote_retrieve_body( wp_remote_get( $m[1], [ 'sslverify' => false ] ) ); echo 'css .eg-btn{ : ', substr_count( $c, '.eg-btn{' ), "\n";
