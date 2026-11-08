<?php
$est = get_page_by_path( 'estudio' ); $src = json_decode( get_post_meta( $est->ID, '_elementor_data', true ), true ); $tpl = null;
$find = function ( $els ) use ( &$find, &$tpl ) { foreach ( $els as $e ) { if ( ( $e['widgetType'] ?? '' ) === 'e-image' && 1476 == ( $e['settings']['image']['value']['src']['value']['id']['value'] ?? $e['settings']['image']['value']['src']['value']['id'] ?? 0 ) ) { $tpl = $e; return; } if ( ! empty( $e['elements'] ) ) $find( $e['elements'] ); } };
$find( $src );
if ( ! $tpl ) { echo "sin plantilla de imagen\n"; echo substr( wp_json_encode( $src ), 0, 0 ); exit; }
$tpl['id'] = substr( md5( uniqid() ), 0, 7 );
$d = json_decode( get_post_meta( 6, '_elementor_data', true ), true ); $n = 0;
$walk = function ( &$els ) use ( &$walk, $tpl, &$n ) { foreach ( $els as &$e ) { if ( ! empty( $e['elements'] ) ) { foreach ( $e['elements'] as $k => $c ) { if ( false !== strpos( wp_json_encode( $c['settings'] ?? [] ), 'Foto de Enric (pendiente)' ) ) { $e['elements'][ $k ] = $tpl; $n++; } } $walk( $e['elements'] ); } } };
$walk( $d );
if ( $n ) { update_post_meta( 6, '_elementor_data', wp_slash( wp_json_encode( $d ) ) ); delete_post_meta( 6, '_elementor_css' ); delete_post_meta( 6, '_elementor_element_cache' ); }
wp_cache_flush(); \Elementor\Plugin::$instance->files_manager->clear_cache();
echo "home foto: $n\n";
