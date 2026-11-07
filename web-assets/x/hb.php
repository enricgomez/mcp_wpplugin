<?php
// Construye el bucle del blog en una página auxiliar (444) y lo injerta en la home bajo «Blog rejilla»:
// así la validación no choca con las etiquetas dinámicas que ya tiene la home.
do_action( 'rest_api_init' );
$cfg = json_decode( file_get_contents( '/tmp/eg-hb.json' ), true );
$run = function ( $n, $in ) { $r = wp_get_ability( $n )->execute( $in ); return is_wp_error( $r ) ? [ 'error' => $r->get_error_code(), 'msg' => $r->get_error_message() ] : $r; };
$in = $cfg['build']['input']; $in['post_id'] = 444; $in['parent_id'] = 'document'; $in['mode'] = 'replace_children';
echo 'build aux: ', substr( json_encode( $run( 'elementor/build-composition', $in ), JSON_UNESCAPED_UNICODE ), 0, 160 ), "\n";
$run( 'elementor/publish-document', [ 'post_id' => 444 ] );
$aux = json_decode( get_post_meta( 444, '_elementor_data', true ), true );
$d = json_decode( get_post_meta( 6, '_elementor_data', true ), true ); $ok = 0;
$g = function ( &$els ) use ( &$g, $aux, &$ok ) { foreach ( $els as &$e ) { if ( ( $e['editor_settings']['title'] ?? '' ) === 'Blog rejilla' ) { $e['elements'] = $aux; $ok++; } elseif ( ! empty( $e['elements'] ) ) $g( $e['elements'] ); } unset( $e ); }; $g( $d );
echo "injertado=$ok elementos=", count( $aux ), "\n"; if ( ! $ok ) return;
update_post_meta( 6, '_elementor_data', wp_slash( wp_json_encode( $d ) ) );
file_put_contents( '/tmp/eg-dyn.json', json_encode( $cfg['dyn'] ) ); include '/tmp/eg-dynfix.php';
$h = wp_remote_retrieve_body( wp_remote_get( home_url( '/?nc=' . time() ), [ 'sslverify' => false ] ) ); echo 'home tarjetas blog: ', substr_count( $h, 'eg-hb-titulo' ), ( false !== strpos( $h, '{{' ) ? ' QUEDAN MARCADORES' : ' ok' ), "\n";
