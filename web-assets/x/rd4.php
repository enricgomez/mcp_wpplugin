<?php
foreach ( get_option( 'active_plugins' ) as $p ) echo $p, "\n";
echo 'RM ver: ', defined( 'RANK_MATH_VERSION' ) ? RANK_MATH_VERSION : '-', ' pro: ', defined( 'RANK_MATH_PRO_VERSION' ) ? RANK_MATH_PRO_VERSION : '-', "\n";
echo 'wizard: ', get_option( 'rank_math_wizard_completed' ) ? 'si' : 'no', ' modo: ', json_encode( get_option( 'rank_math_setup_mode' ) ), "\n";
