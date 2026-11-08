<?php
do_action( 'rest_api_init' );
global $wpdb;
// id antiguo: clase que acompaña a eg-btn-primario (g-d9f3de3) en la home y no existe ya
$have = wp_json_encode( wp_get_ability( 'elementor/global-classes-resource' )->execute( [] ) );
$d = get_post_meta( 6, '_elementor_data', true );
preg_match_all( '/\[((?:"[^"\]]*",?)*"g-d9f3de3"(?:,?"[^"\]]*")*)\]/', $d, $m );
$cand = [];
foreach ( $m[1] as $lst ) foreach ( explode( ',', str_replace( '"', '', $lst ) ) as $c ) if ( 0 === strpos( $c, 'g-' ) && false === strpos( $have, '"' . $c . '\\"' ) && false === strpos( $have, '\\"' . $c . '\\"' ) && false === strpos( $have, '"' . $c . '"' ) ) $cand[ $c ] = ( $cand[ $c ] ?? 0 ) + 1;
arsort( $cand ); echo 'candidatos: ', json_encode( $cand ), "\n";
$old = array_key_first( $cand ); if ( ! $old ) { echo "sin id antiguo\n"; return; }
$css = json_decode( file_get_contents( '/tmp/eg-fixcls.json' ), true )[0]['css'];
$r = wp_get_ability( 'elementor/manage-classes' )->execute( [ 'operations' => [ [ 'action' => 'create', 'label' => 'eg-btn', 'css' => $css ] ] ] );
$new = $r['results'][0]['id'] ?? null; echo 'creada: ', json_encode( $r['results'][0] ?? $r ), "\n";
if ( ! $new ) return;
$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key='_elementor_data' AND meta_value LIKE %s", '%' . $wpdb->esc_like( '"' . $old . '"' ) . '%' ) );
foreach ( $rows as $x ) { update_post_meta( $x->post_id, '_elementor_data', wp_slash( str_replace( '"' . $old . '"', '"' . $new . '"', $x->meta_value ) ) ); delete_post_meta( $x->post_id, '_elementor_element_cache' ); }
echo 'documentos actualizados: ', count( $rows ), " ($old -> $new)\n";
\Elementor\Plugin::$instance->files_manager->clear_cache(); wp_cache_flush();
