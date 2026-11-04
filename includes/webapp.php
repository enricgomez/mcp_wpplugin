<?php
/**
 * Tipología «Gestión de la web», módulo «webapp»: la web como aplicación instalable (PWA) y avisos push.
 *   POST /wp-json/eg/v1/webapp
 * Sin parámetros devuelve los ajustes y el estado (iconos, claves push, pedidos con push).
 *   settings  {"enabled": true, "name": "…", "short_name": "…", "theme_color": "#0E0A08", "background_color": "#0E0A08",
 *              "icon": 1036, "icon_background": "#0E0A08", "offline": {"title","text","phone"},
 *              "install": {"enabled", "title", "text", "button", "ios_button", "ios_step1", "ios_step2", "ios_step3", "ios_arrow", "delay", "days", "bottom"},
 *                         (iPhone: botón «Ver cómo» que abre hasta 3 pasos con iconos; {menu}, {share} y {add} se cambian por el icono; ios_arrow = posición de la flecha, p. ej. "18%")
 *              "push": {"enabled", "title", "prompt_title", "prompt_text", "button", "done_text", "subject",
 *                       "statuses": ["processing", …], "messages": {"processing": "…"}, "replace_sms": true},
 *              "exclude": ["/carrito", …]}  cambia solo las claves que se envían.
 *   test_push {"order_id": 123, "message": "…"}  envía un aviso de prueba a los dispositivos de ese pedido.
 *   vapid_reset true  genera claves push nuevas (los dispositivos tendrán que volver a aceptar).
 * Solo previsualiza; guarda con apply:true. Apagado por defecto (enabled false): sin manifiesto, sin service worker y
 * sin avisos. Al apagarlo, los móviles que ya lo tenían reciben un service worker que se desinstala solo.
 *
 *   POST /wp-json/eg/v1/webapp-subscribe  (público) {"order_id", "key", "subscription": {endpoint, keys{p256dh, auth}}}
 * Lo llama la página de pedido recibido cuando el cliente acepta los avisos. Con push activo y aviso enviado, el SMS
 * de WooCommerce Twilio SMS Notifications de ese cambio de estado no se envía (replace_sms); sin push, sale como siempre.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_WEBAPP_OPT   = 'eg_pub_webapp';
const EG_PUB_WEBAPP_VAPID = 'eg_pub_webapp_vapid';
const EG_PUB_WEBAPP_META  = '_eg_webapp_push';

add_filter( 'eg_pub_modules', function ( $modules ) {
	$modules['webapp'] = array( 'type' => 'gestion', 'routes' => array( 'webapp', 'webapp-subscribe' ) );
	return $modules;
} );

/* ------------------------------------------------------------------ ajustes */

function eg_pub_webapp_defaults() {
	$name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	return array(
		'enabled'          => false,
		'name'             => $name,
		'short_name'       => mb_substr( $name, 0, 12 ),
		'description'      => wp_specialchars_decode( (string) get_bloginfo( 'description' ), ENT_QUOTES ),
		'theme_color'      => '#0E0A08',
		'background_color' => '#0E0A08',
		'icon'             => 0,
		'icon_background'  => '',
		'start_url'        => '/',
		'offline'          => array(
			'title' => 'Sin conexión',
			'text'  => 'Parece que no tienes internet ahora mismo. Vuelve a intentarlo en un momento.',
			'phone' => '',
		),
		'install'          => array(
			'enabled'  => true,
			'title'    => 'Instala la app',
			'text'     => 'Pide en dos toques desde tu pantalla de inicio.',
			'button'   => 'Instalar',
			'ios_button' => 'Ver cómo',
			'ios_step1'  => 'Toca {menu} a la izquierda de la barra de direcciones.',
			'ios_step2'  => 'Toca {share} Compartir.',
			'ios_step3'  => 'Elige {add} «Añadir a pantalla de inicio».',
			'ios_arrow'  => '18%',
			'delay'    => 8,
			'days'     => 14,
			'bottom'   => '6.6em',
		),
		'push'             => array(
			'enabled'      => false,
			'title'        => '',
			'prompt_title' => '¿Te avisamos?',
			'prompt_text'  => 'Recibe un aviso en este móvil cuando cambie el estado de tu pedido.',
			'button'       => 'Avisarme',
			'done_text'    => 'Perfecto, te avisaremos aquí.',
			'subject'      => 'mailto:' . get_option( 'admin_email' ),
			'statuses'     => array(),
			'messages'     => array(),
			'replace_sms'  => true,
		),
		'exclude'          => array( '/carrito', '/cart', '/checkout', '/finalizar-compra', '/mi-cuenta', '/my-account', '/wp-admin', '/wp-login', '/wp-json', 'wc-ajax', 'add-to-cart', 'preview=' ),
	);
}

function eg_pub_webapp_settings() {
	$saved = get_option( EG_PUB_WEBAPP_OPT, array() );
	return eg_pub_webapp_merge( eg_pub_webapp_defaults(), is_array( $saved ) ? $saved : array() );
}

function eg_pub_webapp_merge( array $base, array $new ) {
	foreach ( $new as $k => $v ) {
		if ( ! array_key_exists( $k, $base ) ) {
			continue;
		}
		if ( is_array( $base[ $k ] ) && is_array( $v ) && in_array( $k, array( 'offline', 'install', 'push' ), true ) ) {
			$base[ $k ] = eg_pub_webapp_merge( $base[ $k ], $v );
		} else {
			$base[ $k ] = $v;
		}
	}
	return $base;
}

/** Limpia los ajustes que llegan por la API. */
function eg_pub_webapp_sanitize( array $s ) {
	$color = function ( $c, $def ) {
		$c = sanitize_hex_color( (string) $c );
		return $c ? $c : $def;
	};
	$text  = function ( $t ) {
		return sanitize_text_field( (string) $t );
	};
	$s['enabled']          = ! empty( $s['enabled'] );
	$s['name']             = $text( $s['name'] );
	$s['short_name']       = mb_substr( $text( $s['short_name'] ), 0, 20 );
	$s['description']      = $text( $s['description'] );
	$s['theme_color']      = $color( $s['theme_color'], '#000000' );
	$s['background_color'] = $color( $s['background_color'], '#000000' );
	$s['icon_background']  = '' === (string) $s['icon_background'] ? '' : $color( $s['icon_background'], '' );
	$s['icon']             = absint( $s['icon'] );
	$s['start_url']        = '/' . ltrim( (string) wp_parse_url( (string) $s['start_url'], PHP_URL_PATH ), '/' );
	foreach ( array( 'title', 'text', 'phone' ) as $k ) {
		$s['offline'][ $k ] = $text( $s['offline'][ $k ] ?? '' );
	}
	$s['install']['enabled'] = ! empty( $s['install']['enabled'] );
	foreach ( array( 'title', 'text', 'button', 'ios_button', 'ios_step1', 'ios_step2', 'ios_step3' ) as $k ) {
		$s['install'][ $k ] = $text( $s['install'][ $k ] ?? '' );
	}
	$s['install']['ios_arrow'] = preg_match( '/^[0-9.]{1,5}%$/', (string) ( $s['install']['ios_arrow'] ?? '' ) ) ? $s['install']['ios_arrow'] : '50%';
	$s['install']['delay']  = max( 0, min( 120, (int) $s['install']['delay'] ) );
	$s['install']['days']   = max( 0, min( 365, (int) $s['install']['days'] ) );
	$s['install']['bottom'] = preg_match( '/^[0-9.]+(px|em|rem|vh)$/', (string) $s['install']['bottom'] ) ? $s['install']['bottom'] : '1em';
	$s['push']['enabled']     = ! empty( $s['push']['enabled'] );
	$s['push']['replace_sms'] = ! empty( $s['push']['replace_sms'] );
	foreach ( array( 'title', 'prompt_title', 'prompt_text', 'button', 'done_text' ) as $k ) {
		$s['push'][ $k ] = $text( $s['push'][ $k ] ?? '' );
	}
	$subject              = trim( (string) $s['push']['subject'] );
	$s['push']['subject'] = ( 0 === strpos( $subject, 'mailto:' ) || 0 === strpos( $subject, 'https://' ) ) ? $subject : 'mailto:' . get_option( 'admin_email' );
	$s['push']['statuses'] = array_values( array_filter( array_map( function ( $st ) {
		return sanitize_key( preg_replace( '/^wc-/', '', (string) $st ) );
	}, (array) $s['push']['statuses'] ) ) );
	$messages = array();
	foreach ( (array) $s['push']['messages'] as $st => $msg ) {
		$st = sanitize_key( preg_replace( '/^wc-/', '', (string) $st ) );
		if ( $st && '' !== trim( (string) $msg ) ) {
			$messages[ $st ] = sanitize_textarea_field( (string) $msg );
		}
	}
	$s['push']['messages'] = $messages;
	$s['exclude']          = array_values( array_filter( array_map( 'sanitize_text_field', (array) $s['exclude'] ) ) );
	return $s;
}

/* ------------------------------------------------------------------ API de gestión */

add_action( 'rest_api_init', function () {
	if ( ! eg_pub_module_enabled( 'webapp' ) ) {
		return;
	}
	register_rest_route( 'eg/v1', '/webapp', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_webapp_handle',
		'permission_callback' => eg_pub_module_permission( 'manage_options' ),
	) );
	register_rest_route( 'eg/v1', '/webapp-subscribe', array(
		'methods'             => 'POST',
		'callback'            => 'eg_pub_webapp_subscribe',
		'permission_callback' => '__return_true',
	) );
} );

function eg_pub_webapp_handle( WP_REST_Request $r ) {
	$p       = (array) $r->get_json_params();
	$apply   = ! empty( $p['apply'] );
	$current = eg_pub_webapp_settings();
	$new     = $current;
	$out     = array( 'ok' => true, 'applied' => false, 'changes' => array() );

	if ( isset( $p['settings'] ) && is_array( $p['settings'] ) ) {
		$new = eg_pub_webapp_sanitize( eg_pub_webapp_merge( $current, $p['settings'] ) );
		foreach ( $new as $k => $v ) {
			if ( $v !== $current[ $k ] ) {
				$out['changes'][] = $k;
			}
		}
	}
	if ( ! empty( $p['vapid_reset'] ) ) {
		$out['changes'][] = 'vapid';
	}

	if ( $apply && $out['changes'] ) {
		if ( ! empty( $p['vapid_reset'] ) ) {
			delete_option( EG_PUB_WEBAPP_VAPID );
		}
		update_option( EG_PUB_WEBAPP_OPT, $new, true );
		if ( $new['enabled'] ) {
			$out['icons'] = eg_pub_webapp_build_icons( $new );
			if ( $new['push']['enabled'] ) {
				eg_pub_webapp_vapid();
			}
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		$out['applied'] = true;
	}

	if ( ! empty( $p['test_push'] ) && is_array( $p['test_push'] ) ) {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( absint( $p['test_push']['order_id'] ?? 0 ) ) : false;
		if ( ! $order ) {
			$out['test_push'] = array( 'error' => 'No existe el pedido.' );
		} else {
			$msg              = sanitize_text_field( (string) ( $p['test_push']['message'] ?? 'Aviso de prueba' ) );
			$out['test_push'] = eg_pub_webapp_push_order( $order, $msg, eg_pub_webapp_order_url( $order ) );
		}
	}

	$s            = $apply ? $new : $current;
	$out['settings'] = $apply ? $new : ( $out['changes'] ? $new : $current );
	$vapid        = get_option( EG_PUB_WEBAPP_VAPID, array() );
	$out['state'] = array(
		'manifest'      => home_url( '/?eg-webapp=manifest' ),
		'service_worker'=> home_url( '/?eg-webapp=sw' ),
		'icons'         => get_option( EG_PUB_WEBAPP_OPT . '_icons', array() ),
		'vapid_public'  => $vapid['public'] ?? null,
		'gd'            => function_exists( 'imagecreatetruecolor' ),
		'openssl_ec'    => function_exists( 'openssl_pkey_derive' ),
		'twilio'        => class_exists( 'WC_Twilio_SMS' ),
		'twilio_statuses' => array_values( (array) get_option( 'wc_twilio_sms_send_sms_order_statuses', array() ) ),
		'push_statuses' => eg_pub_webapp_push_statuses( $s ),
	);
	return rest_ensure_response( $out );
}

/* ------------------------------------------------------------------ iconos */

/** Genera los iconos (logo centrado sobre el color de fondo) en uploads/eg-webapp/. */
function eg_pub_webapp_build_icons( array $s ) {
	$icons = array();
	$file  = $s['icon'] ? get_attached_file( $s['icon'] ) : '';
	if ( ! $file || ! file_exists( $file ) || ! function_exists( 'imagecreatetruecolor' ) ) {
		update_option( EG_PUB_WEBAPP_OPT . '_icons', array(), false );
		return array( 'error' => $file ? 'Falta la extensión GD de PHP.' : 'No hay icono (settings.icon = ID de una imagen).' );
	}
	$src = @imagecreatefromstring( (string) file_get_contents( $file ) );
	if ( ! $src ) {
		return array( 'error' => 'No se puede leer la imagen del icono.' );
	}
	$up  = wp_upload_dir();
	$dir = trailingslashit( $up['basedir'] ) . 'eg-webapp';
	wp_mkdir_p( $dir );
	$bg  = ltrim( $s['icon_background'] ? $s['icon_background'] : $s['background_color'], '#' );
	$rgb = array_map( 'hexdec', str_split( strlen( $bg ) === 3 ? $bg[0] . $bg[0] . $bg[1] . $bg[1] . $bg[2] . $bg[2] : $bg, 2 ) );
	$ver = substr( md5( $s['icon'] . $bg . filemtime( $file ) ), 0, 8 );
	$sw  = imagesx( $src );
	$sh  = imagesy( $src );
	foreach ( array( 'icon-192' => array( 192, .12 ), 'icon-512' => array( 512, .12 ), 'maskable-512' => array( 512, .24 ), 'apple-180' => array( 180, .14 ) ) as $name => $cfg ) {
		list( $size, $pad ) = $cfg;
		$im = imagecreatetruecolor( $size, $size );
		imagefill( $im, 0, 0, imagecolorallocate( $im, $rgb[0], $rgb[1], $rgb[2] ) );
		imagealphablending( $im, true );
		$box   = $size * ( 1 - 2 * $pad );
		$scale = min( $box / $sw, $box / $sh );
		$w     = (int) round( $sw * $scale );
		$h     = (int) round( $sh * $scale );
		imagecopyresampled( $im, $src, (int) ( ( $size - $w ) / 2 ), (int) ( ( $size - $h ) / 2 ), 0, 0, $w, $h, $sw, $sh );
		imagepng( $im, $dir . '/' . $name . '.png', 9 );
		imagedestroy( $im );
		$icons[ $name ] = trailingslashit( $up['baseurl'] ) . 'eg-webapp/' . $name . '.png?v=' . $ver;
	}
	imagedestroy( $src );
	update_option( EG_PUB_WEBAPP_OPT . '_icons', $icons, false );
	return $icons;
}

/* ------------------------------------------------------------------ front: manifiesto, SW, página sin conexión */

add_action( 'init', function () {
	if ( empty( $_GET['eg-webapp'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return;
	}
	$what = sanitize_key( wp_unslash( $_GET['eg-webapp'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	$s    = eg_pub_webapp_settings();
	$on   = $s['enabled'] && eg_pub_module_enabled( 'webapp' );
	if ( 'sw' === $what ) {
		nocache_headers();
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: /' );
		echo $on ? eg_pub_webapp_sw( $s ) : eg_pub_webapp_sw_kill(); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}
	if ( ! $on ) {
		return;
	}
	if ( 'manifest' === $what ) {
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );
		echo wp_json_encode( eg_pub_webapp_manifest( $s ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}
	if ( 'offline' === $what ) {
		header( 'Content-Type: text/html; charset=utf-8' );
		echo eg_pub_webapp_offline_html( $s ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}
}, 1 );

function eg_pub_webapp_manifest( array $s ) {
	$icons = (array) get_option( EG_PUB_WEBAPP_OPT . '_icons', array() );
	$list  = array();
	foreach ( array( 'icon-192' => array( '192x192', 'any' ), 'icon-512' => array( '512x512', 'any' ), 'maskable-512' => array( '512x512', 'maskable' ) ) as $k => $meta ) {
		if ( ! empty( $icons[ $k ] ) ) {
			$list[] = array( 'src' => $icons[ $k ], 'sizes' => $meta[0], 'type' => 'image/png', 'purpose' => $meta[1] );
		}
	}
	return array(
		'id'               => $s['start_url'],
		'name'             => $s['name'],
		'short_name'       => $s['short_name'],
		'description'      => $s['description'],
		'lang'             => substr( get_locale(), 0, 2 ),
		'start_url'        => $s['start_url'],
		'scope'            => '/',
		'display'          => 'standalone',
		'orientation'      => 'portrait',
		'background_color' => $s['background_color'],
		'theme_color'      => $s['theme_color'],
		'icons'            => $list,
	);
}

/** Versión del SW: cambia cuando cambian los ajustes o el plugin, para que los móviles lo actualicen. */
function eg_pub_webapp_version( array $s ) {
	return substr( md5( wp_json_encode( $s ) . EG_PUB_VERSION ), 0, 10 );
}

function eg_pub_webapp_sw( array $s ) {
	$cfg = array(
		'v'       => eg_pub_webapp_version( $s ),
		'offline' => home_url( '/?eg-webapp=offline', 'relative' ),
		'exclude' => array_values( $s['exclude'] ),
		'icon'    => (string) ( ( (array) get_option( EG_PUB_WEBAPP_OPT . '_icons', array() ) )['icon-192'] ?? '' ),
	);
	return '/* EG Publicación · webapp */
const CFG = ' . wp_json_encode( $cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ';
const STATIC_CACHE = "eg-static-" + CFG.v, PAGE_CACHE = "eg-page-" + CFG.v;
self.addEventListener("install", e => {
  e.waitUntil(caches.open(PAGE_CACHE).then(c => c.add(new Request(CFG.offline, {cache: "reload"}))).then(() => self.skipWaiting()));
});
self.addEventListener("activate", e => {
  e.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k.startsWith("eg-") && k !== STATIC_CACHE && k !== PAGE_CACHE).map(k => caches.delete(k)))).then(() => self.clients.claim()));
});
function excluded(url) { return CFG.exclude.some(x => url.pathname.indexOf(x) === 0 || url.href.indexOf(x) !== -1); }
self.addEventListener("fetch", e => {
  const req = e.request;
  if (req.method !== "GET") return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin || excluded(url) || url.searchParams.has("eg-webapp")) return;
  if (req.mode === "navigate") {
    // Las páginas siempre de la red (precios, carrito, horario); sin conexión, la página propia.
    e.respondWith(fetch(req).catch(() => caches.match(CFG.offline)));
    return;
  }
  if (/^\/wp-(content|includes)\//.test(url.pathname) && /\.(css|js|woff2?|ttf|png|jpe?g|webp|avif|gif|svg)$/i.test(url.pathname)) {
    e.respondWith(caches.open(STATIC_CACHE).then(c => c.match(req).then(hit => {
      const net = fetch(req).then(res => { if (res && res.ok && res.type === "basic") c.put(req, res.clone()); return res; });
      return hit || net;
    })));
  }
});
self.addEventListener("push", e => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch (err) { d = {body: e.data ? e.data.text() : ""}; }
  e.waitUntil(self.registration.showNotification(d.title || "", {body: d.body || "", icon: d.icon || CFG.icon, badge: d.badge || CFG.icon, tag: d.tag || "eg", renotify: true, data: {url: d.url || "/"}}));
});
self.addEventListener("notificationclick", e => {
  e.notification.close();
  const url = (e.notification.data && e.notification.data.url) || "/";
  e.waitUntil(self.clients.matchAll({type: "window", includeUncontrolled: true}).then(list => {
    for (const c of list) { if (c.url === url && "focus" in c) return c.focus(); }
    return self.clients.openWindow(url);
  }));
});
';
}

/** SW que se desinstala: lo reciben los móviles que tenían la webapp cuando el módulo se apaga. */
function eg_pub_webapp_sw_kill() {
	return '/* EG Publicación · webapp desactivada */
self.addEventListener("install", () => self.skipWaiting());
self.addEventListener("activate", e => {
  e.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k.startsWith("eg-")).map(k => caches.delete(k))))
    .then(() => self.registration.unregister())
    .then(() => self.clients.matchAll({type: "window"}))
    .then(list => list.forEach(c => c.navigate(c.url))));
});
';
}

function eg_pub_webapp_offline_html( array $s ) {
	$icons = (array) get_option( EG_PUB_WEBAPP_OPT . '_icons', array() );
	$phone = $s['offline']['phone'];
	$tel   = preg_replace( '/[^0-9+]/', '', $phone );
	ob_start();
	?><!doctype html>
<html lang="<?php echo esc_attr( substr( get_locale(), 0, 2 ) ); ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?php echo esc_html( $s['offline']['title'] . ' · ' . $s['name'] ); ?></title><meta name="theme-color" content="<?php echo esc_attr( $s['theme_color'] ); ?>">
<style>html,body{margin:0;height:100%}body{display:grid;place-items:center;background:<?php echo esc_html( $s['background_color'] ); ?>;color:#F7EEE3;font:16px/1.5 system-ui,-apple-system,Segoe UI,sans-serif;text-align:center;padding:24px;box-sizing:border-box}
main{max-width:340px}img{width:96px;height:96px;border-radius:24px;margin-bottom:18px}h1{font-size:1.5rem;margin:0 0 .4em}p{opacity:.8;margin:0 0 1.4em}
a,button{display:block;width:100%;box-sizing:border-box;padding:14px 18px;border-radius:999px;font:700 1rem system-ui,sans-serif;text-decoration:none;margin-top:10px;border:0;cursor:pointer}
a{background:<?php echo esc_html( $s['theme_color'] === $s['background_color'] ? '#B8202F' : $s['theme_color'] ); ?>;color:#fff}button{background:rgba(255,255,255,.08);color:inherit;border:1px solid rgba(255,255,255,.2)}</style></head>
<body><main><?php if ( ! empty( $icons['icon-192'] ) ) : ?><img src="<?php echo esc_url( $icons['icon-192'] ); ?>" alt=""><?php endif; ?>
<h1><?php echo esc_html( $s['offline']['title'] ); ?></h1><p><?php echo esc_html( $s['offline']['text'] ); ?></p>
<?php if ( $tel ) : ?><a href="tel:<?php echo esc_attr( $tel ); ?>"><?php echo esc_html( $phone ); ?></a><?php endif; ?>
<button onclick="location.reload()">Reintentar</button></main></body></html>
	<?php
	return (string) ob_get_clean();
}

/* ------------------------------------------------------------------ front: metas, registro del SW y aviso de instalación */

function eg_pub_webapp_front_on() {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return false;
	}
	$s = eg_pub_webapp_settings();
	return $s['enabled'] && eg_pub_module_enabled( 'webapp' ) ? $s : false;
}

add_action( 'wp_head', function () {
	$s = eg_pub_webapp_front_on();
	if ( ! $s ) {
		return;
	}
	$icons = (array) get_option( EG_PUB_WEBAPP_OPT . '_icons', array() );
	echo '<link rel="manifest" href="' . esc_url( home_url( '/?eg-webapp=manifest' ) ) . '">' . "\n";
	echo '<meta name="theme-color" content="' . esc_attr( $s['theme_color'] ) . '">' . "\n";
	echo '<meta name="mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
	echo '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent"><meta name="apple-mobile-web-app-title" content="' . esc_attr( $s['short_name'] ) . '">' . "\n";
	if ( ! empty( $icons['apple-180'] ) ) {
		echo '<link rel="apple-touch-icon" href="' . esc_url( $icons['apple-180'] ) . '">' . "\n";
	}
}, 2 );

add_action( 'wp_footer', function () {
	$s = eg_pub_webapp_front_on();
	if ( ! $s ) {
		return;
	}
	$install = $s['install']['enabled'] && ! ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() ) );
	$icons   = (array) get_option( EG_PUB_WEBAPP_OPT . '_icons', array() );
	$cfg     = array(
		'sw'      => home_url( '/?eg-webapp=sw', 'relative' ),
		'install' => $install ? array(
			'title'  => $s['install']['title'],
			'text'   => $s['install']['text'],
			'button' => $s['install']['button'],
			'ios_button' => $s['install']['ios_button'],
			'ios_step1'  => $s['install']['ios_step1'],
			'ios_step2'  => $s['install']['ios_step2'],
			'ios_step3'  => $s['install']['ios_step3'],
			'ios_arrow'  => $s['install']['ios_arrow'],
			'delay'  => (int) $s['install']['delay'],
			'days'   => (int) $s['install']['days'],
			'icon'   => (string) ( $icons['icon-192'] ?? '' ),
		) : null,
	);
	if ( $install ) {
		$bottom = $s['install']['bottom'];
		echo '<style id="eg-webapp-css">.eg-wa{position:fixed;left:max(.75em,env(safe-area-inset-left));right:max(.75em,env(safe-area-inset-right));bottom:calc(' . esc_html( $bottom ) . ' + env(safe-area-inset-bottom));z-index:9995;display:flex;align-items:center;gap:.8em;padding:.8em .9em;border-radius:18px;background:rgba(26,19,16,.96);-webkit-backdrop-filter:blur(12px);backdrop-filter:blur(12px);border:1px solid rgba(239,165,78,.22);box-shadow:0 20px 44px -14px rgba(0,0,0,.85);color:#F7EEE3;font:14px/1.35 "DM Sans",system-ui,sans-serif;transform:translateY(140%);opacity:0;transition:transform .45s cubic-bezier(.2,.9,.3,1.2),opacity .3s}.eg-wa.is-on{transform:none;opacity:1}.eg-wa img{width:44px;height:44px;border-radius:12px;flex:0 0 44px}.eg-wa b{display:block;font-size:15px}.eg-wa span{opacity:.75}.eg-wa__txt{flex:1;min-width:0}.eg-wa__go{flex:0 0 auto;border:0;border-radius:999px;padding:.7em 1.1em;font:700 14px "DM Sans",system-ui,sans-serif;color:#fff;background:linear-gradient(135deg,#E0662E,#B8202F 52%,#7A1022);cursor:pointer}.eg-wa__x{flex:0 0 auto;background:none;border:0;color:inherit;opacity:.55;font-size:22px;line-height:1;padding:.2em;cursor:pointer}.eg-wa.is-open{flex-wrap:wrap}.eg-wa__steps{flex:1 0 100%;margin:.2em 0 0;padding:.75em 0 0;border-top:1px solid rgba(247,238,227,.12);list-style:none;display:grid;gap:.6em}.eg-wa__steps li{display:flex;align-items:center;gap:.65em}.eg-wa__n{flex:0 0 1.6em;height:1.6em;border-radius:50%;display:grid;place-items:center;font-weight:700;font-size:12px;background:rgba(239,165,78,.16);color:#EFA54E}.eg-wa__ic{display:inline-grid;place-items:center;width:1.9em;height:1.9em;margin:0 .15em;border-radius:8px;background:rgba(247,238,227,.1);vertical-align:middle}.eg-wa__ic svg{width:1.15em;height:1.15em;stroke:#5AA9FF;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}.eg-wa__arrow{position:fixed;left:50%;bottom:calc(.4em + env(safe-area-inset-bottom));z-index:9996;width:34px;height:34px;margin-left:-17px;color:#EFA54E;pointer-events:none;animation:egWaB 1.1s ease-in-out infinite}.eg-wa__arrow svg{width:100%;height:100%;stroke:currentColor;fill:none;stroke-width:2.6;stroke-linecap:round;stroke-linejoin:round;filter:drop-shadow(0 3px 6px rgba(0,0,0,.6))}@keyframes egWaB{0%,100%{transform:translateY(0)}50%{transform:translateY(7px)}}@media(prefers-reduced-motion:reduce){.eg-wa__arrow{animation:none}}@media(min-width:1025px){.eg-wa{left:auto;right:1.5em;bottom:1.5em;max-width:380px}}</style>' . "\n";
	}
	?>
<script id="eg-webapp-js">(function(){var C=<?php echo wp_json_encode( $cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?>;
if("serviceWorker" in navigator){window.addEventListener("load",function(){navigator.serviceWorker.register(C.sw,{scope:"/",updateViaCache:"none"}).catch(function(){});});}
var I=C.install;if(!I)return;var standalone=window.matchMedia("(display-mode: standalone)").matches||navigator.standalone===true;if(standalone)return;
var K="eg-wa-dismissed",now=Date.now();try{var t=+localStorage.getItem(K)||0;if(now-t<I.days*864e5)return;}catch(e){}
var ua=navigator.userAgent,ios=/iphone|ipad|ipod/i.test(ua)&&!/crios|fxios|edgios/i.test(ua),deferred=null;
function esc(s){var d=document.createElement("div");d.textContent=s;return d.innerHTML;}
function show(isIos){if(document.querySelector(".eg-wa"))return;var el=document.createElement("div");el.className="eg-wa";el.setAttribute("role","dialog");
el.innerHTML=(I.icon?'<img src="'+esc(I.icon)+'" alt="">':"")+'<div class="eg-wa__txt"><b>'+esc(I.title)+'</b><span>'+esc(I.text)+'</span></div><button class="eg-wa__go" type="button">'+esc(isIos?I.ios_button:I.button)+'</button><button class="eg-wa__x" type="button" aria-label="Cerrar">×</button>';
document.body.appendChild(el);requestAnimationFrame(function(){el.classList.add("is-on");});
var arrow=null;function close(){el.classList.remove("is-on");if(arrow){arrow.remove();arrow=null;}try{localStorage.setItem(K,String(Date.now()));}catch(e){}setTimeout(function(){el.remove();},500);}
el.querySelector(".eg-wa__x").onclick=close;var go=el.querySelector(".eg-wa__go");if(isIos){go.onclick=function(){go.remove();el.classList.add("is-open");var sh='<span class="eg-wa__ic"><svg viewBox="0 0 24 24"><path d="M12 15V3M8 7l4-4 4 4"/><path d="M6 11H5v10h14V11h-1"/></svg></span>',ad='<span class="eg-wa__ic"><svg viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="4"/><path d="M12 8v8M8 12h8"/></svg></span>';var mn='<span class="eg-wa__ic"><svg viewBox="0 0 24 24"><path d="M5 8h14M5 12h14M5 16h9"/></svg></span>';var ol=document.createElement("ol");ol.className="eg-wa__steps";var h="",n=0;[I.ios_step1,I.ios_step2,I.ios_step3].forEach(function(t){if(!t)return;n++;h+='<li><span class="eg-wa__n">'+n+'</span><span>'+esc(t).replace("{menu}",mn).replace("{share}",sh).replace("{add}",ad)+'</span></li>';});ol.innerHTML=h;el.appendChild(ol);if(/iphone|ipod/i.test(ua)){arrow=document.createElement("div");arrow.className="eg-wa__arrow";arrow.style.left=I.ios_arrow;arrow.innerHTML='<svg viewBox="0 0 24 24"><path d="M12 4v15M6 13l6 6 6-6"/></svg>';document.body.appendChild(arrow);}};return;}if(go)go.onclick=function(){if(!deferred)return close();deferred.prompt();deferred.userChoice.then(function(){deferred=null;close();});};}
window.addEventListener("beforeinstallprompt",function(e){e.preventDefault();deferred=e;setTimeout(function(){show(false);},I.delay*1000);});
window.addEventListener("appinstalled",function(){var el=document.querySelector(".eg-wa");if(el)el.remove();});
if(ios)setTimeout(function(){show(true);},I.delay*1000);})();</script>
	<?php
}, 50 );

/* ------------------------------------------------------------------ push: permiso tras el pedido */

add_action( 'woocommerce_thankyou', function ( $order_id ) {
	$s = eg_pub_webapp_front_on();
	if ( ! $s || ! $s['push']['enabled'] || ! $order_id ) {
		return;
	}
	$order = wc_get_order( $order_id );
	$vapid = eg_pub_webapp_vapid();
	if ( ! $order || empty( $vapid['public'] ) ) {
		return;
	}
	$cfg = array(
		'url'   => rest_url( 'eg/v1/webapp-subscribe' ),
		'order' => (int) $order->get_id(),
		'key'   => $order->get_order_key(),
		'vapid' => $vapid['public'],
		'done'  => $s['push']['done_text'],
	);
	?>
<div class="eg-push" id="eg-push" style="display:none;margin:1.5em 0;padding:1.2em 1.3em;border-radius:18px;background:rgba(239,165,78,.08);border:1px solid rgba(239,165,78,.28);display:flex;gap:1em;align-items:center;flex-wrap:wrap">
	<div style="flex:1 1 220px"><strong style="display:block;font-size:1.1em"><?php echo esc_html( $s['push']['prompt_title'] ); ?></strong><span style="opacity:.8"><?php echo esc_html( $s['push']['prompt_text'] ); ?></span></div>
	<button type="button" class="eg-push__btn button" style="border-radius:999px;padding:.8em 1.4em;font-weight:700;border:0;color:#fff;background:linear-gradient(135deg,#E0662E,#B8202F 52%,#7A1022)"><?php echo esc_html( $s['push']['button'] ); ?></button>
</div>
<script>(function(){var C=<?php echo wp_json_encode( $cfg, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); ?>,box=document.getElementById("eg-push");
if(!box||!("serviceWorker" in navigator)||!("PushManager" in window)||!("Notification" in window)||Notification.permission==="denied")return;
function key(b){var p="=".repeat((4-b.length%4)%4),s=atob((b+p).replace(/-/g,"+").replace(/_/g,"/")),a=new Uint8Array(s.length);for(var i=0;i<s.length;i++)a[i]=s.charCodeAt(i);return a;}
function send(sub){return fetch(C.url,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({order_id:C.order,key:C.key,subscription:sub.toJSON()})});}
function show(){box.style.display="flex";}function hide(){box.style.display="none";}function done(){show();box.innerHTML='<strong>'+C.done.replace(/</g,"&lt;")+'</strong>';}
navigator.serviceWorker.ready.then(function(reg){return reg.pushManager.getSubscription().then(function(sub){
if(sub&&Notification.permission==="granted"){return send(sub).then(done);}
show();box.querySelector(".eg-push__btn").onclick=function(){Notification.requestPermission().then(function(p){if(p!=="granted"){hide();return;}
return reg.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:key(C.vapid)}).then(send).then(done);}).catch(hide);};});});})();</script>
	<?php
}, 5 );

function eg_pub_webapp_subscribe( WP_REST_Request $r ) {
	$s = eg_pub_webapp_settings();
	if ( ! $s['enabled'] || ! $s['push']['enabled'] || ! function_exists( 'wc_get_order' ) ) {
		return eg_pub_error( 'eg_off', 'Los avisos no están activos.', 403 );
	}
	$p     = (array) $r->get_json_params();
	$order = wc_get_order( absint( $p['order_id'] ?? 0 ) );
	if ( ! $order || ! hash_equals( (string) $order->get_order_key(), (string) ( $p['key'] ?? '' ) ) ) {
		return eg_pub_error( 'eg_order', 'Pedido no válido.', 403 );
	}
	$sub = eg_pub_webapp_clean_sub( $p['subscription'] ?? null );
	if ( ! $sub ) {
		return eg_pub_error( 'eg_sub', 'Suscripción no válida.' );
	}
	eg_pub_webapp_add_sub( $order, $sub );
	if ( $order->get_customer_id() ) {
		$user = (array) get_user_meta( $order->get_customer_id(), EG_PUB_WEBAPP_META, true );
		update_user_meta( $order->get_customer_id(), EG_PUB_WEBAPP_META, eg_pub_webapp_merge_subs( array_filter( $user ), $sub ) );
	}
	return rest_ensure_response( array( 'ok' => true ) );
}

function eg_pub_webapp_clean_sub( $sub ) {
	if ( ! is_array( $sub ) || empty( $sub['endpoint'] ) || empty( $sub['keys']['p256dh'] ) || empty( $sub['keys']['auth'] ) ) {
		return null;
	}
	$endpoint = esc_url_raw( (string) $sub['endpoint'], array( 'https' ) );
	$p256     = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $sub['keys']['p256dh'] );
	$auth     = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $sub['keys']['auth'] );
	if ( ! $endpoint || 65 !== strlen( eg_pub_b64u_dec( $p256 ) ) || 16 !== strlen( eg_pub_b64u_dec( $auth ) ) ) {
		return null;
	}
	return array( 'endpoint' => $endpoint, 'p256dh' => $p256, 'auth' => $auth, 'at' => time() );
}

function eg_pub_webapp_merge_subs( array $subs, array $sub ) {
	$subs = array_values( array_filter( $subs, function ( $x ) use ( $sub ) {
		return is_array( $x ) && ( $x['endpoint'] ?? '' ) !== $sub['endpoint'];
	} ) );
	$subs[] = $sub;
	return array_slice( $subs, -3 );
}

function eg_pub_webapp_add_sub( WC_Order $order, array $sub ) {
	$subs = (array) $order->get_meta( EG_PUB_WEBAPP_META );
	$order->update_meta_data( EG_PUB_WEBAPP_META, eg_pub_webapp_merge_subs( array_filter( $subs ), $sub ) );
	$order->save_meta_data();
}

/** Pedido nuevo de un cliente con cuenta que ya aceptó los avisos: hereda sus dispositivos. */
add_action( 'woocommerce_checkout_order_created', function ( $order ) {
	$s = eg_pub_webapp_settings();
	if ( ! $s['enabled'] || ! $s['push']['enabled'] || ! $order->get_customer_id() ) {
		return;
	}
	$subs = array_filter( (array) get_user_meta( $order->get_customer_id(), EG_PUB_WEBAPP_META, true ) );
	if ( $subs ) {
		$order->update_meta_data( EG_PUB_WEBAPP_META, array_values( $subs ) );
		$order->save_meta_data();
	}
} );

/* ------------------------------------------------------------------ push: aviso al cambiar el estado */

function eg_pub_webapp_push_statuses( array $s ) {
	if ( $s['push']['statuses'] ) {
		return $s['push']['statuses'];
	}
	$tw = array_map( function ( $st ) {
		return preg_replace( '/^wc-/', '', (string) $st );
	}, (array) get_option( 'wc_twilio_sms_send_sms_order_statuses', array() ) );
	return $tw ? array_values( $tw ) : array( 'processing', 'completed' );
}

add_action( 'init', function () {
	if ( ! function_exists( 'wc_get_order_statuses' ) ) {
		return;
	}
	$s = eg_pub_webapp_settings();
	if ( ! $s['enabled'] || ! $s['push']['enabled'] ) {
		return;
	}
	foreach ( array_keys( wc_get_order_statuses() ) as $status ) {
		$slug = preg_replace( '/^wc-/', '', $status );
		// Antes que Twilio SMS (prioridad 10), para poder evitar el SMS cuando el aviso push ha llegado.
		add_action( 'woocommerce_order_status_' . $slug, 'eg_pub_webapp_on_status', 5, 2 );
	}
}, 12 );

function eg_pub_webapp_on_status( $order_id, $order = null ) {
	$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}
	$s      = eg_pub_webapp_settings();
	$status = $order->get_status();
	if ( ! in_array( $status, eg_pub_webapp_push_statuses( $s ), true ) || ! array_filter( (array) $order->get_meta( EG_PUB_WEBAPP_META ) ) ) {
		return;
	}
	$res = eg_pub_webapp_push_order( $order, eg_pub_webapp_message( $order, $s ), eg_pub_webapp_order_url( $order ) );
	if ( $res['sent'] > 0 && $s['push']['replace_sms'] ) {
		$skip = function () {
			return array();
		};
		add_filter( 'pre_option_wc_twilio_sms_send_sms_order_statuses', $skip );
		$hook = 'woocommerce_order_status_' . $status;
		$undo = function () use ( &$undo, $skip, $hook ) {
			remove_filter( 'pre_option_wc_twilio_sms_send_sms_order_statuses', $skip );
			remove_action( $hook, $undo, 20 );
		};
		add_action( $hook, $undo, 20 );
	}
}

function eg_pub_webapp_order_url( WC_Order $order ) {
	return $order->get_customer_id() ? $order->get_view_order_url() : $order->get_checkout_order_received_url();
}

/** Texto del aviso: el propio del estado, si no el mismo del SMS de Twilio, si no uno genérico. */
function eg_pub_webapp_message( WC_Order $order, array $s ) {
	$status = $order->get_status();
	$tpl    = $s['push']['messages'][ $status ] ?? '';
	if ( '' === $tpl ) {
		$tpl = (string) get_option( 'wc_twilio_sms_' . $status . '_sms_template', '' );
	}
	if ( '' === $tpl ) {
		$tpl = (string) get_option( 'wc_twilio_sms_default_sms_template', '' );
	}
	if ( '' === $tpl ) {
		$tpl = 'Tu pedido #%order_id% está ahora: %order_status%.';
	}
	$rep = array(
		'%shop_name%'       => $s['name'],
		'%order_id%'        => $order->get_order_number(),
		'%order_count%'     => $order->get_item_count(),
		'%order_amount%'    => $order->get_total(),
		'%order_status%'    => wc_get_order_status_name( $status ),
		'%billing_name%'    => $order->get_formatted_billing_full_name(),
		'%shipping_name%'   => $order->get_formatted_shipping_full_name(),
		'%shipping_method%' => $order->get_shipping_method(),
		'%billing_first%'   => $order->get_billing_first_name( 'edit' ),
		'%billing_last%'    => $order->get_billing_last_name( 'edit' ),
	);
	return wp_strip_all_tags( str_replace( array_keys( $rep ), $rep, $tpl ) );
}

/** Envía un aviso a todos los dispositivos del pedido; quita los que ya no existen. */
function eg_pub_webapp_push_order( WC_Order $order, $message, $url ) {
	$s     = eg_pub_webapp_settings();
	$subs  = array_values( array_filter( (array) $order->get_meta( EG_PUB_WEBAPP_META ) ) );
	$icons = (array) get_option( EG_PUB_WEBAPP_OPT . '_icons', array() );
	$data  = array(
		'title' => '' !== $s['push']['title'] ? $s['push']['title'] : $s['name'],
		'body'  => $message,
		'url'   => $url,
		'icon'  => (string) ( $icons['icon-192'] ?? '' ),
		'tag'   => 'order-' . $order->get_id(),
	);
	$out   = array( 'sent' => 0, 'failed' => 0, 'removed' => 0, 'errors' => array() );
	$keep  = array();
	foreach ( $subs as $sub ) {
		$res = eg_pub_webapp_send( $sub, wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), $s['push']['subject'] );
		if ( true === $res ) {
			++$out['sent'];
			$keep[] = $sub;
		} elseif ( 'gone' === $res ) {
			++$out['removed'];
		} else {
			++$out['failed'];
			$out['errors'][] = $res;
			$keep[]          = $sub;
		}
	}
	if ( count( $keep ) !== count( $subs ) ) {
		$order->update_meta_data( EG_PUB_WEBAPP_META, $keep );
		$order->save_meta_data();
	}
	if ( $out['sent'] ) {
		$order->add_order_note( 'Aviso push enviado al móvil del cliente: ' . $message );
	}
	return $out;
}

/* ------------------------------------------------------------------ Web Push (RFC 8291 aes128gcm + VAPID RFC 8292) */

function eg_pub_b64u_enc( $bin ) {
	return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
}

function eg_pub_b64u_dec( $txt ) {
	$txt = strtr( (string) $txt, '-_', '+/' );
	return (string) base64_decode( $txt . str_repeat( '=', ( 4 - strlen( $txt ) % 4 ) % 4 ), true );
}

/** Punto público sin comprimir (65 bytes) de una clave EC P-256 de OpenSSL. */
function eg_pub_ec_raw_public( $key ) {
	$d = openssl_pkey_get_details( $key );
	return "\x04" . str_pad( $d['ec']['x'], 32, "\0", STR_PAD_LEFT ) . str_pad( $d['ec']['y'], 32, "\0", STR_PAD_LEFT );
}

/** Clave pública de OpenSSL a partir del punto sin comprimir. */
function eg_pub_ec_public_from_raw( $raw ) {
	$der = hex2bin( '3059301306072a8648ce3d020106082a8648ce3d030107034200' ) . $raw;
	$pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
	return openssl_pkey_get_public( $pem );
}

function eg_pub_ec_new() {
	return openssl_pkey_new( array( 'curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC ) );
}

/** Claves VAPID de la web (se crean la primera vez). */
function eg_pub_webapp_vapid() {
	$v = get_option( EG_PUB_WEBAPP_VAPID, array() );
	if ( ! empty( $v['public'] ) && ! empty( $v['private'] ) ) {
		return $v;
	}
	if ( ! function_exists( 'openssl_pkey_derive' ) ) {
		return array();
	}
	$key = eg_pub_ec_new();
	if ( ! $key || ! openssl_pkey_export( $key, $pem ) ) {
		return array();
	}
	$v = array( 'public' => eg_pub_b64u_enc( eg_pub_ec_raw_public( $key ) ), 'private' => $pem );
	update_option( EG_PUB_WEBAPP_VAPID, $v, false );
	return $v;
}

/** Firma ES256 (DER de OpenSSL a r||s de 64 bytes). */
function eg_pub_es256( $data, $pem ) {
	if ( ! openssl_sign( $data, $der, $pem, OPENSSL_ALGO_SHA256 ) ) {
		return '';
	}
	$pos  = 2 + ( ord( $der[1] ) & 0x80 ? ( ord( $der[1] ) & 0x7f ) : 0 );
	$rlen = ord( $der[ $pos + 1 ] );
	$r    = substr( $der, $pos + 2, $rlen );
	$pos  = $pos + 2 + $rlen;
	$slen = ord( $der[ $pos + 1 ] );
	$s    = substr( $der, $pos + 2, $slen );
	$fix  = function ( $n ) {
		return str_pad( ltrim( $n, "\0" ), 32, "\0", STR_PAD_LEFT );
	};
	return $fix( $r ) . $fix( $s );
}

/** Cifra el contenido para una suscripción (aes128gcm, un solo registro). */
function eg_pub_webapp_encrypt( $payload, $ua_public_b64, $auth_b64 ) {
	$ua_public = eg_pub_b64u_dec( $ua_public_b64 );
	$auth      = eg_pub_b64u_dec( $auth_b64 );
	$local     = eg_pub_ec_new();
	$peer      = eg_pub_ec_public_from_raw( $ua_public );
	if ( ! $local || ! $peer ) {
		return false;
	}
	$secret = openssl_pkey_derive( $peer, $local, 32 );
	if ( false === $secret ) {
		return false;
	}
	$as_public = eg_pub_ec_raw_public( $local );
	$ikm       = hash_hkdf( 'sha256', $secret, 32, "WebPush: info\0" . $ua_public . $as_public, $auth );
	$salt      = random_bytes( 16 );
	$cek       = hash_hkdf( 'sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt );
	$nonce     = hash_hkdf( 'sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt );
	$tag       = '';
	$cipher    = openssl_encrypt( $payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag );
	if ( false === $cipher ) {
		return false;
	}
	return $salt . pack( 'N', 4096 ) . chr( strlen( $as_public ) ) . $as_public . $cipher . $tag;
}

/** Envía un aviso. true si llegó, 'gone' si el dispositivo ya no existe, o el texto del error. */
function eg_pub_webapp_send( array $sub, $payload, $subject ) {
	$vapid = eg_pub_webapp_vapid();
	if ( empty( $vapid['private'] ) ) {
		return 'Sin claves VAPID (falta OpenSSL con curvas EC).';
	}
	$body = eg_pub_webapp_encrypt( $payload, $sub['p256dh'], $sub['auth'] );
	if ( false === $body ) {
		return 'No se ha podido cifrar el aviso.';
	}
	$parts = wp_parse_url( $sub['endpoint'] );
	$jwt_h = eg_pub_b64u_enc( wp_json_encode( array( 'typ' => 'JWT', 'alg' => 'ES256' ) ) );
	$jwt_c = eg_pub_b64u_enc( wp_json_encode( array( 'aud' => $parts['scheme'] . '://' . $parts['host'], 'exp' => time() + 12 * HOUR_IN_SECONDS, 'sub' => $subject ), JSON_UNESCAPED_SLASHES ) );
	$sig   = eg_pub_es256( $jwt_h . '.' . $jwt_c, $vapid['private'] );
	if ( '' === $sig ) {
		return 'No se ha podido firmar el aviso.';
	}
	$res = wp_remote_post( $sub['endpoint'], array(
		'timeout' => 10,
		'headers' => array(
			'Content-Type'     => 'application/octet-stream',
			'Content-Encoding' => 'aes128gcm',
			'TTL'              => '3600',
			'Urgency'          => 'high',
			'Authorization'    => 'vapid t=' . $jwt_h . '.' . $jwt_c . '.' . eg_pub_b64u_enc( $sig ) . ', k=' . $vapid['public'],
		),
		'body'    => $body,
	) );
	if ( is_wp_error( $res ) ) {
		return $res->get_error_message();
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( $code >= 200 && $code < 300 ) {
		return true;
	}
	if ( 404 === $code || 410 === $code ) {
		return 'gone';
	}
	return 'HTTP ' . $code . ': ' . substr( (string) wp_remote_retrieve_body( $res ), 0, 200 );
}
