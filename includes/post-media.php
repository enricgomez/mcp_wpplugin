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
    $dry_run = (bool) $request->get_param( 'dry_run' );
    $post = get_post( $post_id );
    if ( ! $post || 'post' !== $post->post_type ) {
        return new WP_Error( 'eg_post_not_found', 'La entrada no existe.', array( 'status' => 404 ) );
    }
    if ( ! current_user_can( 'edit_post', $post_id ) ) {
        return new WP_Error( 'eg_forbidden', 'Sin permisos para editar esta entrada.', array( 'status' => 403 ) );
    }
    $attachment = get_post( $attachment_id );
    if ( ! $attachment || 'attachment' !== $attachment->post_type || ! wp_attachment_is_image( $attachment_id ) ) {
        return new WP_Error( 'eg_invalid_image', 'El ID no corresponde a una imagen de la biblioteca.', array( 'status' => 400 ) );
    }
    $previous_id = (int) get_post_thumbnail_id( $post_id );
    if ( ! $dry_run && $previous_id !== $attachment_id ) {
        if ( ! set_post_thumbnail( $post_id, $attachment_id ) ) {
            return new WP_Error( 'eg_thumbnail_failed', 'No se ha podido asignar la imagen.', array( 'status' => 500 ) );
        }
        clean_post_cache( $post_id );
    }
    $current_id = $dry_run ? $previous_id : (int) get_post_thumbnail_id( $post_id );
    $language = apply_filters( 'wpml_post_language_details', null, $post_id );
    return rest_ensure_response( array(
        'ok' => true,
        'dry_run' => $dry_run,
        'post_id' => $post_id,
        'lang' => is_array( $language ) ? ( $language['language_code'] ?? '' ) : '',
        'status' => get_post_status( $post_id ),
        'previous_attachment_id' => $previous_id,
        'attachment_id' => $current_id,
        'requested_attachment_id' => $attachment_id,
        'image_url' => wp_get_attachment_url( $attachment_id ),
        'url' => get_permalink( $post_id ),
        'wpml_translation_job_changed' => false,
        'note' => 'La imagen destacada se asigna sin modificar contenido, traducciones ni trabajos del editor de WPML.',
    ) );
}
