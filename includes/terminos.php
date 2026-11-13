<?php
/**
 * Tipología «Gestión de la web»: textos y SEO de categorías y otros términos públicos.
 *   POST /wp-json/eg/v1/term
 * Elige el término por taxonomy (product_cat por defecto) + slug o id. Los cambios van en set:
 *   name                 nombre visible de la categoría
 *   description          descripción (HTML permitido en entradas: p, h2, h3, ul, li, strong, a...)
 *   bottom_description   texto que va DEBAJO del listado de productos (mismo HTML). Se pinta solo tras el
 *                        listado de WooCommerce o del widget «Productos de archivo» de Elementor; si la plantilla
 *                        no lo muestra, el shortcode [eg_texto_categoria] lo coloca donde se ponga.
 *   seo_title, seo_description, focus_keyword   SEO del archivo de la categoría (Rank Math y/o Yoast)
 * Como /seo, por defecto solo previsualiza; apply:true guarda. No cambia el slug (las URLs no se mueven).
 * Vacía la caché del archivo en WP Rocket.
 *
 * Organizar categorías (sin slug ni id):
 *   list: true           lista los términos de la taxonomía en todos los idiomas (id, nombre, slug, entradas,
 *                        idioma y trid de WPML). Con posts: true añade las entradas (publicadas, programadas,
 *                        borradores) con fecha, idioma, trid y categorías.
 *   create: [{lang, name, slug, description, translation_of}]   crea términos; translation_of = id del término
 *                        original, para enlazarlo como traducción en WPML. Si ya existe ese slug, lo reutiliza.
 *   assign: {"ID entrada": [ids de término]}   cambia los términos de cada entrada (sustituye los que tenga).
 *   Sin apply:true solo previsualiza.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_META_TERM_BOTTOM = 'eg_pub_bottom_description';

add_filter( 'eg_pub_modules', function ( $modules ) {
	$modules['terminos'] = array( 'type' => 'gestion', 'routes' => array( 'term' ) );
	return $modules;
} );

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_module_enabled( 'terminos' ) ) {
		return;
	}
	register_rest_route( 'eg/v1', '/term', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_term_handle',
		'permission_callback' => 'eg_pub_permission',
	) );
} );

/** SEO del término: Rank Math en term meta; Yoast en la opción wpseo_taxonomy_meta. */
function eg_pub_term_seo_get( $term ) {
	$values = array( 'seo_title' => '', 'seo_description' => '', 'focus_keyword' => '' );
	if ( defined( 'RANK_MATH_VERSION' ) ) {
		$values['seo_title']       = (string) get_term_meta( $term->term_id, 'rank_math_title', true );
		$values['seo_description'] = (string) get_term_meta( $term->term_id, 'rank_math_description', true );
		$values['focus_keyword']   = (string) get_term_meta( $term->term_id, 'rank_math_focus_keyword', true );
	} elseif ( defined( 'WPSEO_VERSION' ) ) {
		$all = get_option( 'wpseo_taxonomy_meta', array() );
		$row = $all[ $term->taxonomy ][ $term->term_id ] ?? array();
		$values['seo_title']       = (string) ( $row['wpseo_title'] ?? '' );
		$values['seo_description'] = (string) ( $row['wpseo_desc'] ?? '' );
		$values['focus_keyword']   = (string) ( $row['wpseo_focuskw'] ?? '' );
	}
	return $values;
}

function eg_pub_term_seo_save( $term, $values ) {
	if ( defined( 'RANK_MATH_VERSION' ) ) {
		$keys = array( 'seo_title' => 'rank_math_title', 'seo_description' => 'rank_math_description', 'focus_keyword' => 'rank_math_focus_keyword' );
		foreach ( $keys as $field => $key ) {
			if ( isset( $values[ $field ] ) ) {
				update_term_meta( $term->term_id, $key, $values[ $field ] );
			}
		}
	}
	if ( defined( 'WPSEO_VERSION' ) ) {
		$keys = array( 'seo_title' => 'wpseo_title', 'seo_description' => 'wpseo_desc', 'focus_keyword' => 'wpseo_focuskw' );
		$all  = get_option( 'wpseo_taxonomy_meta', array() );
		$all  = is_array( $all ) ? $all : array();
		foreach ( $keys as $field => $key ) {
			if ( isset( $values[ $field ] ) ) {
				$all[ $term->taxonomy ][ $term->term_id ][ $key ] = $values[ $field ];
			}
		}
		update_option( 'wpseo_taxonomy_meta', $all );
	}
}

function eg_pub_term_handle( WP_REST_Request $r ) {
	$p        = $r->get_params();
	$apply    = ! empty( $p['apply'] );
	$taxonomy = sanitize_key( $p['taxonomy'] ?? 'product_cat' );
	$tax      = get_taxonomy( $taxonomy );
	if ( ! $tax || ! $tax->public ) {
		return eg_pub_error( 'eg_taxonomy', 'Taxonomía no válida o no pública: ' . $taxonomy );
	}
	if ( ! current_user_can( $tax->cap->edit_terms ) ) {
		return eg_pub_error( 'eg_forbidden', 'El autor configurado no puede editar términos de ' . $taxonomy . '.', 403 );
	}
	if ( ! empty( $p['list'] ) ) {
		return eg_pub_term_list( $taxonomy, ! empty( $p['posts'] ) );
	}
	if ( ! empty( $p['create'] ) || ! empty( $p['assign'] ) ) {
		return eg_pub_term_organize( $taxonomy, (array) ( $p['create'] ?? array() ), (array) ( $p['assign'] ?? array() ), $apply );
	}
	$term = ! empty( $p['id'] ) ? get_term( (int) $p['id'], $taxonomy ) : get_term_by( 'slug', sanitize_title( (string) ( $p['slug'] ?? '' ) ), $taxonomy );
	if ( ! $term || is_wp_error( $term ) ) {
		return eg_pub_error( 'eg_term', 'No existe el término (indica slug o id) en ' . $taxonomy . '.', 404 );
	}

	$set     = (array) ( $p['set'] ?? array() );
	$changes = array();
	foreach ( array( 'name', 'description' ) as $field ) {
		if ( ! array_key_exists( $field, $set ) || ! is_scalar( $set[ $field ] ) ) {
			continue;
		}
		$new = 'name' === $field ? sanitize_text_field( (string) $set[ $field ] ) : wp_kses_post( (string) $set[ $field ] );
		if ( $new !== $term->$field ) {
			$changes[ $field ] = array( 'before' => $term->$field, 'after' => $new );
		}
	}
	if ( array_key_exists( 'bottom_description', $set ) && is_scalar( $set['bottom_description'] ) ) {
		$old = (string) get_term_meta( $term->term_id, EG_PUB_META_TERM_BOTTOM, true );
		$new = wp_kses_post( (string) $set['bottom_description'] );
		if ( $new !== $old ) {
			$changes['bottom_description'] = array( 'before' => $old, 'after' => $new );
		}
	}
	$seo = eg_pub_term_seo_get( $term );
	foreach ( $seo as $field => $old ) {
		if ( ! array_key_exists( $field, $set ) || ! is_scalar( $set[ $field ] ) ) {
			continue;
		}
		$new = sanitize_text_field( (string) $set[ $field ] );
		if ( $new !== $old ) {
			$changes[ $field ] = array( 'before' => $old, 'after' => $new );
			$seo[ $field ]     = $new;
		}
	}
	if ( isset( $changes['seo_title'] ) || isset( $changes['seo_description'] ) || isset( $changes['focus_keyword'] ) ) {
		if ( ! defined( 'RANK_MATH_VERSION' ) && ! defined( 'WPSEO_VERSION' ) ) {
			return eg_pub_error( 'eg_seo_plugin', 'No hay Yoast SEO ni Rank Math activos.' );
		}
	}

	if ( $apply && $changes ) {
		$args = array();
		foreach ( array( 'name', 'description' ) as $field ) {
			if ( isset( $changes[ $field ] ) ) {
				$args[ $field ] = $changes[ $field ]['after'];
			}
		}
		if ( $args ) {
			// Sin esto, wp_update_term pasa la descripción por wp_filter_kses y quita los h2/h3 a quien no tiene unfiltered_html.
			$kses = has_filter( 'pre_term_description', 'wp_filter_kses' );
			if ( $kses ) {
				remove_filter( 'pre_term_description', 'wp_filter_kses' );
			}
			// Con WPML, wp_update_term busca el slug duplicado con get_term_by, que WPML pasa al idioma actual:
			// en un término traducido encuentra el del idioma principal y falla con «el slug ya lo está utilizando otro término».
			$lang = apply_filters( 'wpml_element_language_code', null, array( 'element_id' => $term->term_taxonomy_id, 'element_type' => 'tax_' . $taxonomy ) );
			$prev = $lang ? apply_filters( 'wpml_current_language', null ) : null;
			if ( $lang && $prev !== $lang ) {
				do_action( 'wpml_switch_language', $lang );
			}
			$done = wp_update_term( $term->term_id, $taxonomy, $args );
			if ( $lang && $prev !== $lang ) {
				do_action( 'wpml_switch_language', $prev );
			}
			if ( $kses ) {
				add_filter( 'pre_term_description', 'wp_filter_kses' );
			}
			if ( is_wp_error( $done ) ) {
				return eg_pub_error( 'eg_term_save', $done->get_error_message() );
			}
		}
		if ( isset( $changes['bottom_description'] ) ) {
			// update_term_meta pasa por wp_unslash: se protege el HTML igual que en wp_update_term.
			update_term_meta( $term->term_id, EG_PUB_META_TERM_BOTTOM, wp_slash( $changes['bottom_description']['after'] ) );
		}
		eg_pub_term_seo_save( $term, array_map( function ( $c ) { return $c['after']; }, array_intersect_key( $changes, $seo ) ) );
		if ( function_exists( 'rocket_clean_term' ) ) {
			rocket_clean_term( $term->term_id, $taxonomy );
		}
		$term = get_term( $term->term_id, $taxonomy );
	}

	return new WP_REST_Response( array(
		'ok'          => true,
		'applied'     => $apply,
		'id'          => $term->term_id,
		'taxonomy'    => $taxonomy,
		'slug'        => $term->slug,
		'link'        => get_term_link( $term ),
		'name'        => $term->name,
		'description' => $term->description,
		'bottom_description' => (string) get_term_meta( $term->term_id, EG_PUB_META_TERM_BOTTOM, true ),
		'seo'         => $seo,
		'changes'     => $changes,
	), 200 );
}

/** Idioma de WPML de un término o una entrada ('' sin WPML). */
function eg_pub_term_wpml( $element_id, $element_type ) {
	if ( ! has_filter( 'wpml_element_language_details' ) ) {
		return array( 'lang' => '', 'trid' => 0 );
	}
	$d = apply_filters( 'wpml_element_language_details', null, array( 'element_id' => $element_id, 'element_type' => $element_type ) );
	return array( 'lang' => $d->language_code ?? '', 'trid' => (int) ( $d->trid ?? 0 ) );
}

/** Ejecuta $fn con WPML en el idioma $lang (o en todos con 'all') y vuelve al idioma anterior. */
function eg_pub_term_in_lang( $lang, $fn ) {
	$prev = $lang && has_filter( 'wpml_current_language' ) ? apply_filters( 'wpml_current_language', null ) : null;
	if ( $prev && $prev !== $lang ) {
		do_action( 'wpml_switch_language', $lang );
	}
	$out = $fn();
	if ( $prev && $prev !== $lang ) {
		do_action( 'wpml_switch_language', $prev );
	}
	return $out;
}

function eg_pub_term_list( $taxonomy, $with_posts ) {
	$terms = eg_pub_term_in_lang( 'all', function () use ( $taxonomy ) {
		return get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'number' => 500 ) );
	} );
	$rows = array();
	foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
		$rows[] = array(
			'id'    => $t->term_id,
			'name'  => $t->name,
			'slug'  => $t->slug,
			'count' => $t->count,
		) + eg_pub_term_wpml( $t->term_taxonomy_id, 'tax_' . $taxonomy );
	}
	$out = array( 'ok' => true, 'taxonomy' => $taxonomy, 'default' => 'category' === $taxonomy ? (int) get_option( 'default_category' ) : 0, 'terms' => $rows );
	if ( $with_posts ) {
		$types = get_taxonomy( $taxonomy )->object_type;
		$posts = get_posts( array(
			'post_type'        => $types,
			'post_status'      => array( 'publish', 'future', 'draft', 'pending', 'private' ),
			'numberposts'      => 300,
			'orderby'          => 'date',
			'order'            => 'DESC',
			'suppress_filters' => true,
		) );
		$out['posts'] = array();
		foreach ( $posts as $post ) {
			$out['posts'][] = array(
				'id'     => $post->ID,
				'title'  => $post->post_title,
				'status' => $post->post_status,
				'date'   => $post->post_date,
				'terms'  => wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) ),
			) + eg_pub_term_wpml( $post->ID, 'post_' . $post->post_type );
		}
	}
	return new WP_REST_Response( $out, 200 );
}

function eg_pub_term_organize( $taxonomy, $create, $assign, $apply ) {
	$created = array();
	$errors  = array();
	foreach ( $create as $i => $c ) {
		$c    = (array) $c;
		$name = sanitize_text_field( (string) ( $c['name'] ?? '' ) );
		$slug = sanitize_title( (string) ( $c['slug'] ?? $name ) );
		$lang = sanitize_key( (string) ( $c['lang'] ?? '' ) );
		if ( '' === $name ) {
			$errors[] = 'create[' . $i . ']: falta name.';
			continue;
		}
		$row = array( 'name' => $name, 'slug' => $slug, 'lang' => $lang );
		if ( ! $apply ) {
			$created[] = $row;
			continue;
		}
		$term = eg_pub_term_in_lang( $lang, function () use ( $slug, $taxonomy ) {
			return get_term_by( 'slug', $slug, $taxonomy );
		} );
		if ( ! $term ) {
			$res = eg_pub_term_in_lang( $lang, function () use ( $name, $slug, $taxonomy, $c ) {
				return wp_insert_term( $name, $taxonomy, array( 'slug' => $slug, 'description' => wp_kses_post( (string) ( $c['description'] ?? '' ) ) ) );
			} );
			if ( is_wp_error( $res ) ) {
				$errors[] = 'create[' . $i . '] ' . $slug . ': ' . $res->get_error_message();
				continue;
			}
			$term = get_term( (int) $res['term_id'], $taxonomy );
		}
		if ( $lang && has_action( 'wpml_set_element_language_details' ) ) {
			$trid     = false;
			$src_lang = null;
			$source   = ! empty( $c['translation_of'] ) ? get_term( (int) $c['translation_of'], $taxonomy ) : null;
			if ( $source && ! is_wp_error( $source ) ) {
				$src      = eg_pub_term_wpml( $source->term_taxonomy_id, 'tax_' . $taxonomy );
				$trid     = $src['trid'] ? $src['trid'] : false;
				$src_lang = $src['lang'] ? $src['lang'] : null;
			}
			do_action( 'wpml_set_element_language_details', array(
				'element_id'           => (int) $term->term_taxonomy_id,
				'element_type'         => 'tax_' . $taxonomy,
				'trid'                 => $trid,
				'language_code'        => $lang,
				'source_language_code' => $src_lang,
			) );
		}
		$created[] = array( 'id' => $term->term_id, 'link' => get_term_link( $term ) ) + $row;
	}

	$assigned = array();
	foreach ( $assign as $post_id => $ids ) {
		$post_id = (int) $post_id;
		$ids     = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
		$bad     = array_filter( $ids, function ( $id ) use ( $taxonomy ) {
			$t = get_term( $id, $taxonomy );
			return ! $t || is_wp_error( $t );
		} );
		if ( ! get_post( $post_id ) || ! current_user_can( 'edit_post', $post_id ) || ! $ids || $bad ) {
			$errors[] = 'assign ' . $post_id . ': entrada o términos no válidos.';
			continue;
		}
		$before = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
		if ( $apply ) {
			$done = wp_set_object_terms( $post_id, $ids, $taxonomy, false );
			if ( is_wp_error( $done ) ) {
				$errors[] = 'assign ' . $post_id . ': ' . $done->get_error_message();
				continue;
			}
			if ( function_exists( 'rocket_clean_post' ) ) {
				rocket_clean_post( $post_id );
			}
		}
		$assigned[] = array( 'id' => $post_id, 'before' => $before, 'after' => $ids );
	}
	return new WP_REST_Response( array(
		'ok'       => ! $errors,
		'applied'  => $apply,
		'created'  => $created,
		'assigned' => $assigned,
		'errors'   => $errors,
	), 200 );
}

/** Texto de debajo del listado del término que se está viendo ('' si no hay). Se pinta una sola vez por página. */
function eg_pub_term_bottom_html() {
	static $done = false;
	if ( $done || ! ( is_tax() || is_category() || is_tag() ) ) {
		return '';
	}
	$term = get_queried_object();
	$text = $term instanceof WP_Term ? (string) get_term_meta( $term->term_id, EG_PUB_META_TERM_BOTTOM, true ) : '';
	if ( '' === trim( $text ) ) {
		return '';
	}
	$done = true;
	return '<div class="eg-term-bottom term-description">' . wp_kses_post( $text ) . '</div>';
}

// Tras el listado: WooCommerce clásico y el widget «Productos de archivo» de Elementor Pro (paginado) lanzan esta acción.
add_action( 'woocommerce_after_shop_loop', function () {
	echo eg_pub_term_bottom_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- ya pasa por wp_kses_post.
}, 20 );

add_shortcode( 'eg_texto_categoria', 'eg_pub_term_bottom_html' );

// Campo «Texto debajo del listado» en la pantalla de editar categoría, para cambiarlo también desde wp-admin.
add_action( 'admin_init', function () {
	foreach ( get_taxonomies( array( 'public' => true ) ) as $taxonomy ) {
		add_action( $taxonomy . '_edit_form_fields', 'eg_pub_term_bottom_field', 20 );
		add_action( 'edited_' . $taxonomy, 'eg_pub_term_bottom_field_save' );
	}
} );

function eg_pub_term_bottom_field( $term ) {
	?>
	<tr class="form-field">
		<th scope="row"><label for="eg_pub_bottom_description">Texto debajo del listado</label></th>
		<td>
			<?php
			wp_nonce_field( 'eg_pub_term_bottom', 'eg_pub_term_bottom_nonce' );
			wp_editor( (string) get_term_meta( $term->term_id, EG_PUB_META_TERM_BOTTOM, true ), 'eg_pub_bottom_description', array(
				'textarea_name' => 'eg_pub_bottom_description',
				'textarea_rows' => 12,
				'media_buttons' => false,
			) );
			?>
			<p class="description">EG Publicación: se muestra debajo de los productos de esta categoría. Las preguntas como «h3» seguidas de un párrafo, bajo un «h2» de preguntas frecuentes, salen también como schema FAQ.</p>
		</td>
	</tr>
	<?php
}

function eg_pub_term_bottom_field_save( $term_id ) {
	if ( ! isset( $_POST['eg_pub_term_bottom_nonce'], $_POST['eg_pub_bottom_description'] )
		|| ! wp_verify_nonce( sanitize_key( $_POST['eg_pub_term_bottom_nonce'] ), 'eg_pub_term_bottom' )
		|| ! current_user_can( 'edit_term', $term_id ) ) {
		return;
	}
	$text = wp_kses_post( wp_unslash( $_POST['eg_pub_bottom_description'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	if ( '' === trim( $text ) ) {
		delete_term_meta( $term_id, EG_PUB_META_TERM_BOTTOM );
	} else {
		update_term_meta( $term_id, EG_PUB_META_TERM_BOTTOM, wp_slash( $text ) );
	}
}

/**
 * Preguntas del texto de debajo: cada h3 seguido de su respuesta, desde el h2 de preguntas frecuentes
 * (o FAQ) hasta el siguiente h2. Devuelve array( array( pregunta, respuesta ) ).
 */
function eg_pub_term_faqs( $html ) {
	if ( ! preg_match( '#<h2[^>]*>[^<]*(preguntas|preguntes|faq|questions)[^<]*</h2>(.*?)(?=<h2|$)#is', $html, $m ) ) {
		return array();
	}
	preg_match_all( '#<h3[^>]*>(.*?)</h3>(.*?)(?=<h3|$)#is', $m[2], $qa, PREG_SET_ORDER );
	$faqs = array();
	foreach ( $qa as $item ) {
		$q = trim( wp_strip_all_tags( $item[1] ) );
		$a = trim( wp_strip_all_tags( $item[2] ) );
		if ( '' !== $q && '' !== $a ) {
			$faqs[] = array( $q, $a );
		}
	}
	return $faqs;
}

// Schema FAQPage en el archivo de la categoría con las preguntas del texto de debajo.
add_action( 'wp_head', function () {
	if ( ! ( is_tax() || is_category() || is_tag() ) || is_paged() ) {
		return;
	}
	$term = get_queried_object();
	$faqs = $term instanceof WP_Term ? eg_pub_term_faqs( (string) get_term_meta( $term->term_id, EG_PUB_META_TERM_BOTTOM, true ) ) : array();
	if ( ! $faqs ) {
		return;
	}
	$schema = array(
		'@context'   => 'https://schema.org',
		'@type'      => 'FAQPage',
		'mainEntity' => array_map( function ( $f ) {
			return array(
				'@type'          => 'Question',
				'name'           => $f[0],
				'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $f[1] ),
			);
		}, $faqs ),
	);
	echo '<script type="application/ld+json" class="eg-term-faq">' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "</script>\n";
} );
