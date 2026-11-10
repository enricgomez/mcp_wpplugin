<?php
/* Plugin Name: EG llms.txt sin legales
 * Description: Quita las páginas legales del llms.txt de Rank Math. */
add_filter('rank_math/llms_txt/posts_query_args', function ($args) {
  $args['post__not_in'] = array_merge((array)($args['post__not_in'] ?? []), [1457,1458,1459,1460]);
  return $args;
});
