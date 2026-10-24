# Registro de cambios de EG Publicación

Historial del plugin EG Publicación, de lo más nuevo a lo más antiguo. Es el registro común de **Claude y ChatGPT**, que modifican este repositorio en paralelo: antes de cambiar o decidir nada sobre el plugin, léelo junto con la [última release](https://github.com/enricgomez/mcp_wpplugin/releases/latest), y añade aquí tu cambio en el mismo PR (ver «Cómo trabajamos en este repositorio» en el [README](README.md)).

Cada entrada indica la versión, la fecha en que se publicó la release (UTC), el PR, qué cambia y a qué tipología, módulo y webs afecta. Las versiones siguen `MAYOR.MENOR.PARCHE`: parche para correcciones, menor para funciones nuevas compatibles, mayor si algo deja de funcionar como antes.

## Sin publicar

Cambios ya fusionados en `main` que aún no tienen release (no han subido la versión). Al publicar la siguiente versión, se mueven a su entrada.

- Nada por ahora.

## 2.12.0 · 24/10/2026 · PR pendiente (Claude)

- `/template` (Elementor) admite `id` + `patch` para editar una plantilla o página que ya existe sin rehacerla: `patch` = `{"<id de elemento>": {ajustes}}` y esos ajustes sustituyen a los del elemento en su JSON de Elementor (p. ej. la lista de enlaces de una columna del pie). Solo toca `_elementor_data`; devuelve `patched` y `missing`.
- Webs: taxi-calella.com (enlace «Blog» en el pie, plantilla Main Footer). El resto no cambia: sin `id` y `patch`, `/template` funciona igual que antes.

## 2.11.0 · 23/10/2026 · PR #28 (Claude)

- `/template` (Elementor) admite `conditions`: condiciones de visualización del Theme Builder de Elementor Pro (p. ej. `["include/singular/post"]`, `["include/archive/category", "include/archive/post_tag"]`; `[]` las quita). Guarda `_elementor_conditions` y rehace la caché de condiciones de Elementor Pro (si su API no está, la escribe a mano). Se ponen solo en la plantilla original; las traducciones se enlazan con `translation_of`.
- `/template` admite también `status` (`publish` por defecto, `draft`, `private`) y `settings` (ajustes de la plantilla, `_elementor_page_settings`, p. ej. la entrada de vista previa). Con `type: "single-post"` o `"archive"` crea plantillas de entrada o de archivo del blog.
- Webs: taxi-calella.com (plantillas del blog). El resto no cambia: sin esos parámetros `/template` funciona igual que antes.

## 2.10.0 · 23/10/2026 · PR #27 (Claude)

- `/landing` (Elementor) admite `elements`: JSON de Elementor propio para la página nueva, en lugar del de la maestra. De la maestra solo se copian la plantilla de página y sus ajustes, así una página existente sirve de base para rehacerla en borrador sin tocar la publicada. Los marcadores, `images` y `widgets` se aplican también sobre ese JSON.
- `dry_run: true` con `data: true` devuelve además el JSON de Elementor completo de la maestra (solo lectura), para usarlo de base.
- El resto no cambia: sin `elements` ni `data`, `/landing` funciona igual que antes. Esta entrada se añadió después de fusionar el PR.

## 2.9.6 · 22/10/2026 · PR #23, #24 y #25 (ChatGPT y Claude)

- **Nuevo `POST /post-media`** (Blog, `includes/post-media.php`): asigna como imagen destacada una imagen que ya está en la biblioteca (`post_id`, `attachment_id`, `dry_run`). Solo llama a `set_post_thumbnail`: no re-guarda el contenido ni toca los trabajos del editor de traducción de WPML. Con `dry_run: true` devuelve la imagen actual en `previous_attachment_id` sin cambiar nada (PR #25, ChatGPT).
- **IndexNow opcional** (`includes/indexnow.php`, PR #23, ChatGPT): avisa a IndexNow de las entradas publicadas por EG Publicación (también las traducciones), sin duplicar avisos. Viene **apagado** (opción `eg_pub_indexnow_enabled`); se enciende en Ajustes → EG IndexNow. Antes de activarlo en una web, comprobar que ningún otro plugin (SEO, caché o CDN) ya envía IndexNow.
- `CHANGELOG.md` y la sección «Cómo trabajamos en este repositorio» del README (PR #24, Claude).
- Webs: desire-vips.com (imagen 57310 de los posts 57301/57303). El escenario de Make de Desire Vips (10022329) aún no ofrece la ruta `post-media` y solo devuelve el código HTTP. Esta entrada se añadió después de la release.

## 2.9.5 · 22/10/2026 · PR #21 y #22 (ChatGPT)

- **Nuevo `POST /seo`** (Gestión de la web): revisa y edita título SEO, descripción, frase clave y canonical de entradas, páginas y tipos de contenido públicos (también Elementor y WooCommerce), por `ids` o `paths`, con sobrescrituras por idioma WPML en `langs`. Por defecto solo previsualiza; guarda con `apply: true`.
- Guarda en los metadatos de Yoast SEO o de Rank Math, según el que esté activo. `/publish` y `/landing` usan ese mismo guardado; `/text` busca y sustituye también en metadatos de Yoast; `/product` admite claves de Yoast.
- Webs: pensado para desire-vips.com (Yoast). El resto con Rank Math no cambia de comportamiento.
- Pendiente según el PR: validar en staging con Yoast, WPML y CPT antes de actualizar Desire Vips.

## 2.9.4 · 22/10/2026 · PR #19 y #20 (ChatGPT)

- `/publish` (Blog) admite `status`: `draft`, `pending`, `private` o `publish`. Sin `status` publica como antes (inmediata o programada con `date`); una fecha futura con `draft` sigue en borrador.
- Si Yoast SEO está activo, `seo_title`, `seo_description` y `focus_keyword` se guardan también en `_yoast_wpseo_*`. Rank Math se sigue escribiendo.
- Webs: desire-vips.com (estaba fijada en la 2.9.3; hay que quitar «Mantener esta web en la versión» para instalarla). El escenario de Make de Desire Vips sigue inactivo hasta tener esta versión.

## 2.9.3 · 22/10/2026 · PR #18 (Claude)

- `/lang-links` guarda también el enlace de cada idioma en las traducciones de cadenas de WPML cuyo original es la URL de `from` (p. ej. un campo de opciones traducido como «admin text»). La respuesta las lista en `strings`.
- Webs: campingelfar.com (botón Reservar de la cabecera, campo `[general-options]url-motres`).

## 2.9.2 · 21/10/2026 · PR #17 (Claude)

- `/lang-links` cambia también los elementos de menú con enlace personalizado (`_menu_item_url`) y devuelve `elsewhere`: dónde más aparece el dominio sin cambiarlo.
- Webs: campingelfar.com (enlace de Witbooking).

## 2.9.1 · 21/10/2026 · PR #16 (Claude)

- `/lang-links` escribe una sola vez el enlace de cada idioma en el JSON de Elementor y el contenido de cada traducción, en lugar de cambiarlo al servir la página. Si se desactiva el plugin, los enlaces siguen bien. Añade `translate_templates` y `dry_run`.
- Se elimina el buffer de salida y la opción `eg_pub_lang_links` de la 2.9.0 (no se llegó a usar en ninguna web).

## 2.9.0 · 21/10/2026 · PR #15 (Claude)

- Nuevo módulo `enlaces` (Gestión de la web, `includes/enlaces.php`) con `POST /lang-links`: enlace por idioma (p. ej. motor de reservas). Sustituido por el enfoque de la 2.9.1.
- Webs: campingelfar.com.

## 2.8.0 · 21/10/2026 · PR #14 (Claude)

- Los ajustes pasan de una casilla por endpoint a cuatro tipologías: **Blog** (`/publish`), **Elementor** (`/landing`, `/template`, `/append`), **WooCommerce** (`/product`) y **Gestión de la web** (`/text`, `/menu`, `/redirect`, `/status`). Opción nueva `eg_pub_types`, deducida de la antigua al actualizar (las webs no cambian de comportamiento).
- Productos pasa de `includes/clientes/miriamtirado.php` a `includes/woocommerce.php`. Desde aquí, lo nuevo va como módulo de su tipología, no como módulo de cliente.
- Incluye el PR #13 (`.claude/settings.json`, sin efecto en el plugin).

## 2.7.2 · 21/10/2026 · PR #12 (Claude)

- Las actualizaciones se instalan en la carpeta actual del plugin (`EG_PUB_BASENAME`) en vez de crear siempre `eg-publicacion/`, para no dejar dos copias.
- Webs: miriamtirado.com (tenía el plugin en `mcp_wpplugin-main`; hubo que dejarla bien a mano una vez).

## 2.7.1 · 21/10/2026 · PR #11 (Claude)

- Enlace «Ajustes» en la fila del plugin, en el listado de plugins.

## 2.7.0 · 21/10/2026 · PR #10 (Claude)

- Módulo de cliente «Productos» con `POST /product`: edita productos de WooCommerce en todos sus idiomas (textos, quitar bloques, sustituir texto, campos de JetEngine, SEO de Rank Math, categorías), con `dry_run`. Guarda sin `wp_update_post` para no relanzar la traducción de WPML.
- Webs: miriamtirado.com (sacar Rita Bumbum 4 de la preventa).

## 2.6.2 · 21/10/2026 · PR #9 (Claude)

- `/redirect` conserva el prefijo de idioma (`/ca/`) en los destinos relativos (usa `get_option( 'home' )` en lugar de `home_url()`).
- Webs: adorna.es (15 redirecciones en catalán).

## 2.6.1 · 21/10/2026 · PR #8 (Claude)

- Módulo opcional «Contenido» con `POST /status`: cambia el estado de entradas por ruta, ID o categoría, con traducciones de WPML y `dry_run`, sin `wp_update_post`.
- Webs: adorna.es.

## 2.6.0 · 20/10/2026 · PR #7 (Claude)

- Interruptor por módulo (opción `eg_pub_modules`); las webs con el interruptor antiguo encendido conservan todos los módulos generales.
- Casilla «Mantener esta web en la versión» o `EG_PUB_HOLD_VERSION` en `wp-config.php`.
- Con WordPress 6.9: 9 abilities `eg-publicacion/*` para el MCP Adapter oficial.

## 2.5.5 · 20/10/2026 · PR #6 (Claude)

- `POST /template` y `POST /append`: plantillas guardadas de Elementor y sección al pie de páginas. Solo tocan `_elementor_data`.
- Webs: adorna.es.

## 2.5.4 · 20/10/2026 · PR #5 (Claude)

- `POST /text`: busca y sustituye textos visibles en toda la web (títulos, contenido, Elementor, menús, Rank Math, algunas opciones) y vacía cachés.
- Webs: adorna.es («reformas» → «carpintería»).

## 2.5.3 · 20/10/2026 · PR #4 (Claude)

- `/menu` coloca las páginas en el orden enviado.
- Webs: adorna.es.

## 2.5.2 · 20/10/2026 · PR #3 (Claude)

- `POST /menu`: añade páginas a un menú (por idioma con WPML), sin duplicar, con `remove_titles` y `dry_run`.
- Webs: adorna.es.

## 2.5.1 · 20/10/2026 · PR #2 (Claude)

- `/landing` con `"layout": "widgets"`: reparte el cuerpo en widgets de título, texto e imagen de Elementor.
- Webs: adorna.es.

## 2.5.0 · 20/10/2026 · PR #1 (Claude)

- Módulo opcional «Landings de Elementor» (desactivado por defecto): `POST /landing` (clona una página maestra con marcadores `{{clave}}`, imágenes, Rank Math y WPML) y `POST /redirect` (Rank Math).
- El aviso de «otra copia cargada» indica la ruta real.
- Webs: adorna.es (landings locales).

## 2.4.0 · 14/10/2026 (Claude)

- `/publish` crea la categoría si no existe, con `category_name`; con WPML la asigna al idioma y la enlaza con la del original.

## 2.3.0 · 14/10/2026 (Claude)

- Primera versión en este repositorio: plugin normal (antes mu-plugin `eg-studio-rest-publisher.php`, v2.2.0) con `POST /publish` para el escenario de Make «Blogs clientes: Notion → WordPress», y actualizaciones automáticas desde las releases de GitHub.
- Release automática: al subir a `main` una versión nueva, la Action crea la release `vX.Y.Z` con `eg-publicacion.zip`.
- Webs: blogs de hotelmiamicalella.com, campingelfar.com, taxi-santasusanna.com y taxi-calella.com.
