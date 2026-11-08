<?php
do_action( 'rest_api_init' );
$r = wp_get_ability( 'elementor/global-classes-resource' )->execute( [] );
$j = json_encode( $r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
echo substr( $j, 0, 1200 ), "\n";
preg_match_all( '/eg-btn[a-z-]*/', $j, $m ); echo implode( ' ', array_unique( $m[0] ) ), "\n";
$o = get_option( 'elementor_global_classes' ); echo 'opt=', is_array( $o ) ? count( $o['items'] ?? $o ) : var_export( $o, true ), "\n";
global $wpdb; foreach ( $wpdb->get_results( "SELECT option_name, LENGTH(option_value) l FROM {$wpdb->options} WHERE option_name LIKE '%global_class%' OR option_name LIKE '%global-class%'" ) as $x ) echo $x->option_name, ' ', $x->l, "\n";
$kit = get_option( 'elementor_active_kit' ); echo "kit=$kit\n";
foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, LENGTH(meta_value) l FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key LIKE %s", $kit, '%class%' ) ) as $x ) echo $x->meta_key, ' ', $x->l, "\n";
$m = get_post_meta( $kit, '_elementor_global_classes', true ); if ( $m ) { $d = is_array( $m ) ? $m : json_decode( $m, true ); echo 'items=', count( $d['items'] ?? [] ), "\n"; foreach ( $d['items'] ?? [] as $id => $it ) if ( 'eg-btn' === ( $it['label'] ?? '' ) ) echo json_encode( $it ), "\n"; }
