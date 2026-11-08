<?php
$dir = ABSPATH . 'wp-content/uploads';
foreach ( [ '5427e750884febf78237913beeec20fe', '45e44f888822f8f77a5936bcf2b56665', '60929726ce1c09caf7104ca33aa5d892', 'cd89e80bd21d3b60fbd86754f0ccf642', '39f8e32dbb4682a7692366be79da3657', 'f5737b341de2095493ad5f91445ac69b' ] as $n ) {
	$o = trim( shell_exec( 'find ' . escapeshellarg( ABSPATH ) . ' -name ' . escapeshellarg( $n . '*' ) . ' -not -path "*/cache/*" 2>/dev/null | head -5' ) );
	foreach ( array_filter( explode( "\n", $o ) ) as $f ) { $s = @getimagesize( $f ); echo "$n $f {$s[0]}x{$s[1]}\n"; }
	if ( ! $o ) echo "$n -\n";
}
