<?php
global $wpdb;
$old = maybe_unserialize($wpdb->get_var("SELECT option_value FROM l99US_options WHERE option_name='wp_rocket_settings'"));
$s = get_option('wp_rocket_settings', []); if (!is_array($s)) $s = [];
foreach (['consumer_key','consumer_email','secret_key'] as $k) if (!empty($old[$k])) $s[$k] = $old[$k];
$set = ['cache_mobile'=>1,'do_caching_mobile_files'=>0,'cache_logged_user'=>0,'purge_cron_interval'=>10,'purge_cron_unit'=>'HOUR_IN_SECONDS',
 'minify_css'=>1,'minify_js'=>1,'defer_all_js'=>1,'exclude_defer_js'=>[],'delay_js'=>0,'async_css'=>0,'remove_unused_css'=>0,
 'lazyload'=>1,'lazyload_iframes'=>1,'lazyload_youtube'=>1,'lazyload_css_bg_img'=>1,'image_dimensions'=>1,
 'manual_preload'=>1,'preload_links'=>1,'host_fonts_locally'=>1,'emoji'=>1,'embeds'=>1,'cdn'=>0,'control_heartbeat'=>1,'heartbeat_site_behavior'=>'reduce_periodicity','heartbeat_admin_behavior'=>'reduce_periodicity','heartbeat_editor_behavior'=>''];
foreach ($set as $k=>$v) $s[$k]=$v;
update_option('wp_rocket_settings', $s);
echo "key=", empty($s['consumer_key'])?'NO':'si', " email=", empty($s['consumer_email'])?'NO':'si', "\n";
