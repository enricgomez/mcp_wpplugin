<?php
// Limpia el contenido importado: titulares sin contenido debajo y restos «Lorem ipsum». Copia en _eg_content_bak.
foreach ( get_posts( [ 'post_type' => 'proyecto', 'numberposts' => -1, 'meta_key' => '_eg_old_id' ] ) as $p ) {
	$html = $p->post_content; if ( ! get_post_meta( $p->ID, '_eg_content_bak', true ) ) update_post_meta( $p->ID, '_eg_content_bak', wp_slash( $html ) );
	$doc = new DOMDocument(); libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8"?><div id="r">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
	$r = $doc->getElementById( 'r' ); $n0 = 0;
	foreach ( iterator_to_array( $r->childNodes ) as $c ) if ( $c->nodeType === XML_TEXT_NODE && ! trim( $c->textContent ) ) $r->removeChild( $c );
	foreach ( iterator_to_array( $r->childNodes ) as $c ) if ( $c instanceof DOMElement && ( stripos( $c->textContent, 'lorem ipsum' ) !== false || ! trim( $c->textContent ) && ! $c->getElementsByTagName( 'img' )->length ) ) { $r->removeChild( $c ); $n0++; }
	do { $ch = false; $kids = array_values( array_filter( iterator_to_array( $r->childNodes ), fn( $c ) => $c instanceof DOMElement ) );
		foreach ( $kids as $i => $c ) { $nx = $kids[ $i + 1 ] ?? null;
			if ( preg_match( '/^h[2-6]$/', $c->nodeName ) && ( ! $nx || preg_match( '/^h[2-6]$/', $nx->nodeName ) && $nx->nodeName <= $c->nodeName ) ) { $r->removeChild( $c ); $n0++; $ch = true; break; } } } while ( $ch );
	$out = ''; foreach ( $r->childNodes as $c ) $out .= $doc->saveHTML( $c );
	if ( $out !== $html ) wp_update_post( [ 'ID' => $p->ID, 'post_content' => $out ] );
	echo $p->post_name, ": -$n0\n";
}
