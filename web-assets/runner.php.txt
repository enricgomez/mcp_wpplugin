<?php
// wp eval-file runner: ejecuta una ability con la entrada JSON de $args[0]
do_action( 'rest_api_init' );
$req = json_decode( file_get_contents( $args[0] ), true );
$out = [];
foreach ( ( isset( $req[0] ) ? $req : [ $req ] ) as $r ) {
	$a = wp_get_ability( $r['ability'] );
	if ( ! $a ) { $out[] = [ 'ability' => $r['ability'], 'error' => 'no existe' ]; continue; }
	$res = $a->execute( $r['input'] ?? null );
	if ( is_wp_error( $res ) ) { $res = [ 'error' => $res->get_error_code(), 'message' => $res->get_error_message(), 'data' => $res->get_error_data() ]; }
	$out[] = [ 'ability' => $r['ability'], 'result' => $res ];
}
$j = json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
file_put_contents( '/tmp/eg-last.json', $j );
$max = (int) ( $args[1] ?? 20000 );
echo strlen( $j ) > $max ? substr( $j, 0, $max ) . "\n…(cortado, " . strlen( $j ) . " bytes)" : $j;
