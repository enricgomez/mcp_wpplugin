<?php
$ids = get_posts(['post_type'=>'post','post_status'=>'any','numberposts'=>-1,'meta_key'=>'_eg_old_id','fields'=>'ids']);
foreach ($ids as $id) wp_delete_post($id, true); echo 'borrados: ', count($ids), "\n";
