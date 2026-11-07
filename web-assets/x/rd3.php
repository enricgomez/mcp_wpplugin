<?php
$m = rank_math()->manager; $mod = $m ? $m->get_module( 'redirections' ) : null;
echo 'mod: ', $mod ? get_class( $mod ) : 'null', "\n";
if ( $mod ) { foreach ( [ 'is_active', 'is_disabled', 'is_hidden' ] as $f ) if ( method_exists( $mod, $f ) ) echo "$f=", var_export( $mod->$f(), true ), "\n"; echo 'args: ', json_encode( array_diff_key( (array) $mod->get( 'args' ) ?? [], [] ) ), "\n"; }
\RankMath\Helper::update_modules( [ 'redirections' => 'on' ] );
echo 'tras update: ', json_encode( get_option( 'rank_math_modules' ) ), "\n";
