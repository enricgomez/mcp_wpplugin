<?php
$est = get_page_by_path( 'estudio' ); $src = json_decode( get_post_meta( $est->ID, '_elementor_data', true ), true ); $tpl = null;
$is1476 = function ( $e ) { return ( $e['widgetType'] ?? '' ) === 'e-image' && false !== strpos( wp_json_encode( $e['settings'] ?? [] ), '"id":{"$$type":"image-attachment-id","value":1476}' ); };
$find = function ( $els ) use ( &$find, &$tpl, $is1476 ) { foreach ( $els as $e ) { if ( ! $tpl && $is1476( $e ) && false !== strpos( wp_json_encode( $e['settings'] ), '"full"' ) ) { $tpl = $e; return; } if ( ! empty( $e['elements'] ) ) $find( $e['elements'] ); } };
$find( $src );
if ( ! $tpl ) { $f = null; $g = function ( $els ) use ( &$g, &$f ) { foreach ( $els as $e ) { if ( ( $e['widgetType'] ?? '' ) === 'e-image' && ! $f ) $f = $e; if ( ! empty( $e['elements'] ) ) $g( $e['elements'] ); } }; $g( $src ); echo "sin plantilla; ejemplo: ", substr( wp_json_encode( $f['settings'] ?? [] ), 0, 600 ), "\n"; exit; }
$tpl['id'] = substr( md5( uniqid() ), 0, 7 );
$d = json_decode( get_post_meta( 6, '_elementor_data', true ), true ); $n = 0;
$walk = function ( &$els ) use ( &$walk, $tpl, &$n, $is1476 ) { foreach ( $els as &$e ) { if ( ! empty( $e['elements'] ) ) { foreach ( $e['elements'] as $k => $c ) { if ( $is1476( $c ) ) { $e['elements'][ $k ] = $tpl; $n++; } } $walk( $e['elements'] ); } } };
$walk( $d );
if ( $n ) { update_post_meta( 6, '_elementor_data', wp_slash( wp_json_encode( $d ) ) ); delete_post_meta( 6, '_elementor_css' ); delete_post_meta( 6, '_elementor_element_cache' ); }
wp_cache_flush(); \Elementor\Plugin::$instance->files_manager->clear_cache();
echo "home foto: $n\n";
