<?php
echo json_encode( get_option( 'rank_math_modules' ) ), "\n";
echo 'helper: ', \RankMath\Helper::is_module_active( 'redirections' ) ? 'activo' : 'inactivo', "\n";
global $wpdb; print_r( $wpdb->get_row( "SELECT * FROM {$wpdb->prefix}rank_math_redirections WHERE sources LIKE '%quien-somos%'", ARRAY_A ) );
if ( class_exists( '\RankMath\Redirections\DB' ) ) { $m = \RankMath\Redirections\DB::match_redirections( 'quien-somos' ); echo 'match: ', json_encode( $m ), "\n"; }
$o = get_option( 'rank-math-options-general' ); echo 'opts redir: ', json_encode( array_intersect_key( (array) $o, array_flip( [ 'redirections_debug', 'redirections_fallback', 'redirections_post_redirect', 'redirections_header_code' ] ) ) ), "\n";
