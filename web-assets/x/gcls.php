<?php
do_action( 'rest_api_init' );
$r = wp_get_ability( 'elementor/global-classes-resource' )->execute( [] );
$j = json_encode( $r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
echo 'len=', strlen( $j ), "\n";
foreach ( [ 'eg-btn"', 'eg-fila"', 'eg-hero-ctas"', 'eg-btn-primario"' ] as $l ) { $p = strpos( $j, '"' . $l ); echo $l, ' pos=', var_export( $p, true ), ' ', $p !== false ? substr( $j, max( 0, $p - 400 ), 900 ) : '', "\n\n"; }
