<?php
/**
 * Tipología «Gestión de la web»: banner de cookies con Google Consent Mode v2 y medición de clics.
 *   POST /wp-json/eg/v1/cookies
 * Sin parámetros devuelve la configuración guardada. Los cambios solo se previsualizan; apply:true guarda y
 * vacía la caché de WP Rocket.
 *   enabled        true/false: muestra el banner y fija el consentimiento por defecto (todo denegado hasta que se
 *                  acepte). Va antes de cualquier etiqueta de Google (la de /seo-tech ga4 o un fragmento propio).
 *   policy         {"ca": "/politica-de-cookies/", "es": "/es/politica-de-cookies/"}: enlace a la política de cookies
 *                  por idioma (WPML). Sin él, la página de privacidad de WordPress.
 *   texts          {"ca": {"text", "accept", "reject", "policy"}}: cambia los textos por defecto (ca, es, en).
 *   color          color de los botones (p. ej. "#2471FB").
 *   events         true/false (por defecto true): manda a Google Analytics los clics en teléfono (clic_telefono),
 *                  WhatsApp (clic_whatsapp) y enlaces a los hosts de booking_hosts (pedir_cita), con la ruta y el idioma.
 *   booking_hosts  ["reservas.ejemplo.com"]: enlaces que cuentan como pedir_cita.
 *   rules          [{"event": "soy_clinica", "href": "/contacte/"}]: otros clics, por un trozo del enlace.
 * Cualquier enlace a «#cookies» vuelve a abrir el banner para cambiar la elección.
 *
 * Provisional: desde la 2.100.0, si el plugin EG Cookies está activo este módulo no hace nada (EG Cookies registra
 * /cookies, copia esta configuración y pinta su propio banner). Se quitará cuando todas las webs lo tengan.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_OPTION_COOKIES = 'eg_pub_cookies';

add_filter( 'eg_pub_modules', function ( $modules ) {
	$modules['cookies'] = array( 'type' => 'gestion', 'routes' => array( 'cookies' ) );
	return $modules;
} );

/** EG Cookies instalado y activo: el módulo provisional se aparta. */
function eg_pub_cookies_external() {
	return defined( 'EG_COOKIES_VERSION' );
}

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_module_enabled( 'cookies' ) || eg_pub_cookies_external() ) {
		return;
	}
	register_rest_route( 'eg/v1', '/cookies', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_cookies_handle',
		'permission_callback' => eg_pub_module_permission( 'manage_options' ),
	) );
} );

function eg_pub_cookies_defaults() {
	return array(
		'ca' => array(
			'text'   => "Fem servir galetes d'analítica per saber com s'utilitza la web i millorar-la. Només s'activen si les acceptes.",
			'accept' => 'Acceptar',
			'reject' => 'Rebutjar',
			'policy' => 'Política de cookies',
		),
		'es' => array(
			'text'   => 'Usamos cookies de analítica para saber cómo se usa la web y mejorarla. Solo se activan si las aceptas.',
			'accept' => 'Aceptar',
			'reject' => 'Rechazar',
			'policy' => 'Política de cookies',
		),
		'en' => array(
			'text'   => 'We use analytics cookies to understand how the site is used and improve it. They are only set if you accept them.',
			'accept' => 'Accept',
			'reject' => 'Reject',
			'policy' => 'Cookie policy',
		),
	);
}

function eg_pub_cookies_config() {
	$c = get_option( EG_PUB_OPTION_COOKIES, array() );
	return is_array( $c ) ? $c : array();
}

function eg_pub_cookies_lang() {
	$lang = apply_filters( 'wpml_current_language', null );
	if ( ! $lang ) {
		$lang = substr( get_locale(), 0, 2 );
	}
	return sanitize_key( (string) $lang );
}

/** Consentimiento por defecto: antes que cualquier etiqueta de Google (la de /seo-tech va en la prioridad 1). */
add_action( 'wp_head', function () {
	$c = eg_pub_cookies_config();
	if ( empty( $c['enabled'] ) || is_admin() || eg_pub_cookies_external() ) {
		return;
	}
	echo "<script data-cfasync=\"false\" nowprocket>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}"
		. "(function(){var m=document.cookie.match(/(?:^|; )eg_consent=(granted|denied)/),g=m&&m[1]==='granted'?'granted':'denied';"
		. "gtag('consent','default',{ad_storage:g,ad_user_data:g,ad_personalization:g,analytics_storage:g,functionality_storage:'granted',security_storage:'granted',wait_for_update:500});"
		. "gtag('set','ads_data_redaction',true);})();</script>\n";
}, 0 );

add_action( 'wp_footer', function () {
	$c = eg_pub_cookies_config();
	if ( empty( $c['enabled'] ) || is_admin() || eg_pub_cookies_external() ) {
		return;
	}
	$lang     = eg_pub_cookies_lang();
	$defaults = eg_pub_cookies_defaults();
	$t        = $defaults[ $lang ] ?? $defaults['en'];
	if ( ! empty( $c['texts'][ $lang ] ) && is_array( $c['texts'][ $lang ] ) ) {
		$t = array_merge( $t, array_filter( $c['texts'][ $lang ], 'strlen' ) );
	}
	$policy = (string) ( $c['policy'][ $lang ] ?? '' );
	if ( '' === $policy ) {
		$policy = (string) get_privacy_policy_url();
	} elseif ( 0 === strpos( $policy, '/' ) ) {
		$policy = home_url( $policy );
	}
	$color = sanitize_hex_color( (string) ( $c['color'] ?? '' ) );
	$color = $color ? $color : '#1a73e8';
	?>
<div id="eg-cookies" role="dialog" aria-live="polite" aria-label="<?php echo esc_attr( $t['policy'] ); ?>" data-nosnippet hidden>
<p><?php echo esc_html( $t['text'] ); ?><?php if ( $policy ) : ?> <a href="<?php echo esc_url( $policy ); ?>"><?php echo esc_html( $t['policy'] ); ?></a><?php endif; ?></p>
<div class="eg-cookies-btns"><button type="button" data-eg-consent="denied"><?php echo esc_html( $t['reject'] ); ?></button><button type="button" data-eg-consent="granted"><?php echo esc_html( $t['accept'] ); ?></button></div>
</div>
<style>#eg-cookies{position:fixed;left:16px;right:16px;bottom:16px;z-index:999999;max-width:720px;margin:0 auto;background:#fff;color:#1f2933;border-radius:10px;box-shadow:0 6px 30px rgba(0,0,0,.18);padding:16px 20px;display:flex;flex-wrap:wrap;align-items:center;gap:12px 20px;font-size:15px;line-height:1.45}#eg-cookies[hidden]{display:none}#eg-cookies p{margin:0;flex:1 1 320px}#eg-cookies a{color:<?php echo esc_attr( $color ); ?>;text-decoration:underline}#eg-cookies .eg-cookies-btns{display:flex;gap:10px;flex:0 0 auto}#eg-cookies button{font:inherit;font-weight:600;cursor:pointer;border-radius:8px;padding:10px 20px;border:1px solid <?php echo esc_attr( $color ); ?>;background:#fff;color:<?php echo esc_attr( $color ); ?>;min-width:110px}#eg-cookies button[data-eg-consent=granted]{background:<?php echo esc_attr( $color ); ?>;color:#fff}@media (max-width:480px){#eg-cookies .eg-cookies-btns{width:100%}#eg-cookies button{flex:1}}</style>
<script data-cfasync="false" nowprocket>(function(){var box=document.getElementById('eg-cookies');if(!box)return;
function get(){var m=document.cookie.match(/(?:^|; )eg_consent=(granted|denied)/);return m?m[1]:'';}
function clearGa(){var host=location.hostname.replace(/^www\./,'');document.cookie.split('; ').forEach(function(c){var n=c.split('=')[0];if(/^_ga|^_gid$|^_gat/.test(n)){['', host, '.'+host].forEach(function(d){document.cookie=n+'=; Max-Age=0; path=/'+(d?'; domain='+d:'');});}});}
function set(v){document.cookie='eg_consent='+v+'; Max-Age=15552000; path=/; SameSite=Lax'+(location.protocol==='https:'?'; Secure':'');
if(window.gtag){gtag('consent','update',{ad_storage:v,ad_user_data:v,ad_personalization:v,analytics_storage:v});}if(v==='denied'){clearGa();}box.hidden=true;}
box.addEventListener('click',function(e){var b=e.target.closest('[data-eg-consent]');if(b){set(b.getAttribute('data-eg-consent'));}});
document.addEventListener('click',function(e){var a=e.target.closest&&e.target.closest('a[href$="#cookies"]');if(a){e.preventDefault();box.hidden=false;}});
if(!get()){box.hidden=false;}})();</script>
	<?php
	if ( ! isset( $c['events'] ) || ! empty( $c['events'] ) ) {
		$cfg = array(
			'hosts' => array_values( (array) ( $c['booking_hosts'] ?? array() ) ),
			'rules' => array_values( (array) ( $c['rules'] ?? array() ) ),
			'lang'  => $lang,
		);
		?>
<script data-cfasync="false" nowprocket>(function(c){document.addEventListener('click',function(e){if(!e.target.closest)return;var a=e.target.closest('a,[class*="ht-ctc"],[class*="ht_ctc"]');if(!a)return;
var h=a.getAttribute('href')||'',cl=a.getAttribute('class')||'',ev='',i;
if(/^tel:/i.test(h))ev='clic_telefono';else if(/wa\.me|whatsapp\.com|^whatsapp:/i.test(h)||/ht[-_]ctc/.test(cl))ev='clic_whatsapp';
else{for(i=0;i<c.hosts.length;i++){if(c.hosts[i]&&h.indexOf(c.hosts[i])>-1){ev='pedir_cita';break;}}
if(!ev){for(i=0;i<c.rules.length;i++){if(c.rules[i].href&&h.indexOf(c.rules[i].href)>-1){ev=c.rules[i].event;break;}}}}
if(ev&&window.gtag){gtag('event',ev,{link_url:h,idioma:c.lang,pagina:location.pathname});}},true);})(<?php echo wp_json_encode( $cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?>);</script>
		<?php
	}
}, 5 );

function eg_pub_cookies_handle( WP_REST_Request $r ) {
	$p      = $r->get_params();
	$apply  = ! empty( $p['apply'] );
	$before = eg_pub_cookies_config();
	$after  = $before;
	if ( array_key_exists( 'enabled', $p ) ) {
		$after['enabled'] = rest_sanitize_boolean( $p['enabled'] );
	}
	if ( array_key_exists( 'events', $p ) ) {
		$after['events'] = rest_sanitize_boolean( $p['events'] );
	}
	if ( array_key_exists( 'color', $p ) ) {
		$after['color'] = (string) sanitize_hex_color( (string) $p['color'] );
	}
	if ( isset( $p['policy'] ) && is_array( $p['policy'] ) ) {
		foreach ( $p['policy'] as $lang => $url ) {
			$url = trim( (string) $url );
			$after['policy'][ sanitize_key( $lang ) ] = 0 === strpos( $url, '/' ) ? '/' . ltrim( sanitize_text_field( $url ), '/' ) : esc_url_raw( $url );
		}
	}
	if ( isset( $p['texts'] ) && is_array( $p['texts'] ) ) {
		foreach ( $p['texts'] as $lang => $texts ) {
			foreach ( array( 'text', 'accept', 'reject', 'policy' ) as $k ) {
				if ( isset( $texts[ $k ] ) && is_scalar( $texts[ $k ] ) ) {
					$after['texts'][ sanitize_key( $lang ) ][ $k ] = sanitize_text_field( (string) $texts[ $k ] );
				}
			}
		}
	}
	if ( isset( $p['booking_hosts'] ) ) {
		$after['booking_hosts'] = array_values( array_filter( array_map( function ( $h ) {
			return preg_replace( '/[^a-z0-9.\-\/]/', '', strtolower( (string) $h ) );
		}, (array) $p['booking_hosts'] ) ) );
	}
	if ( isset( $p['rules'] ) ) {
		$rules = array();
		foreach ( (array) $p['rules'] as $rule ) {
			$event = sanitize_key( (string) ( $rule['event'] ?? '' ) );
			$href  = sanitize_text_field( (string) ( $rule['href'] ?? '' ) );
			if ( $event && $href ) {
				$rules[] = array( 'event' => $event, 'href' => $href );
			}
		}
		$after['rules'] = $rules;
	}
	$changed = $after !== $before;
	if ( $apply && $changed ) {
		update_option( EG_PUB_OPTION_COOKIES, $after, true );
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
	}
	return new WP_REST_Response( array(
		'ok'      => true,
		'applied' => $apply && $changed,
		'changed' => $changed,
		'config'  => $after,
		'before'  => $before,
		'ga4'     => (string) get_option( 'eg_pub_ga4_id', '' ),
	), 200 );
}
