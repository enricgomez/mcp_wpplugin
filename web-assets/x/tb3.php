<?php
foreach ( [ "/tmp/eg-ba.json" ] as $CFGF ) { include "/tmp/eg-tpl.php"; }
echo get_term_link( get_term_by( "slug", "ia", "category" ) ), "\n";
