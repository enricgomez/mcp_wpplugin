<?php
$p = get_page_by_path( 'ng-veterinaris', OBJECT, 'proyecto' ); $m = fn( $k ) => get_post_meta( $p->ID, $k, true );
echo $p->ID, ' | ', $m( 'titular' ), ' | ', $m( 'web' ), ' | ', $m( 'anio' ), ' | ', json_encode( $m( 'datos' ), JSON_UNESCAPED_UNICODE ), "\nRES: ", $m( 'resumen' ), "\nTXT: ", substr( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $p->post_content ) ), 0, 1500 ), "\n";
echo shell_exec( 'ls /var/www/vhosts | grep -i "miami\|taxi\|bowling\|clautic\|viatger"' );
