<?php
/**
 * Publicación de posts desde Make: endpoint /wp-json/eg/v1/publish, ajustes y avisos por email.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_OPTION_KEY  = 'eg_pub_api_key';
const EG_PUB_OPTION_USER = 'eg_pub_author_id';
const EG_PUB_META_NOTION = '_eg_notion_id';
const EG_PUB_OPTION_MAIL = 'eg_pub_notify_email';
const EG_PUB_DEFAULT_MAIL = 'enric@enricgomez.com';

function eg_pub_notify_email() {
	$mail = get_option( EG_PUB_OPTION_MAIL, null );
	return null === $mail ? EG_PUB_DEFAULT_MAIL : (string) $mail;
}

/* ======================================================================
 * Clave de acceso: se genera sola la primera vez.
 * ==================================================================== */
function eg_pub_get_key() {
	$key = get_option( EG_PUB_OPTION_KEY );
	if ( ! $key ) {
		$key = wp_generate_password( 48, false, false );
		update_option( EG_PUB_OPTION_KEY, $key, false );
	}
	return $key;
}

/* ======================================================================
 * Página de ajustes (solo administradores): muestra la clave y el autor.
 * ==================================================================== */
add_action( 'admin_menu', function () {
	add_options_page( 'EG Publicación', 'EG Publicación', 'manage_options', 'eg-publicacion', 'eg_pub_settings_page' );
} );

function eg_pub_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( isset( $_POST['eg_pub_nonce'] ) && wp_verify_nonce( $_POST['eg_pub_nonce'], 'eg_pub_save' ) ) {
		if ( ! empty( $_POST['eg_pub_regenerate'] ) ) {
			delete_option( EG_PUB_OPTION_KEY );
		}
		if ( isset( $_POST['eg_pub_author'] ) ) {
			update_option( EG_PUB_OPTION_USER, max( 0, (int) $_POST['eg_pub_author'] ), false );
		}
		if ( isset( $_POST['eg_pub_author'] ) ) {
			eg_pub_save_types( isset( $_POST['eg_pub_types'] ) ? (array) wp_unslash( $_POST['eg_pub_types'] ) : array() );
			if ( (bool) get_option( EG_PUB_OPTION_HOLD, false ) !== ! empty( $_POST['eg_pub_hold'] ) ) {
				update_option( EG_PUB_OPTION_HOLD, ! empty( $_POST['eg_pub_hold'] ), false );
				delete_site_transient( 'update_plugins' ); // Que WordPress vuelva a mirar si ofrece la versión nueva.
			}
		}
		if ( isset( $_POST['eg_pub_mail'] ) ) {
			$mails = array_filter( array_map( 'sanitize_email', array_map( 'trim', explode( ',', wp_unslash( $_POST['eg_pub_mail'] ) ) ) ) );
			update_option( EG_PUB_OPTION_MAIL, implode( ', ', $mails ), false );
		}
		echo '<div class="updated"><p>Guardado.</p></div>';
		if ( ! empty( $_POST['eg_pub_test'] ) ) {
			$sent = eg_pub_send_mail(
				'Prueba de aviso de publicación – ' . get_bloginfo( 'name' ),
				"Este es un email de prueba del plugin EG Publicación.\n\nSi lo recibes, los avisos de posts publicados llegarán a esta dirección.\n\n" . home_url( '/' )
			);
			echo $sent
				? '<div class="updated"><p>Email de prueba enviado. Revisa también la carpeta de spam.</p></div>'
				: '<div class="error"><p>WordPress no ha podido enviar el email. Hay que configurar el envío de correo (SMTP) de esta web.</p></div>';
		}
	}
	$key    = eg_pub_get_key();
	$author = (int) get_option( EG_PUB_OPTION_USER );
	$mail   = eg_pub_notify_email();
	?>
	<div class="wrap">
		<h1>EG Publicación (Make)</h1>
		<p>Datos para los escenarios de Make de esta web.</p>
		<table class="form-table">
			<tr>
				<th>Endpoints</th>
				<td><code><?php echo esc_html( rest_url( 'eg/v1/' ) ); ?></code> + la ruta de cada tipología activa (abajo)</td>
			</tr>
			<tr>
				<th>Nombre de la cabecera</th>
				<td><code>X-EG-Key</code></td>
			</tr>
			<tr>
				<th>Clave</th>
				<td><input type="text" readonly class="regular-text code" style="width:32em" value="<?php echo esc_attr( $key ); ?>" onclick="this.select()"></td>
			</tr>
		</table>
		<form method="post">
			<?php wp_nonce_field( 'eg_pub_save', 'eg_pub_nonce' ); ?>
			<table class="form-table">
				<tr>
					<th>Autor de los posts</th>
					<td>
						<?php
						wp_dropdown_users( array(
							'name'             => 'eg_pub_author',
							'selected'         => $author,
							'role__in'         => array( 'administrator', 'editor', 'author' ),
							'show_option_none' => '— Primer administrador —',
						) );
						?>
					</td>
				</tr>
				<tr>
					<th>Avisar por email cuando se publique un post</th>
					<td>
						<input type="text" name="eg_pub_mail" class="regular-text" value="<?php echo esc_attr( $mail ); ?>">
						<p class="description">Un email por post (con los enlaces de todos los idiomas) cuando un post programado desde Make pasa a publicado. Varias direcciones separadas por comas. Vacío = sin avisos.</p>
						<label><input type="checkbox" name="eg_pub_test" value="1"> Enviar un email de prueba al guardar</label>
					</td>
				</tr>
				<tr>
					<th>Qué hace en esta web</th>
					<td>
						<?php
						$eg_pub_routes = array();
						foreach ( eg_pub_modules() as $module ) {
							$eg_pub_routes[ $module['type'] ] = array_merge( $eg_pub_routes[ $module['type'] ] ?? array(), (array) $module['routes'] );
						}
						foreach ( eg_pub_types() as $slug => $type ) :
							$eg_pub_missing = ! empty( $type['requires'] ) && ! defined( $type['requires'] );
							?>
							<label style="display:block;margin-bottom:8px<?php echo $eg_pub_missing ? ';opacity:.6' : ''; ?>">
								<input type="checkbox" name="eg_pub_types[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( eg_pub_type_enabled( $slug ) ); ?> <?php disabled( $eg_pub_missing && ! eg_pub_type_enabled( $slug ) ); ?>>
								<strong><?php echo esc_html( $type['label'] ); ?></strong>: <?php echo esc_html( $type['description'] ); ?>
								<?php foreach ( $eg_pub_routes[ $slug ] ?? array() as $route ) : ?>
									<code>/<?php echo esc_html( $route ); ?></code>
								<?php endforeach; ?>
								<?php echo $eg_pub_missing ? '<em>(' . esc_html( $type['label'] ) . ' no está activo en esta web)</em>' : ''; ?>
							</label>
						<?php endforeach; ?>
						<p class="description">Todas usan la misma clave. Lo que está apagado no responde (404) ni aparece como ability.</p>
					</td>
				</tr>
				<tr>
					<th>Actualizaciones</th>
					<td>
						<label><input type="checkbox" name="eg_pub_hold" value="1" <?php checked( eg_pub_update_held() ); ?> <?php disabled( defined( 'EG_PUB_HOLD_VERSION' ) ); ?>> Mantener esta web en la versión <?php echo esc_html( EG_PUB_VERSION ); ?> (no actualizar sola)</label>
						<?php $eg_pub_latest = eg_pub_gh_release(); ?>
						<p class="description">
							<?php echo $eg_pub_latest ? 'Última versión publicada: ' . esc_html( $eg_pub_latest['version'] ) . '. ' : ''; ?>
							<?php echo defined( 'EG_PUB_HOLD_VERSION' ) ? 'Fijado en wp-config.php con EG_PUB_HOLD_VERSION.' : 'Desmárcalo para volver a recibir las versiones nuevas.'; ?>
						</p>
					</td>
				</tr>
				<tr>
					<th>Regenerar clave</th>
					<td><label><input type="checkbox" name="eg_pub_regenerate" value="1"> Crear una clave nueva (habrá que actualizarla en Make)</label></td>
				</tr>
			</table>
			<?php submit_button( 'Guardar' ); ?>
		</form>
	</div>
	<?php
}

/* ======================================================================
 * Endpoint
 * ==================================================================== */
add_action( 'rest_api_init', function () {
	if ( ! eg_pub_module_enabled( 'blog' ) ) {
		return;
	}
	register_rest_route( 'eg/v1', '/publish', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_handle',
		'permission_callback' => 'eg_pub_permission',
	) );
} );

function eg_pub_permission( WP_REST_Request $request ) {
	$sent = (string) $request->get_header( 'x_eg_key' );
	if ( '' === $sent || ! hash_equals( eg_pub_get_key(), $sent ) ) {
		return new WP_Error( 'eg_auth', 'Clave X-EG-Key incorrecta.', array( 'status' => 401 ) );
	}
	// Actuar como el autor configurado (o el primer administrador).
	$user_id = max( 0, (int) get_option( EG_PUB_OPTION_USER ) );
	if ( ! $user_id || ! get_userdata( $user_id ) ) {
		$admins  = get_users( array( 'role' => 'administrator', 'number' => 1, 'orderby' => 'ID', 'fields' => 'ID' ) );
		$user_id = $admins ? (int) $admins[0] : 0;
	}
	if ( ! $user_id ) {
		return new WP_Error( 'eg_user', 'No hay ningún usuario autor configurado.', array( 'status' => 500 ) );
	}
	wp_set_current_user( $user_id );
	return current_user_can( 'publish_posts' );
}

function eg_pub_error( $code, $message, $status = 400 ) {
	return new WP_REST_Response( array( 'ok' => false, 'code' => $code, 'message' => $message ), $status );
}

function eg_pub_find_by_notion( $notion_id ) {
	$notion_id = sanitize_text_field( $notion_id );
	if ( '' === $notion_id ) {
		return 0;
	}
	$ids = get_posts( array(
		'post_type'        => 'post',
		'post_status'      => 'any',
		'meta_key'         => EG_PUB_META_NOTION,
		'meta_value'       => $notion_id,
		'fields'           => 'ids',
		'numberposts'      => 1,
		'suppress_filters' => true, // WPML: buscar en todos los idiomas.
	) );
	return $ids ? (int) $ids[0] : 0;
}

function eg_pub_handle( WP_REST_Request $r ) {
	$p = $r->get_params();

	$notion_id = isset( $p['notion_id'] ) ? sanitize_text_field( $p['notion_id'] ) : '';
	$lang      = isset( $p['lang'] ) ? sanitize_key( $p['lang'] ) : '';
	$title     = isset( $p['title'] ) ? wp_strip_all_tags( $p['title'] ) : '';
	$content   = isset( $p['content'] ) ? (string) $p['content'] : '';

	if ( '' === $notion_id || '' === $title || '' === trim( $content ) ) {
		return eg_pub_error( 'eg_missing', 'Faltan datos obligatorios: notion_id, title o content (¿el cuerpo de la ficha de Notion está vacío?).' );
	}

	$has_wpml = defined( 'ICL_SITEPRESS_VERSION' );

	// --- Traducción: localizar el original ---------------------------------
	$source_id = 0;
	if ( ! empty( $p['translation_of_notion'] ) ) {
		$source_id = eg_pub_find_by_notion( $p['translation_of_notion'] );
		if ( ! $source_id ) {
			return eg_pub_error( 'eg_source', 'El post original en castellano todavía no está en WordPress. Publica primero la ficha original.', 409 );
		}
	}

	// Categoría del original (antes de cambiar de idioma), para enlazar la traducción de la categoría.
	$source_cat_ttid = 0;
	if ( $source_id ) {
		$tt_ids          = wp_get_object_terms( $source_id, 'category', array( 'fields' => 'tt_ids' ) );
		$source_cat_ttid = ( ! is_wp_error( $tt_ids ) && $tt_ids ) ? (int) $tt_ids[0] : 0;
	}

	if ( $has_wpml && $lang ) {
		do_action( 'wpml_switch_language', $lang );
	}

	// --- Fecha y estado -----------------------------------------------------
	$tz        = wp_timezone();
	$date_raw  = isset( $p['date'] ) ? trim( (string) $p['date'] ) : '';
	// Preserve existing integrations: publishing remains the default unless explicitly overridden.
	$requested_status = isset( $p['status'] ) ? sanitize_key( $p['status'] ) : 'publish';
	if ( ! in_array( $requested_status, array( 'draft', 'pending', 'private', 'publish' ), true ) ) {
		return eg_pub_error( 'eg_status', 'Estado no válido: usa draft, pending, private o publish.' );
	}
	$status    = $requested_status;
	$post_date = null;
	if ( '' !== $date_raw ) {
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_raw ) ) {
			$date_raw .= ' 09:00:00'; // Solo fecha: publicar a las 9:00 hora de la web.
		}
		try {
			$dt        = new DateTimeImmutable( $date_raw, $tz );
			$dt        = $dt->setTimezone( $tz );
			$post_date = $dt->format( 'Y-m-d H:i:s' );
			// Only scheduled publications become future; drafts must stay drafts.
			if ( 'publish' === $requested_status ) {
				$status = ( $dt->getTimestamp() > time() ) ? 'future' : 'publish';
			}
		} catch ( Exception $e ) {
			return eg_pub_error( 'eg_date', 'Fecha de publicación no válida: ' . $date_raw );
		}
	}

	// --- Crear o actualizar -------------------------------------------------
	$existing = eg_pub_find_by_notion( $notion_id );
	$postarr  = array(
		'post_type'    => 'post',
		'post_title'   => $title,
		'post_content' => $content,
		'post_excerpt' => isset( $p['excerpt'] ) ? sanitize_textarea_field( $p['excerpt'] ) : '',
		'post_status'  => $status,
	);
	if ( ! empty( $p['slug'] ) ) {
		$postarr['post_name'] = sanitize_title( $p['slug'] );
	}
	if ( $post_date ) {
		$postarr['post_date']     = $post_date;
		$postarr['post_date_gmt'] = get_gmt_from_date( $post_date );
		$postarr['edit_date']     = true;
	}
	if ( $existing ) {
		$postarr['ID'] = $existing;
		$post_id       = wp_update_post( wp_slash( $postarr ), true );
	} else {
		$postarr['post_author'] = get_current_user_id();
		$post_id                = wp_insert_post( wp_slash( $postarr ), true );
	}
	if ( is_wp_error( $post_id ) ) {
		return eg_pub_error( 'eg_insert', 'WordPress no pudo guardar el post: ' . $post_id->get_error_message(), 500 );
	}
	update_post_meta( $post_id, EG_PUB_META_NOTION, $notion_id );

	// --- Idioma y traducción (WPML) ----------------------------------------
	if ( $has_wpml && $lang ) {
		$trid     = $source_id
			? apply_filters( 'wpml_element_trid', null, $source_id, 'post_post' )
			: apply_filters( 'wpml_element_trid', null, $post_id, 'post_post' );
		$src_lang = null;
		if ( $source_id ) {
			$src      = apply_filters( 'wpml_element_language_details', null, array( 'element_id' => $source_id, 'element_type' => 'post' ) );
			$src_lang = $src ? $src->language_code : null;
		}
		do_action( 'wpml_set_element_language_details', array(
			'element_id'           => $post_id,
			'element_type'         => 'post_post',
			'trid'                 => $trid ? $trid : false,
			'language_code'        => $lang,
			'source_language_code' => $src_lang,
		) );
	}

	// --- Categoría por slug (en el idioma del post) ------------------------
	if ( ! empty( $p['category_slug'] ) ) {
		$term = get_term_by( 'slug', sanitize_title( $p['category_slug'] ), 'category' );
		if ( ! $term && ! empty( $p['category_name'] ) ) {
			$term = eg_pub_create_category( sanitize_title( $p['category_slug'] ), sanitize_text_field( $p['category_name'] ), $has_wpml ? $lang : '', $source_cat_ttid );
		}
		if ( ! $term || is_wp_error( $term ) ) {
			$why = is_wp_error( $term ) ? ' (' . $term->get_error_message() . ')' : '';
			return eg_pub_error( 'eg_category', 'El post se ha guardado (ID ' . $post_id . '), pero no existe la categoría "' . $p['category_slug'] . '" en este idioma' . $why . '.' );
		}
		wp_set_post_terms( $post_id, array( (int) $term->term_id ), 'category', false );
	}

	// --- SEO del plugin activo (Yoast SEO o Rank Math) --------------------------
	eg_pub_seo_save( $post_id, $p );

	// --- Imagen destacada ---------------------------------------------------
	$notes   = array();
	$img_url = isset( $p['image_url'] ) ? trim( (string) $p['image_url'] ) : '';
	$img_b64 = isset( $p['image_base64'] ) ? trim( (string) $p['image_base64'] ) : '';
	if ( '' !== $img_url || '' !== $img_b64 ) {
		$att = eg_pub_sideload_image( $post_id, $img_url, $img_b64, array(
			'filename' => isset( $p['image_filename'] ) ? $p['image_filename'] : '',
			'title'    => isset( $p['image_title'] ) ? $p['image_title'] : '',
			'alt'      => isset( $p['image_alt'] ) ? $p['image_alt'] : '',
		) );
		if ( is_wp_error( $att ) ) {
			$notes[] = 'imagen destacada: ' . $att->get_error_message();
		} else {
			set_post_thumbnail( $post_id, $att );
		}
	} elseif ( $source_id && ! has_post_thumbnail( $post_id ) ) {
		$thumb = (int) get_post_thumbnail_id( $source_id );
		if ( $thumb ) {
			set_post_thumbnail( $post_id, $thumb );
		}
	}

	// --- Imagen dentro del contenido ----------------------------------------
	// El contenido puede llevar el marcador <!-- eg:imagen-contenido --> donde debe ir la imagen.
	$placeholder = '<!-- eg:imagen-contenido -->';
	$cimg_url    = isset( $p['content_image_url'] ) ? trim( (string) $p['content_image_url'] ) : '';
	$cimg_b64    = isset( $p['content_image_base64'] ) ? trim( (string) $p['content_image_base64'] ) : '';
	$cimg_alt    = isset( $p['content_image_alt'] ) ? sanitize_text_field( $p['content_image_alt'] ) : '';
	$cimg_id     = 0;
	if ( '' !== $cimg_url || '' !== $cimg_b64 ) {
		$c_att = eg_pub_sideload_image( $post_id, $cimg_url, $cimg_b64, array(
			'filename' => isset( $p['content_image_filename'] ) ? $p['content_image_filename'] : get_post_field( 'post_name', $post_id ) . '-2',
			'title'    => isset( $p['content_image_title'] ) ? $p['content_image_title'] : '',
			'alt'      => $cimg_alt,
		) );
		if ( is_wp_error( $c_att ) ) {
			$notes[] = 'imagen del contenido: ' . $c_att->get_error_message();
		} else {
			$cimg_id = (int) $c_att;
		}
	} elseif ( $source_id ) {
		// Traducción: reutilizar la imagen de contenido del original.
		$cimg_id = (int) get_post_meta( $source_id, '_eg_content_image_id', true );
	}
	if ( $cimg_id ) {
		update_post_meta( $post_id, '_eg_content_image_id', $cimg_id );
		if ( '' === $cimg_alt ) {
			$cimg_alt = (string) get_post_meta( $cimg_id, '_wp_attachment_image_alt', true );
		}
	}
	$current = get_post_field( 'post_content', $post_id, 'raw' );
	if ( false !== strpos( $current, $placeholder ) ) {
		$block = '';
		if ( $cimg_id ) {
			$src   = wp_get_attachment_image_url( $cimg_id, 'large' );
			$block = '<!-- wp:image {"id":' . $cimg_id . ',"sizeSlug":"large","linkDestination":"none"} -->' . "\n"
				. '<figure class="wp-block-image size-large"><img src="' . esc_url( $src ) . '" alt="' . esc_attr( $cimg_alt ) . '" class="wp-image-' . $cimg_id . '"/></figure>' . "\n"
				. '<!-- /wp:image -->';
		}
		wp_update_post( wp_slash( array(
			'ID'           => $post_id,
			'post_content' => str_replace( $placeholder, $block, $current ),
		) ) );
	}

	// --- Preguntas frecuentes en desplegables con schema FAQPage -----------
	if ( isset( $p['faq_css'] ) && is_string( $p['faq_css'] ) ) {
		update_option( EG_PUB_OPTION_FAQ_CSS, wp_strip_all_tags( $p['faq_css'] ), false );
	}
	$faq = eg_pub_faq_apply( $post_id, $p );
	if ( is_string( $faq ) ) {
		$notes[] = $faq;
	}

	$image_note = implode( ' | ', $notes );
	clean_post_cache( $post_id );
	return new WP_REST_Response( array(
		'ok'         => true,
		'id'         => $post_id,
		'status'     => get_post_status( $post_id ),
		'link'       => get_permalink( $post_id ),
		'lang'       => $lang,
		'image_note' => $image_note,
		'faqs'       => is_int( $faq ) ? $faq : 0,
		'message'    => '' === $image_note ? '' : 'Post guardado, pero falló: ' . $image_note,
	), 200 );
}

/**
 * Crea una categoría que no existe. Con WPML la asigna al idioma del post y,
 * si es una traducción, la enlaza con la categoría del post original.
 */
function eg_pub_create_category( $slug, $name, $lang, $source_ttid ) {
	$res = wp_insert_term( $name, 'category', array( 'slug' => $slug ) );
	if ( is_wp_error( $res ) ) {
		if ( 'term_exists' !== $res->get_error_code() ) {
			return $res;
		}
		$term_id = (int) $res->get_error_data( 'term_exists' );
	} else {
		$term_id = (int) $res['term_id'];
	}
	$term = get_term( $term_id, 'category' );
	if ( ! $term || is_wp_error( $term ) ) {
		return $term;
	}
	if ( $lang ) {
		$trid     = false;
		$src_lang = null;
		if ( $source_ttid ) {
			$trid = apply_filters( 'wpml_element_trid', null, $source_ttid, 'tax_category' );
			$src  = apply_filters( 'wpml_element_language_details', null, array( 'element_id' => $source_ttid, 'element_type' => 'category' ) );
			$src_lang = $src ? $src->language_code : null;
		}
		do_action( 'wpml_set_element_language_details', array(
			'element_id'           => (int) $term->term_taxonomy_id,
			'element_type'         => 'tax_category',
			'trid'                 => $trid ? $trid : false,
			'language_code'        => $lang,
			'source_language_code' => $src_lang,
		) );
	}
	return $term;
}

function eg_pub_sideload_image( $post_id, $url, $b64, $args ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	// Evitar duplicados en la biblioteca si se vuelve a publicar la misma imagen.
	$hash     = '' !== $url ? md5( strtok( $url, '?' ) ) : md5( $b64 );
	$existing = get_posts( array(
		'post_type'        => 'attachment',
		'post_status'      => 'inherit',
		'meta_key'         => '_eg_src_hash',
		'meta_value'       => $hash,
		'fields'           => 'ids',
		'numberposts'      => 1,
		'suppress_filters' => true,
	) );
	if ( $existing ) {
		$att = (int) $existing[0];
	} else {
		if ( '' !== $url ) {
			$tmp = download_url( esc_url_raw( $url ), 60 );
			if ( is_wp_error( $tmp ) ) {
				return new WP_Error( 'eg_img', 'no se pudo descargar (' . $tmp->get_error_message() . ')' );
			}
		} else {
			$data = base64_decode( preg_replace( '#^data:image/\w+;base64,#', '', $b64 ), true );
			if ( false === $data ) {
				return new WP_Error( 'eg_img', 'el base64 no es válido' );
			}
			$tmp = wp_tempnam( 'eg-image' );
			file_put_contents( $tmp, $data );
		}

		$mime = wp_get_image_mime( $tmp );
		$exts = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/avif' => 'avif' );
		if ( ! $mime || ! isset( $exts[ $mime ] ) ) {
			@unlink( $tmp );
			return new WP_Error( 'eg_img', 'el archivo no es una imagen válida' );
		}
		$base = ! empty( $args['filename'] ) ? $args['filename'] : get_post_field( 'post_name', $post_id );
		$base = sanitize_title( preg_replace( '/\.(jpe?g|png|webp|gif|avif)$/i', '', $base ) );

		$att = media_handle_sideload( array( 'name' => $base . '.' . $exts[ $mime ], 'tmp_name' => $tmp ), $post_id, ! empty( $args['title'] ) ? sanitize_text_field( $args['title'] ) : null );
		if ( is_wp_error( $att ) ) {
			@unlink( $tmp );
			return new WP_Error( 'eg_img', 'no se pudo subir (' . $att->get_error_message() . ')' );
		}
		update_post_meta( $att, '_eg_src_hash', $hash );
	}
	if ( ! empty( $args['alt'] ) ) {
		update_post_meta( $att, '_wp_attachment_image_alt', sanitize_text_field( $args['alt'] ) );
	}
	return $att;
}


/* ======================================================================
 * Aviso por email cuando un post programado desde Make se publica.
 * Un solo email por post: se dispara con el original (castellano) y
 * espera un par de minutos para que las traducciones también estén
 * publicadas e incluir sus enlaces.
 * ==================================================================== */
function eg_pub_send_mail( $subject, $body ) {
	$to = eg_pub_notify_email();
	if ( '' === trim( $to ) ) {
		return false;
	}
	return wp_mail( array_map( 'trim', explode( ',', $to ) ), $subject, $body );
}

add_action( 'transition_post_status', function ( $new, $old, $post ) {
	if ( 'publish' !== $new || 'future' !== $old || 'post' !== $post->post_type ) {
		return;
	}
	if ( ! get_post_meta( $post->ID, EG_PUB_META_NOTION, true ) ) {
		return; // Solo posts creados desde Make.
	}
	if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
		$details = apply_filters( 'wpml_element_language_details', null, array( 'element_id' => $post->ID, 'element_type' => 'post' ) );
		if ( $details && ! empty( $details->source_language_code ) ) {
			return; // Es una traducción: el aviso lo envía el original.
		}
	}
	if ( get_post_meta( $post->ID, '_eg_notified', true ) ) {
		return;
	}
	if ( ! wp_next_scheduled( 'eg_pub_notify_published', array( $post->ID ) ) ) {
		wp_schedule_single_event( time() + 120, 'eg_pub_notify_published', array( $post->ID ) );
	}
}, 10, 3 );

add_action( 'eg_pub_notify_published', function ( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post || 'publish' !== $post->post_status || get_post_meta( $post_id, '_eg_notified', true ) ) {
		return;
	}
	$lines = array();
	$lines[] = 'Se acaba de publicar en ' . get_bloginfo( 'name' ) . ':';
	$lines[] = '';

	$items = array( $post_id => '' );
	if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
		$trid  = apply_filters( 'wpml_element_trid', null, $post_id, 'post_post' );
		$trans = $trid ? apply_filters( 'wpml_get_element_translations', null, $trid, 'post_post' ) : array();
		$items = array();
		foreach ( (array) $trans as $code => $t ) {
			$items[ (int) $t->element_id ] = strtoupper( $code );
		}
		if ( ! isset( $items[ $post_id ] ) ) {
			$items = array( $post_id => '' ) + $items;
		}
	}
	foreach ( $items as $id => $code ) {
		$status  = get_post_status( $id );
		$lines[] = ( $code ? $code . ' – ' : '' ) . get_the_title( $id ) . ( 'publish' === $status ? '' : ' [estado: ' . $status . ']' );
		$lines[] = get_permalink( $id );
		$lines[] = '';
	}
	$lines[] = '—';
	$lines[] = 'Aviso automático del plugin EG Publicación (enricgomez Studio).';

	if ( eg_pub_send_mail( 'Publicado: ' . get_the_title( $post_id ) . ' – ' . get_bloginfo( 'name' ), implode( "\n", $lines ) ) ) {
		update_post_meta( $post_id, '_eg_notified', current_time( 'mysql' ) );
	}
} );
