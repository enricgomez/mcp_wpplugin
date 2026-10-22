<?php
/**
 * Optional IndexNow notification for posts published through EG Publicación.
 * Disabled by default to avoid double submissions from existing SEO plugins.
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

function eg_pub_indexnow_enabled() {
    return (bool) get_option( 'eg_pub_indexnow_enabled', false );
}
function eg_pub_indexnow_key() {
    $key = get_option( 'eg_pub_indexnow_key', '' );
    if ( ! preg_match( '/^[a-zA-Z0-9-]{8,128}$/', (string) $key ) ) {
        $key = wp_generate_password( 32, false, false );
        update_option( 'eg_pub_indexnow_key', $key, false );
    }
    return $key;
}
add_action( 'admin_menu', function () {
    add_options_page( 'EG IndexNow', 'EG IndexNow', 'manage_options', 'eg-indexnow', 'eg_pub_indexnow_settings' );
} );
function eg_pub_indexnow_settings() {
    if ( ! current_user_can( 'manage_options' ) ) { return; }
    if ( isset( $_POST['eg_indexnow_save'] ) && check_admin_referer( 'eg_indexnow_save' ) ) {
        update_option( 'eg_pub_indexnow_enabled', ! empty( $_POST['eg_indexnow_enabled'] ), false );
        echo '<div class="notice notice-success"><p>Configuración guardada.</p></div>';
    }
    $key = eg_pub_indexnow_key();
    echo '<div class="wrap"><h1>EG IndexNow</h1><p>Comprueba primero que ningún plugin SEO o de IndexNow envíe ya las URLs. Si existe otro emisor, deja esta opción desactivada.</p>';
    echo '<form method="post">';
    wp_nonce_field( 'eg_indexnow_save' );
    echo '<p><label><input type="checkbox" name="eg_indexnow_enabled" value="1" ' . checked( eg_pub_indexnow_enabled(), true, false ) . '> Activar avisos IndexNow para artículos publicados mediante EG Publicación</label></p>';
    echo '<p>URL pública de verificación: <code>' . esc_html( home_url( '/?eg_indexnow_key=1' ) ) . '</code></p>';
    echo '<p><button class="button button-primary" type="submit" name="eg_indexnow_save" value="1">Guardar</button></p></form></div>';
}
add_action( 'template_redirect', function () {
    if ( ! isset( $_GET['eg_indexnow_key'] ) || '1' !== (string) $_GET['eg_indexnow_key'] ) { return; }
    header( 'Content-Type: text/plain; charset=utf-8' );
    header( 'X-Robots-Tag: noindex' );
    echo esc_html( eg_pub_indexnow_key() );
    exit;
}, 0 );

// Delay until WPML has assigned the correct language and permalink.
add_action( 'save_post_post', function ( $post_id, $post, $update ) {
    if ( ! eg_pub_indexnow_enabled() || wp_is_post_revision( $post_id ) || 'publish' !== $post->post_status ) { return; }
    if ( ! get_post_meta( $post_id, EG_PUB_META_NOTION, true ) ) { return; }
    if ( ! wp_next_scheduled( 'eg_pub_indexnow_submit', array( (int) $post_id ) ) ) {
        wp_schedule_single_event( time() + 180, 'eg_pub_indexnow_submit', array( (int) $post_id ) );
    }
}, 20, 3 );

add_action( 'eg_pub_indexnow_submit', function ( $post_id ) {
    $post_id = (int) $post_id;
    if ( ! eg_pub_indexnow_enabled() || 'publish' !== get_post_status( $post_id ) ) { return; }
    if ( ! get_post_meta( $post_id, EG_PUB_META_NOTION, true ) ) { return; }
    $url = get_permalink( $post_id );
    if ( ! $url || wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) { return; }
    $fingerprint = md5( $url . '|' . get_post_modified_time( 'U', true, $post_id ) );
    if ( get_post_meta( $post_id, '_eg_indexnow_sent', true ) === $fingerprint ) { return; }
    $response = wp_remote_post( 'https://api.indexnow.org/indexnow', array(
        'timeout' => 15,
        'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
        'body' => wp_json_encode( array(
            'host' => wp_parse_url( home_url(), PHP_URL_HOST ),
            'key' => eg_pub_indexnow_key(),
            'keyLocation' => home_url( '/?eg_indexnow_key=1' ),
            'urlList' => array( $url ),
        ) ),
    ) );
    $code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
    update_post_meta( $post_id, '_eg_indexnow_last_http', $code );
    update_post_meta( $post_id, '_eg_indexnow_last_attempt', current_time( 'mysql', true ) );
    if ( 200 === $code || 202 === $code ) {
        update_post_meta( $post_id, '_eg_indexnow_sent', $fingerprint );
    }
} );
