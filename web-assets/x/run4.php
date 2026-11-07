<?php echo json_encode(wp_get_attachment_metadata(30)?array_keys(wp_get_attachment_metadata(30)["sizes"]):"no"), get_post_mime_type(30), "\n"; include "/tmp/eg-hb.php";
