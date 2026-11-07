<?php
foreach ( get_posts( [ 'post_type' => 'proyecto', 'numberposts' => -1, 'post_status' => 'any' ] ) as $p ) {
	$m = fn( $k ) => get_post_meta( $p->ID, $k, true );
	$o = [ 'id' => $p->ID, 'slug' => $p->post_name, 't' => $p->post_title, 'st' => $p->post_status, 'cli' => $m( 'cliente' ), 'svc' => $m( 'servicio' ), 'tit' => $m( 'titular' ), 'sector' => $m( 'sector' ), 'anio' => $m( 'anio' ), 'web' => $m( 'web' ),
		'datos' => array_values( (array) $m( 'datos' ) ), 'tip' => wp_get_post_terms( $p->ID, 'tipologia', [ 'fields' => 'names' ] ), 'gal' => count( array_filter( explode( ',', (string) $m( 'galeria' ) ) ) ), 'th' => get_post_thumbnail_id( $p->ID ),
		'res' => $m( 'resumen' ), 'html' => preg_replace( '/\s+/', ' ', $p->post_content ), 'rm' => [ $m( 'rank_math_title' ), $m( 'rank_math_description' ) ] ];
	echo json_encode( $o, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), "\n";
}
echo 'TIPOS: ', implode( ' | ', wp_list_pluck( get_terms( [ 'taxonomy' => 'tipologia', 'hide_empty' => false ] ), 'name' ) ), "\n";
