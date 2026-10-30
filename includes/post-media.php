<?php
/**
 * Safe, targeted post-media operations for the EG blog.
 * Does not re-save post content or alter WPML translation jobs.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

add_filter( 'eg_pub_modules', function ( $modules ) {
    $modules['post_media'] = array( 'type' => 'blog', 'routes' => array( 'post-media' ) );
    return $modules;
} );

add_action( 'rest_api_init', function () {
    if ( ! eg_pub_module_enabled( 'post_media' ) ) { return; }
    register_rest_route( 'eg/v1', '/post-media', array(
        'methods' => 'POST',
        'callback' => 'eg_pub_post_media_handle',
        'permission_callback' => 'eg_pub_permission',
    ) );
} );

function eg_pub_post_media_handle( WP_REST_Request $request ) {
    $post_id = absint( $request->get_param( 'post_id' ) );
    $attachment_id = absint( $request->get_param( 'attachment_id' ) );
    $content_id = absint( $request->get_param( 'content_attachment_id' ) );
    $dry_run = (bool) $request->get_param( 'dry_run' );
    $post = get_post( $post_id );
    if ( ! $post || 'post' !== $post->post_type ) {
        return new WP_Error( 'eg_post_not_found', 'La entrada no existe.', array( 'status' => 404 ) );
    }
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return new WP_Error( 'eg_forbidden', 'Sin permisos para editar esta entrada.', array( 'status' => 403 ) );
    }
    if ( ! $attachment_id && ! $content_id ) {
        return new WP_Error( 'eg_invalid_image', 'Indica attachment_id (imagen destacada) o content_attachment_id (imagen del contenido).', array( 'status' => 400 ) );
    }
    foreach ( array_filter( array( $attachment_id, $content_id ) ) as $id ) {
        $attachment = get_post( $id );
        if ( ! $attachment || 'attachment' !== $attachment->post_type || ! wp_attachment_is_image( $id ) ) {
            return new WP_Error( 'eg_invalid_image', 'El ID ' . $id . ' no corresponde a una imagen de la biblioteca.', array( 'status' => 400 ) );
        }
    }
    $previous_id = (int) get_post_thumbnail_id( $post_id );
    if ( $attachment_id && ! $dry_run && $previous_id !== $attachment_id ) {
        if ( ! set_post_thumbnail( $post_id, $attachment_id ) ) {
            return new WP_Error( 'eg_thumbnail_failed', 'No se ha podido asignar la imagen.', array( 'status' => 500 ) );
        }
        clean_post_cache( $post_id );
    }
    $current_id = ( $dry_run || ! $attachment_id ) ? $previous_id : (int) get_post_thumbnail_id( $post_id );
    $language = apply_filters( 'wpml_post_language_details', null, $post_id );
    $out = array(
        'ok' => true,
        'dry_run' => $dry_run,
        'post_id' => $post_id,
        'lang' => is_array( $language ) ? ( $language['language_code'] ?? '' ) : '',
        'status' => get_post_status( $post_id ),
        'previous_attachment_id' => $previous_id,
        'attachment_id' => $current_id,
        'requested_attachment_id' => $attachment_id,
        'image_url' => $attachment_id ? wp_get_attachment_url( $attachment_id ) : wp_get_attachment_url( $current_id ),
        'url' => get_permalink( $post_id ),
        'wpml_translation_job_changed' => false,
        'note' => 'La imagen destacada se asigna sin modificar contenido, traducciones ni trabajos del editor de WPML.',
    );
    if ( $content_id ) {
        $swap = eg_pub_post_media_swap_content( $post, $content_id, $dry_run );
        if ( is_wp_error( $swap ) ) {
            return $swap;
        }
        $out += $swap;
        $out['note'] = 'La imagen del contenido se cambia solo en esta entrada (conserva su texto alternativo); las traducciones se cambian una a una.';
    }
    return rest_ensure_response( $out );
}

/**
 * Cambia la imagen del contenido (la del marcador eg:imagen-contenido, guardada en _eg_content_image_id)
 * por otra de la biblioteca: mismo bloque, mismo alt, solo cambian el ID y la URL.
 */
function eg_pub_post_media_swap_content( WP_Post $post, $new_id, $dry_run ) {
    $old_id = (int) get_post_meta( $post->ID, '_eg_content_image_id', true );
    $result = array( 'previous_content_attachment_id' => $old_id, 'content_attachment_id' => $old_id );
    if ( ! $old_id ) {
        return new WP_Error( 'eg_no_content_image', 'Esta entrada no tiene imagen del contenido puesta por EG Publicación.', array( 'status' => 409 ) );
    }
    if ( $old_id === $new_id ) {
        return $result;
    }
    $content = $post->post_content;
    $pattern = '#<!--\s*wp:image\s+\{[^}]*"id":' . $old_id . '\b.*?<!--\s*/wp:image\s*-->#s';
    if ( ! preg_match( $pattern, $content, $m ) ) {
        return new WP_Error( 'eg_content_image_missing', 'No se encuentra el bloque de la imagen ' . $old_id . ' en el contenido.', array( 'status' => 409 ) );
    }
    $old_src = (string) wp_get_attachment_image_url( $old_id, 'large' );
    $new_src = (string) wp_get_attachment_image_url( $new_id, 'large' );
    $block = preg_replace( '/"id":' . $old_id . '\b/', '"id":' . $new_id, $m[0], 1 );
    $block = str_replace( 'wp-image-' . $old_id, 'wp-image-' . $new_id, $block );
    $block = preg_replace( '/(<img[^>]*\ssrc=")[^"]*(")/', '${1}' . esc_url( $new_src ) . '${2}', $block, 1 );
    $result['content_attachment_id'] = $new_id;
    $result['content_image_url'] = $new_src;
    $result['previous_content_image_url'] = $old_src;
    if ( $dry_run ) {
        return $result;
    }
    $saved = wp_update_post( wp_slash( array(
        'ID' => $post->ID,
        'post_content' => str_replace( $m[0], $block, $content ),
    ) ), true );
    if ( is_wp_error( $saved ) ) {
        return $saved;
    }
    update_post_meta( $post->ID, '_eg_content_image_id', $new_id );
    return $result;
}
