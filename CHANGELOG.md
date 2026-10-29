# Registro de cambios de EG Publicación

Historial del plugin EG Publicación, de lo más nuevo a lo más antiguo. Es el registro común de los cambios del repositorio: antes de cambiar o decidir nada sobre el plugin, léelo junto con la [última release](https://github.com/enricgomez/mcp_wpplugin/releases/latest), y añade aquí tu cambio en el mismo PR (ver «Cómo trabajamos en este repositorio» en el [README](README.md)).

Cada entrada indica la versión, la fecha en que se publicó la release (UTC), el PR, qué cambia y a qué tipología, módulo y webs afecta. Las versiones siguen `MAYOR.MENOR.PARCHE`: parche para correcciones, menor para funciones nuevas compatibles, mayor si algo deja de funcionar como antes.

## Sin publicar

Cambios ya fusionados en `main` que aún no tienen release (no han subido la versión). Al publicar la siguiente versión, se mueven a su entrada.

- Nada por ahora.

## 2.34.0 · 29/10/2026 · PR pendiente (Claude)

- `/menu`: `items` acepta también enlaces personalizados `{url, title, position?, parent?}`, p. ej. el ancla de una sección de una página. Se reconocen por su URL: si ya existe uno con esa URL, se actualiza en vez de duplicarse. Antes solo se podían añadir páginas, y una misma página no podía estar dos veces en el menú.
- Webs: sonovet.net (elemento «Per a clíniques veterinàries / Para clínicas veterinarias» al apartado de clínicas de Contacte). En las demás no cambia nada mientras no se use.

## 2.33.3 · 29/10/2026 · PR #67 (Claude)

- `/import-wp`: reconoce las imágenes del origen con y sin `www` (en viajestauro.com las galerías no se trasladaban porque las imágenes iban con www). Quita el CSS de galería que imprime el origen y los `class=""` vacíos, y crea los posts con los comentarios cerrados.
- Webs: test-viajestauro.com.egserver.cloud.

## 2.33.2 · 29/10/2026 · PR #66 (Claude)

- `/import-wp`: nuevos `include` y `exclude` (IDs de los posts en el origen) para elegir o descartar posts, p. ej. versiones duplicadas de un post con el mismo slug.
- Webs: test-viajestauro.com.egserver.cloud.

## 2.33.1 · 29/10/2026 · PR #65 (Claude)

- `/import-wp`: nuevo `lang` para orígenes con WPML (solo trae los posts de ese idioma; sin él, la API devuelve todos los idiomas y las traducciones con el mismo slug se pisaban). `dry_run` devuelve el idioma de cada post.
- Webs: test-viajestauro.com.egserver.cloud.

## 2.33.0 · 29/10/2026 · PR #64 (Claude)

- Nuevo endpoint **`/import-wp`** (módulo «Importar blog», tipología Gestión de la web): traslada los posts publicados de otra web WordPress (`source`) leyendo su API pública. Mantiene slug, fecha, título, extracto, categorías, etiquetas y la meta descripción de Yoast; sube a la biblioteca la imagen destacada y las imágenes del texto (el archivo original, sin el sufijo de tamaño) y cambia el texto para que apunte a ellas; las galerías pasan a `[gallery]` con las imágenes nuevas. Quita los `data-start`/`data-end` de los textos pegados de ChatGPT. Por tandas (`page`, `per_page`, 3 por defecto; devuelve `next_page`), `slugs` para posts concretos, `status` y `dry_run` para listar. Repetirlo actualiza los posts sin duplicar imágenes. Cada post devuelve `left_urls` con lo que aún apunte a imágenes del origen.
- Webs: test-viajestauro.com.egserver.cloud (blog de viajestauro.com). En las demás no cambia nada mientras no se use.

## 2.32.2 · 29/10/2026 · PR #63 (Claude)

- `/publish`: el ID de Notion se busca con y sin guiones. Una traducción enviada por Make (ID con guiones) no encontraba el original publicado con una llamada directa (ID sin guiones) y daba «El post original en castellano todavía no está en WordPress» (409). Lo mismo al volver a publicar un post: ahora se actualiza en vez de duplicarse.
- Webs: taxi-calella.com (traducciones de La Roca Village). En las demás solo cambia la búsqueda.

## 2.32.1 · 29/10/2026 · PR #62 (Claude)

- `/item`: nuevo `set.author` (ID, usuario o email de un usuario que pueda escribir entradas) para cambiar quién firma una entrada ya publicada sin reenviarla; el estado (`dry_run`) devuelve `author`.
- Webs: taxi-calella.com (los posts ya publicados pasan a «Taxi Calella»). En las demás no cambia nada mientras no se use.

## 2.32.0 · 29/10/2026 · PR #61 (Claude)

- `/publish`: nuevo `author` (ID, nombre de usuario o email de un usuario que pueda escribir entradas) para que los posts del blog los firme ese usuario, con su avatar. Queda guardado en la web (opción `eg_pub_blog_author`) y se aplica también a los posts que llegan sin el parámetro (escenario de Notion) y a los que se vuelven a publicar. El plugin sigue actuando con el usuario configurado en Ajustes, así que los demás endpoints no cambian de permisos. La respuesta devuelve `author`.
- Webs: taxi-calella.com (posts firmados por «Taxi Calella»). En las demás no cambia nada mientras no se use.

## 2.31.0 · 29/10/2026 · PR #60 (Claude)

- `/template`: nuevo `patch_widgets` (`{"<tipo de widget>": {ajustes}}`) con `ids` (lista de páginas o plantillas) o `id`: cambia esos ajustes en todos los widgets de ese tipo de cada página, sin saber sus ids de elemento. Guarda la copia en `_eg_pub_elementor_backup`, borra el CSS de Elementor y vacía la caché de WP Rocket de cada página. Devuelve un resultado por página.
- Webs: taxi-calella.com (los carruseles de imágenes de los traslados salían rotos en público: el lazy load del carrusel de Elementor choca con las etiquetas `<picture>` de Imagify; se apaga con `{"image-carousel": {"lazyload": ""}}`). En las demás no cambia nada mientras no se use.

## 2.30.0 · 27/10/2026 · PR #58 (Claude)

- `/item`: nuevo `set.parent` para cambiar la página superior de una página (o de otro tipo jerárquico). `0` la deja sin superior. Rechaza tipos no jerárquicos, la propia entrada, otro tipo o una entrada que cuelgue de ella. El slug se recalcula con la nueva superior.
- Webs: test-neptunoapartments.com.egserver.cloud (Shama Spa y Gastronomía pasan a colgar de Instalaciones, como en las URLs de GNA). En las demás no cambia nada mientras no se use.

## 2.29.0 · 27/10/2026 · PR #57 (Claude)

- `/cpt`: nuevo **`listings`** para crear o actualizar (por `key`) plantillas de listado de JetEngine hechas con Elementor para un CPT (`title`, `post_type`, `elements` con el JSON de Elementor y `settings` opcional). Devuelve el ID que va en el widget Listing Grid (`lisitng_id`). La lista de `dry_run` añade los listados existentes y `listing_legacy_disabled` (si es true, el Listing Grid ignora sus filtros propios y hay que filtrar con el Query Builder).
- Webs: test-viajestauro.com.egserver.cloud (listado y página de eventos). En las demás no cambia nada mientras no se use.

## 2.28.1 · 27/10/2026 · PR #56 (Claude)

- `/term`: con WPML, cambiar el nombre o la descripción de un término traducido (p. ej. una categoría en catalán) fallaba con «El slug … ya lo está utilizando otro término», porque WPML pasa la comprobación de slug al idioma principal. Ahora se cambia al idioma del término mientras se guarda. El texto de debajo y el SEO ya funcionaban.
- Webs: nilacakes.com (versión catalana). En las demás no cambia nada.

## 2.28.0 · 27/10/2026 · PR #55 (Claude)

- `/cpt`: nuevo **`entries`** para crear o actualizar (por tipo y slug) entradas de un CPT de JetEngine con título, contenido, extracto, estado, orden, imagen destacada, términos y campos de JetEngine (`meta`). Las fechas en AAAA-MM-DD se guardan como timestamp si el campo lo es, las galerías como lista de IDs y los switcher como true/false. Los campos que no existen en el CPT se devuelven en `unknown_meta` sin guardarse.
- Webs: test-viajestauro.com.egserver.cloud (dar de alta los proyectos como eventos). En las demás no cambia nada mientras no se use.

## 2.27.2 · 27/10/2026 · PR #54 (Claude)

- `/cpt`: busca la herramienta add-cpt de JetEngine a partir de la carpeta real del plugin JetEngine y, si no la encuentra, dice en el error qué ruta ha probado. La 2.27.1 seguía sin cargarla en test-viajestauro.
- Webs: test-viajestauro.com.egserver.cloud.

## 2.27.1 · 27/10/2026 · PR #53 (Claude)

- Corrige la 2.27.0: `/cpt` respondía que JetEngine no traía la herramienta add-cpt porque JetEngine solo carga sus herramientas MCP cuando arranca su servidor MCP. Ahora carga el fichero de la herramienta si hace falta.
- Webs: test-viajestauro.com.egserver.cloud.

## 2.27.0 · 27/10/2026 · PR #52 (Claude)

- Nuevo endpoint **`/cpt`** (módulo «JetEngine», tipología Gestión de la web): crea tipos de contenido (CPT) y taxonomías de JetEngine con sus campos, sin entrar en el panel. `post_type` usa el mismo formato que la herramienta MCP «add-cpt» de JetEngine (`general_settings`, `labels`, `advanced_settings`, `meta_fields` con `name`, `title`, `type`, `options`…); `taxonomies` crea taxonomías (`name`, `slug`, `object_type`, `hierarchical`) y sus `terms`. Lo que ya existe con el mismo slug no se toca y se devuelve como `existing`. `dry_run` (o una llamada vacía) lista los CPT y taxonomías de JetEngine con sus campos. Tras crear, regenera las URL en la siguiente carga. Necesita una versión de JetEngine que traiga sus herramientas MCP (si no, responde con un error claro).
- Webs: test-viajestauro.com.egserver.cloud (CPT «Proyectos» con sus campos y la taxonomía de categorías). En las demás no cambia nada mientras no se use.

## 2.26.1 · 27/10/2026 · PR #51 (Claude)

- Corrige la 2.26.0: `/template` con `id` y `patch`, `replace` o `remove` daba un error crítico (500) porque la función no recibía `remove`. Con la 2.26.0 cualquier `patch` de `/template` fallaba sin cambiar nada.
- Webs: las que tengan la 2.26.0 (test-neptunoapartments.com).

## 2.26.0 · 27/10/2026 · PR #50 (Claude)

- `/template` con `id`: nuevo **`remove`** (`["<id de elemento>", …]`) quita elementos con sus hijos, también secciones de primer nivel, sin reenviar la página. Se combina con `patch` y `replace`. Desde esta versión, `patch`, `replace` y `remove` guardan antes el JSON anterior en `_eg_pub_elementor_backup` (como `copy_from`) y vacían la caché de WP Rocket de esa página.
- Webs: test-neptunoapartments.com (quitar de la home las secciones del hotel: TripAdvisor, eventos, preguntas frecuentes). En las demás no cambia nada mientras no se use.

## 2.25.0 · 27/10/2026 · PR #49 (Claude)

- Nuevo endpoint **`/item`** (módulo «Fichas», tipología Gestión de la web): edita una entrada existente de cualquier tipo y sus campos de ACF. `dry_run` lista los campos con su tipo y valor; `set` cambia título, slug, extracto, contenido, estado u orden; `acf` cambia campos (imágenes y galerías por ID, URL o `{"url","alt"}`, sin duplicar subidas); `featured_image` pone la destacada. Guarda sin `wp_update_post` para que WPML no traduzca solo.
- Webs: test-neptunoapartments.com (convertir las fichas de habitaciones del hotel en los 8 apartamentos). En las demás no cambia nada mientras no se use.

## 2.24.1 · 27/10/2026 · PR #60 (Claude)

- `/product`: al quitar o añadir términos (`remove_terms`, `add_terms`) invalida la caché de términos del producto en WooCommerce. Con Redis u otra caché de objetos, la ficha seguía mostrando los atributos de antes (p. ej. un alérgeno quitado) hasta vaciar la caché a mano.
- Webs: las que usan `/product` con caché de objetos (nilacakes.com). Corrección, no cambia nada más.

## 2.24.0 · 27/10/2026 · PR #47 (Claude)

- `/product`: nuevo campo **`slug`** en `set` o `langs`. Cambia la URL del producto (con `wp_unique_post_slug`, así nunca choca con otra) y guarda la anterior en `_wp_old_slug`, como hace el editor de WordPress: la URL vieja redirige con un 301 a la nueva. `dry_run` muestra el cambio en `post_name`.
- Webs: nilacakes.com (slugs descriptivos para los productos con nombres genéricos, p. ej. `/producto/pistacho/` → `/producto/cookie-pistacho/`). En las demás no cambia nada mientras no se use.

## 2.23.0 · 27/10/2026 · PR #60 (Claude)

- `/menu`: **`labels`** (`{"id de elemento": "texto"}`) cambia solo el texto de elementos que ya están en el menú, sin tocar su posición ni su padre. Hasta ahora, cambiar el texto pasando la página en `items` la movía a la posición de la lista y la sacaba de su submenú. **`keep_place: true`** deja en su sitio las páginas que ya están y añade las nuevas al final. Cada elemento de `items` admite `position` y `parent`. `dry_run` devuelve también `parent` y `order`. Sin estos parámetros funciona igual que antes.
- Webs: sonovet.net (textos cortos de los servicios en el menú y nuevo acceso «Per a clíniques veterinàries»). En las demás no cambia nada mientras no se use.

## 2.22.0 · 27/10/2026 · PR #44 (Claude)

- `/menu`: `create: true` crea el menú si no existe (con `menu` como nombre), para montar webs nuevas desde Make, y `location` lo asigna a una ubicación del tema (p. ej. `menu-1` en Hello Elementor). Sin estos parámetros funciona igual que antes.
- Webs: test-viajestauro.com.egserver.cloud (menú principal y del pie). En las demás no cambia nada.

## 2.21.0 · 27/10/2026 · PR #43 (Claude)

- **Nuevo `POST /kit`** (tipología Elementor, módulo `kit`, `includes/kit.php`): estilos globales del kit activo de Elementor sin entrar en wp-admin. `colors` y `typography` cambian los colores y tipografías del sistema (`primary`, `secondary`, `text`, `accent`); `custom_colors` y `custom_typography` añaden o cambian los globales propios por `_id`; `settings` fusiona cualquier otro ajuste del kit (ancho del contenedor, botones, enlaces…). `fonts` (`[{"family", "css_url"}]`) descarga los woff2 de una hoja `@font-face` (p. ej. Fontshare) a `uploads/eg-fonts/`, la sirve en local y añade la familia al selector de fuentes de Elementor (grupo «Fuentes propias»). Por defecto solo previsualiza; `apply: true` guarda y vacía la caché de CSS de Elementor. Pide `manage_options`.
- **Nuevo `POST /media`** (mismo módulo): `items` (`[{"url", "alt", "title", "key"}]`) descarga imágenes a la biblioteca y devuelve `id` y `src`, para usarlas en el JSON de `/template` y `/landing`. Con `key`, repetir la llamada devuelve la imagen ya subida en lugar de duplicarla. Pide `upload_files`.
- Webs: test-viajestauro.com.egserver.cloud (montaje de la web nueva). En las demás no cambia nada mientras no se use.

## 2.20.0 · 27/10/2026 · PR #60 (Claude)

- `/template` con `id` admite **`copy_from`**: copia a la página `id` el JSON de Elementor, sus ajustes, la plantilla de página y el SEO (título, descripción y frase clave de Yoast o Rank Math) de otra página, sin tocar su URL, su slug ni su idioma en WPML. Con `copy_title: true` copia también el título. Antes guarda el JSON anterior en `_eg_pub_elementor_backup` y después vacía la caché de Elementor y la de esa página en WP Rocket.
- Webs: sonovet.net (sustituir las páginas publicadas por los borradores revisados del Plan SEO). En las demás no cambia nada mientras no se use.

## 2.19.0 · 27/10/2026 · PR #41 (Claude)

- `/template` con `id` admite **`replace`**: `{"<id de elemento>": {elemento completo}}` sustituye ese elemento entero, con sus hijos, en el JSON de Elementor de una plantilla o página. Sirve para reestructurar una sección (añadir una imagen, pasar a dos columnas) sin reenviar la página completa por `/landing`. Se aplica antes que `patch` y se pueden mandar los dos; la respuesta trae `replaced`.
- Webs: sonovet.net (ajustes estéticos del borrador de la home). En las demás no cambia nada mientras no se use.

## 2.18.0 · 27/10/2026 · PR #40 (Claude)

- **Nuevo `POST /rocket`** (Gestión de la web, módulo `rendimiento`, `includes/rendimiento.php`): ajustes de WP Rocket sin entrar en wp-admin. `add`/`remove` añaden o quitan valores de las listas (`exclude_lazyload`, `cache_reject_uri`, `cache_reject_cookies`, `exclude_css`, `exclude_js`, `exclude_defer_js`, `delay_js_exclusions`) sin tocar el resto, y `set` cambia interruptores (`lazyload`, `async_css`, `remove_unused_css`, `delay_js`...). Por defecto solo previsualiza; `apply: true` guarda y vacía la caché. Sin parámetros devuelve los ajustes y si la licencia parece oficial (las GPL no tienen CSS no usado ni CSS crítico). Pide `manage_options` al autor configurado.
- `/term`: el texto de debajo del listado (`bottom_description`) se puede editar también en wp-admin, en la pantalla de la categoría («Texto debajo del listado»). Sus preguntas frecuentes (h3 + respuesta bajo un h2 de preguntas frecuentes/FAQ) salen como schema `FAQPage` en el archivo de la categoría.
- Webs: nilacakes.com (excluir del lazy load las imágenes de la primera pantalla, sacar `resumen-de-compra` de la caché y FAQ de la categoría Cookies). En las demás no cambia nada mientras no se use.

## 2.17.0 · 27/10/2026 · PR #39 (Claude)

- **Nuevo `POST /update`** (`includes/actualizar.php`): instala al momento la última release de GitHub, sin esperar a la actualización automática de WordPress (que mira GitHub cada 6 horas y se aplica en el cron). Está activo en todas las webs, sea cual sea su tipología, con la clave X-EG-Key de siempre, así que se llama desde el escenario de Make de cada web con `path: update`. `dry_run: true` solo devuelve la versión instalada y la última. Respeta «Mantener esta web en la versión». Deja el plugin activo (fuera del cron, WordPress lo desactiva al actualizarlo) y devuelve la versión que queda instalada.
- Webs: todas. Esta versión llega a cada web por la actualización automática; desde entonces las siguientes se pueden instalar al momento con `/update`.

## 2.16.0 · 27/10/2026 · PR #38 (Claude)

- `/term` (Gestión de la web): nuevo campo `bottom_description`, el texto largo de una categoría que va **debajo** del listado de productos para no empujar los productos hacia abajo (la `description` de WooCommerce sale encima). Se guarda en el metadato `eg_pub_bottom_description` y se pinta tras el listado (`woocommerce_after_shop_loop`, que lanzan WooCommerce y el widget «Productos de archivo» de Elementor Pro). Si una plantilla no lo muestra, el shortcode `[eg_texto_categoria]` lo coloca donde se ponga; nunca sale dos veces.
- Webs: nilacakes.com (categoría Cookies). En las demás no cambia nada mientras no se use el campo.

## 2.15.0 · 26/10/2026 · PR #37 (Claude)

- **Nuevo `POST /term`** (Gestión de la web, `includes/terminos.php`): cambia el nombre, la descripción y el SEO (título, descripción y frase clave, en Rank Math o Yoast) de una categoría de productos o de otro término público, elegido por `slug` o `id`. Por defecto solo previsualiza; guarda con `apply: true`. No toca el slug. La descripción admite HTML de entradas (h2, h3, listas, enlaces).
- Webs: nilacakes.com (texto y SEO de la categoría Cookies). El resto no cambia: es un endpoint nuevo.

## 2.14.0 · 24/10/2026 · PR #36 (Claude)

- FAQ (Blog): la primera vez que una web recibe `faqs` en `/publish`, queda activado por defecto (opción `eg_pub_faq_auto`). Desde entonces, los posts que llegan sin el parámetro, como los del escenario de Make de Notion (9927615), también convierten su sección de preguntas frecuentes. `faqs_default` (`true`/`false`) lo enciende o apaga a mano.
- Webs: taxi-calella.com y taxi-santasusanna.com. Las webs que nunca han usado `faqs` no cambian.

## 2.13.3 · 24/10/2026 · PR #35 (Claude)

- FAQ (Blog): el estilo por defecto ya no usa el color `accent` de Elementor, que en taxi-calella.com es blanco casi transparente y dejaba el «+» invisible. El icono toma el color de la pregunta y el borde abierto es neutro. Para los colores de la marca, `faq_css`.
- Webs: taxi-calella.com y taxi-santasusanna.com.

## 2.13.2 · 24/10/2026 · PR #34 (Claude)

- `faqs: "auto"` (Blog): el texto que va después de la última pregunta, como la llamada final a reservar, ya no se mete en la última respuesta. En HTML suelto, la respuesta acaba en la primera línea en blanco; con bloques de Gutenberg, en su primer bloque.
- Webs: taxi-calella.com y taxi-santasusanna.com.

## 2.13.1 · 24/10/2026 · PR #33 (Claude)

- `/publish` (Blog): al actualizar un post sin `content_image_url` ni `content_image_base64`, el marcador `<!-- eg:imagen-contenido -->` vuelve a poner la imagen de contenido que el post ya tenía (antes se quedaba sin imagen). Las traducciones siguen usando la del original si no tienen una propia.
- Webs: todas las de tipología Blog; afecta solo a actualizaciones de posts.

## 2.13.0 · 24/10/2026 · PR #32 (Claude)

- `/publish` (Blog) admite `faqs`: con `"auto"` convierte la sección «Preguntas frecuentes» (H2 y preguntas en H3, en es, ca, en, fr o de) en desplegables nativos de WordPress (bloque Detalles) con estilo propio, y añade los datos estructurados `FAQPage` al grafo de Yoast (sin Yoast, JSON-LD propio). También una lista `[{q, a}]`, `faq_title` y `false`. `faq_css` guarda un CSS propio para la web. Nuevo `includes/faq.php`; la respuesta trae `faqs` (número de preguntas).
- Webs: taxi-calella.com y taxi-santasusanna.com (FAQ de los posts). El resto no cambia: sin `faqs`, `/publish` funciona igual que antes.

## 2.12.0 · 24/10/2026 · PR #31 (Claude)

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
