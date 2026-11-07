<?php
update_option( 'date_format', 'j \d\e F \d\e Y' ); update_option( 'category_base', 'blog/categoria' );
$cid = []; foreach ( [ 'web', 'ia', 'consultoria', 'sistemas' ] as $s ) $cid[ $s ] = get_term_by( 'slug', $s, 'category' )->term_id;
$n = [ 'web' => 0, 'ia' => 0, 'consultoria' => 0, 'sistemas' => 0 ]; $h2 = 0;
foreach ( get_posts( [ 'post_type' => 'post', 'numberposts' => -1, 'meta_key' => '_eg_old_id' ] ) as $p ) {
	$t = mb_strtolower( $p->post_title . ' ' . get_post_meta( $p->ID, 'rank_math_focus_keyword', true ) ); $oc = explode( ',', get_post_meta( $p->ID, '_eg_old_cats', true ) );
	if ( preg_match( '/inteligencia artificial|\bia\b|chatgpt|\bllm|gemini|openai|copilot|perplexity/u', $t ) ) $c = 'ia';
	elseif ( preg_match( '/woocommerce|wordpress|\bweb|seo\b|google|url|can[oó]nic|hosting|dominio|tienda|ecommerce|e-commerce|plugin|elementor|redsys|ssl|https|php|css|html|accesib|im[aá]gen|verifactu|velocidad|copias? de seguridad|malware|hack/u', $t ) || array_intersect( $oc, [ 'wordpress', 'woocommerce', 'diseno-web', 'ecommerce', 'hosting-dominios', 'seguridad', 'posicionamiento-web', 'seo', 'diseno-grafico' ] ) ) $c = 'web';
	elseif ( array_intersect( $oc, [ 'software', 'music-audio', 'impresion' ] ) ) $c = 'sistemas';
	else $c = 'consultoria';
	wp_set_post_categories( $p->ID, [ $cid[ $c ] ] ); $n[ $c ]++;
	// primer titular igual al título del post: fuera (ya está en el h1)
	$cnt = $p->post_content;
	if ( preg_match( '/^\s*<h[1-3][^>]*>(.*?)<\/h[1-3]>\s*/s', $cnt, $m ) && mb_strtolower( trim( wp_strip_all_tags( $m[1] ) ) ) === mb_strtolower( trim( $p->post_title ) ) ) { $cnt = substr( $cnt, strlen( $m[0] ) ); wp_update_post( [ 'ID' => $p->ID, 'post_content' => wp_slash( $cnt ) ] ); $h2++; }
}
flush_rewrite_rules( false );
echo json_encode( $n ), " | titulares duplicados quitados: $h2\n";
