<?php
$l = get_post_meta( 5, '_elementor_global_classes_labels', true ); $ids = get_post_meta( 5, '_elementor_global_classes_post_ids', true );
$l = is_array( $l ) ? $l : json_decode( $l, true ); $ids = is_array( $ids ) ? $ids : json_decode( $ids, true );
foreach ( (array) $l as $k => $v ) if ( false !== strpos( is_string( $v ) ? $v : json_encode( $v ), 'eg-btn' ) || false !== strpos( (string) $k, 'eg-btn' ) ) echo "label $k => ", json_encode( $v ), ' post=', json_encode( $ids[ $k ] ?? ( is_string( $v ) ? ( $ids[ $v ] ?? null ) : null ) ), "\n";
echo 'tipos: ', gettype( $l ), ' ', count( (array) $l ), ' / ', gettype( $ids ), ' ', count( (array) $ids ), "\n";
echo substr( json_encode( $ids ), 0, 300 ), "\n";
$d = get_post_meta( 6, '_elementor_data', true ); $p = strpos( $d, 'g-d9f3de3' ); echo 'home ctx: ', substr( $d, max( 0, $p - 200 ), 300 ), "\n";
