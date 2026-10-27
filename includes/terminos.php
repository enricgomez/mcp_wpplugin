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
			$done = wp_update_term( $term->term_id, $taxonomy, $args );
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
