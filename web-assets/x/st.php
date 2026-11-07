<?php
echo 'blog_public=', get_option( 'blog_public' ), "\n";
echo 'imagify key: ', ( get_imagify_option( 'api_key' ) ?? '' ) ? 'SI' : 'NO', ' | opts: ', json_encode( array_intersect_key( (array) get_option( 'imagify_settings' ), array_flip( [ 'optimization_level', 'lossless', 'auto_optimize', 'convert_to_webp', 'convert_to_avif', 'optimization_format', 'display_nextgen', 'display_nextgen_method', 'resize_larger', 'resize_larger_w' ] ) ) ), "\n";
echo 'redis dropin: ', file_exists( WP_CONTENT_DIR . '/object-cache.php' ) ? 'si' : 'no', ' | conectado: ', wp_using_ext_object_cache() ? 'si' : 'no', "\n";
echo 'plugins instalados: ', implode( ', ', array_keys( get_plugins() ) ), "\n";
$t = get_option( 'rank-math-options-titles' ); echo 'rm titles: ', json_encode( array_intersect_key( (array) $t, array_flip( [ 'title_separator', 'knowledgegraph_type', 'knowledgegraph_name', 'knowledgegraph_logo', 'homepage_title', 'homepage_description', 'disable_author_archives', 'disable_date_archives', 'pt_proyecto_title', 'open_graph_image' ] ) ), JSON_UNESCAPED_UNICODE ), "\n";
echo 'home meta: ', get_option( 'show_on_front' ), ' ', get_option( 'page_on_front' ), ' ', get_option( 'page_for_posts' ), "\n";
