<?php
$b = get_page_by_path( 'blog' ); if ( ! $b ) { $bid = wp_insert_post( [ 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Blog', 'post_name' => 'blog' ] ); } else $bid = $b->ID;
update_option( 'page_for_posts', $bid ); update_option( 'posts_per_page', 12 ); echo "blog page=$bid\n";
foreach ( [ '/tmp/eg-bs.json', '/tmp/eg-ba.json' ] as $CFGF ) { echo "== $CFGF\n"; include '/tmp/eg-tpl.php'; }
