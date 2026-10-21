# mcp_plugins

Plugins para conectar con webs en WordPress via MCP

## EG Publicación

Plugin de WordPress de enricgomez Studio. Expone `/wp-json/eg/v1/publish`, que usa el escenario de Make "Blogs clientes: Notion → WordPress" para publicar los posts de blog de los clientes (idioma y traducción WPML, categoría, SEO de Rank Math, imagen destacada, imagen dentro del contenido y aviso por email).

### Parámetros de categoría

- `category_slug`: slug de la categoría en el idioma del post.
- `category_name` (opcional, desde la v2.4.0): si la categoría no existe, se crea con este nombre. Con WPML se asigna al idioma del post y, en las traducciones, se enlaza con la categoría del original.

### Qué hace en cada web: tipologías (desde la v2.8.0)

En Ajustes → EG Publicación → «Qué hace en esta web» se marca, por tipología, lo que usa cada web. Lo que está apagado no registra sus endpoints (responden 404) ni sus abilities. Todo usa la misma clave `X-EG-Key`.

| Tipología | Endpoints | Capacidad del autor |
| --- | --- | --- |
| Blog | `/publish` | `publish_posts` |
| Elementor (requiere Elementor) | `/landing`, `/template`, `/append` | `publish_pages`, `edit_pages` |
| WooCommerce (requiere WooCommerce) | `/product` | `edit_others_products` |
| Gestión de la web | `/text`, `/menu`, `/redirect` (Rank Math), `/status`, `/lang-links` | `manage_options`, `edit_theme_options`, `edit_others_pages` |

Al actualizar desde la 2.7.x o anterior no hay que tocar nada: el blog queda activo (antes lo estaba siempre) y cada módulo suelto encendido activa su tipología (Productos → WooCommerce; Landings o Plantillas → Elementor; Textos, Menús, Redirecciones o Contenido → Gestión de la web). Al guardar se sigue escribiendo también la opción antigua, por si se vuelve a una 2.7.x.

**Añadir algo nuevo.** Cada endpoint es un módulo de una tipología: se añade con el filtro `eg_pub_modules` (`'slug' => array( 'type' => 'woocommerce', 'routes' => array( 'ruta' ) )`) y registra su ruta solo si `eg_pub_module_enabled( 'slug' )`. Si hace falta una tipología nueva, se añade en `eg_pub_types()` (`includes/modulos.php`).

**No cambiar el nombre ni la carpeta del plugin.** El plugin se llama «EG Publicación» y la release trae la carpeta `eg-publicacion/`. Desde la 2.7.2 las actualizaciones se instalan en la carpeta que ya tenga la web.

### Mantener una web en una versión (desde la v2.6.0)

En Ajustes → EG Publicación → «Actualizaciones», la casilla «Mantener esta web en la versión X» hace que la web deje de ofrecer y de instalar sola las versiones nuevas. También se puede fijar en `wp-config.php` con `define( 'EG_PUB_HOLD_VERSION', true );`.

### Abilities y MCP (desde la v2.6.0, con WordPress 6.9 o superior)

Las mismas funciones se registran como Abilities de WordPress (categoría `eg-publicacion`), así que un cliente MCP como Claude las puede usar con el [MCP Adapter](https://github.com/WordPress/mcp-adapter) oficial, entrando con un usuario de WordPress y su contraseña de aplicación. Los endpoints de Make no cambian. Cada ability comprueba la capacidad del usuario que la usa, igual que el endpoint.

- Siempre: `eg-publicacion/status` (solo lectura: versión, tipologías, idiomas y plugins de la web). Con Blog: `eg-publicacion/publish-post` (publica como el autor configurado).
- Por tipología: `create-landing`, `search-text` (solo lectura), `replace-text`, `save-template`, `append-template`, `edit-menu` y `create-redirect`.

Con WordPress anterior a la 6.9 no se registra nada y el resto del plugin funciona igual.

### Endpoints

- `POST /wp-json/eg/v1/landing`: clona una página maestra de Elementor y crea (o actualiza) una página, en borrador salvo que se pida `status: publish`.
  - Obligatorios: `landing_id` (identificador propio, para poder actualizarla), `template_id` (ID de la página maestra) y `title`.
  - Opcionales: `replacements` (objeto: `{"municipio":"Blanes"}` sustituye `{{municipio}}` en todos los textos), `slug`, `parent_id`, `lang` y `translation_of` (el `landing_id` del original, para enlazarla en WPML), `seo_title`, `seo_description`, `focus_keyword` (también admiten marcadores).
  - `images`: cambia las imágenes de la maestra. Clave = ID de adjunto en la maestra; valor = ID de un adjunto de la biblioteca, una URL (se descarga) o `{"url"|"id": …, "alt": "…"}`. Sirve para imágenes, fondos y galerías.
  - `widgets`: texto completo de widgets concretos de la maestra por su ID de elemento (`{"4de652f": "Carpintería en {{municipio}}"}`). Cambia el título de un widget de título o el HTML de un editor de texto, así cualquier landing existente sirve de maestra sin tocarla.
  - `inline_images`: fotos integradas en el texto. `{"img_cocinas": <ID> | "https://…" | {"id"|"url": …, "alt": "…", "align": "left"}}` sustituye `{{img_cocinas}}` por la foto con srcset, a un lado del texto en escritorio y a todo el ancho en el móvil (el CSS lo añade el plugin solo en estas páginas).
  - `layout`: con `"widgets"` (desde la v2.5.1), cada texto con `<h2>` o fotos se reparte en widgets de Elementor: un widget de título por cada `<h2>`, uno de texto por cada bloque y, donde hay una foto de `inline_images`, dos columnas (foto y texto, alternando el lado con `align`) que en el móvil se apilan con la foto arriba. Funciona con secciones y con contenedores. Sin `layout`, el texto queda en un solo widget con las fotos dentro.
  - `featured_image`: imagen destacada (ID, URL o el nombre de una de `inline_images`).
  - `dry_run: true` (solo con `template_id`): no crea nada y devuelve los marcadores, las imágenes (ID, URL y widget) y los widgets de texto (ID y extracto) de la maestra.
  - La respuesta incluye `missing_keys` si quedan marcadores sin valor e `image_note` si falla alguna imagen.
- `POST /wp-json/eg/v1/menu` (desde la v2.5.2): añade páginas a un menú. Parámetros `menu` (nombre o ID), `lang` (con WPML usa la traducción del menú en ese idioma), `items` (`[{"landing_id"|"page_id": …, "title": "…"}]`, en ese orden; si la página ya está en el menú solo cambia el texto y la posición) y `remove_titles` (quita los elementos cuyo texto contenga alguna de esas cadenas). Con `dry_run` lista los menús y los elementos del menú.
- `POST /wp-json/eg/v1/text` (desde la v2.5.4): busca y sustituye textos en toda la web (títulos, contenido, JSON de Elementor incluidas las plantillas de cabecera y pie, elementos de menú, title/meta/keyword de Rank Math y las opciones `blogname`, `blogdescription` y `rank-math-options-titles`). Con `search` (`["reforma", …]`, sin distinguir mayúsculas) lista dónde aparece cada cadena. Con `replace` (`{"de": "a"}` o `[{"from": …, "to": …}]`, distingue mayúsculas) sustituye solo en el texto visible, nunca dentro de etiquetas ni enlaces, y vacía la caché de Elementor y de WP Rocket. `ids` limita a esas entradas (`"option:blogdescription"` para opciones), `post_types` cambia los tipos revisados y `dry_run` cuenta los cambios sin guardarlos.
- `POST /wp-json/eg/v1/template` (desde la v2.5.5): crea o actualiza una plantilla de Elementor (Plantillas guardadas). Parámetros `key`, `lang`, `title`, `elements` (JSON de Elementor), `type` (`section` por defecto) y `translation_of` (idioma de la versión original, para enlazarla en WPML). Devuelve también si hay claves de reCAPTCHA configuradas.
- `POST /wp-json/eg/v1/append` (desde la v2.5.5): añade al final de páginas una sección con un H2 y el widget Plantilla de Elementor Pro apuntando a la plantilla `template_key` del idioma `lang`. `pages` (`[{"page_id"|"landing_id": …, "heading": "…"}]`), `children_of` (añade las páginas hijas) y `heading_pattern` (`{title}` = título de la página). Si la sección ya existe la sustituye; con `remove` la quita. Solo toca el JSON de Elementor, no el contenido de la entrada.
- `POST /wp-json/eg/v1/status` (desde la v2.6.1): cambia el estado (`status`: `draft`, `publish`, `private` o `pending`) de las entradas elegidas por `paths` (rutas, también con /ca/), `ids` o `post_type` + `terms` (`{"categoria-proyecto": ["banos"]}`, con `exclude_terms` para dejar fuera las que tengan otros términos). `with_translations` añade sus traducciones de WPML. Con `dry_run` solo las lista con su estado. Guarda el estado sin `wp_update_post`, así WPML no lanza la traducción automática.
- `POST /wp-json/eg/v1/product` (desde la v2.7.0, tipología WooCommerce, capacidad `edit_others_products`): edita productos de WooCommerce en todos sus idiomas.
  - Productos: `sku` (lista de SKU: con WPML la traducción comparte el SKU), `ids` o `paths`. Con `with_translations` (por defecto `true`) añade sus traducciones de WPML.
  - Cambios en `set` (todos los idiomas) y en `langs` (`{"es": {...}, "ca": {...}}`, se suman a `set`): `title`, `excerpt` y `content` (texto completo), `remove_blocks` (quita del extracto y del contenido los bloques h1-h6, p, li o div, o las líneas sueltas, cuyo texto visible contiene alguna de esas cadenas), `replace` (`{"de": "a"}`, solo en el texto visible), `meta` (campos de JetEngine del producto y `rank_math_title`, `rank_math_description`, `rank_math_focus_keyword`), `add_terms` y `remove_terms` (`{"product_cat": ["preventa"]}`: slugs en cualquier idioma, se usa el término del idioma de cada producto).
  - `dry_run` devuelve qué cambiaría (antes y después) sin guardar. Los textos se guardan sin `wp_update_post`, así WPML no lanza la traducción automática. Vacía la caché del producto en WooCommerce y WP Rocket.
  - Ejemplo, sacar un libro de la preventa: `{"sku": ["rita-bumbum-4"], "set": {"remove_terms": {"product_cat": ["preventa"]}}, "langs": {"es": {"remove_blocks": ["sale a la venta el"], "meta": {"subtitle_2": "YA DISPONIBLE"}}, "ca": {"remove_blocks": ["surt a la venda el"], "meta": {"subtitle_2": "JA DISPONIBLE"}}}}`.
- `POST /wp-json/eg/v1/lang-links` (desde la v2.9.0, tipología Gestión de la web, capacidad `manage_options`): enlaces que cambian según el idioma de WPML en el que se ve la página, por ejemplo el botón Reservar de la cabecera hacia el motor de reservas de cada idioma.
  - `rules`: lista de reglas que sustituye a las guardadas. Cada una con `from` (URL tal como está en la web) y `to` (URL con `{lang}`). Opcionales: `langs` (`{"ca": "ca"}`, código del destino para cada idioma de WPML si no coincide) y `skip` (idiomas en los que no se cambia; por defecto el idioma principal).
  - Ejemplo: `{"rules": [{"from": "https://engine.witbooking.com/property/campingelfar", "to": "https://engine.witbooking.com/{lang}/property/campingelfar"}]}`.
  - El cambio se hace al servir la página, sobre el HTML final (también la forma escapada `https:\/\/` del JSON de Elementor). Da igual si el enlace está en una cabecera de Elementor sin traducir, en un menú o en el contenido: no hay que editar cada traducción ni la traducción de cadenas de WPML. No se aplica en el editor de Elementor ni en la API.
  - Sin `rules` devuelve las reglas guardadas; `clear: true` las borra; `dry_run` no guarda. La respuesta incluye `preview` (el enlace en cada idioma activo) y `found` (dónde está la URL de `from` en la base de datos: entradas, plantillas y opciones). Al guardar vacía la caché de WP Rocket.
- `POST /wp-json/eg/v1/redirect`: crea una redirección en Rank Math (tipología Gestión de la web). Parámetros `from` (ruta o URL), `to` y `code` (301 por defecto).

### Instalación en una web

1. Descarga `eg-publicacion.zip` de la última [release](https://github.com/enricgomez/mcp_wpplugin/releases/latest) (no el zip del código del repositorio, que crea la carpeta `mcp_wpplugin-main`). Desde la v2.7.2, si una web ya lo tiene en otra carpeta, las actualizaciones se instalan en esa misma carpeta y no dejan dos copias.
2. En WordPress: Plugins → Añadir nuevo → Subir plugin, y actívalo.
3. Si la web tenía la versión antigua como mu-plugin, borra `wp-content/mu-plugins/eg-studio-rest-publisher.php`. La clave y los ajustes se conservan, así que Make sigue funcionando.
4. La clave para Make está en Ajustes → EG Publicación.

### Publicar una versión nueva

1. Cambia la versión en `eg-publicacion.php` (cabecera `Version:` y constante `EG_PUB_VERSION`).
2. Haz commit y súbelo a `main`.
3. La GitHub Action crea la release `vX.Y.Z` con el zip (si esa versión ya tenía release, no hace nada). Cada web la detecta en unas horas y se actualiza sola (o al momento con "Buscar actualizaciones" en la fila del plugin).

Si el repositorio es privado, cada web necesita en `wp-config.php`:

```php
define( 'EG_PUB_GITHUB_TOKEN', 'github_pat_...' ); // token de solo lectura (Contents: Read) de este repositorio
```
