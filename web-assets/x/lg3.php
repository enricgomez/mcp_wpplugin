<?php
$CFGF = '/tmp/eg-l404.json'; include '/tmp/eg-tpl.php';
\ElementorPro\Plugin::instance()->modules_manager->get_modules( 'theme-builder' )->get_conditions_manager()->get_cache()->regenerate();
\Elementor\Plugin::$instance->files_manager->clear_cache();
$r = wp_remote_get( home_url( '/no-existe-xyz/?nc=' . time() ), [ 'sslverify' => false ] ); $h = wp_remote_retrieve_body( $r ); preg_match( '/<h1[^>]*>(.*?)<\/h1>/s', $h, $m ); echo '404 ', wp_remote_retrieve_response_code( $r ), ' h1=', strip_tags( $m[1] ?? 'NINGUNO' ), "\n";
