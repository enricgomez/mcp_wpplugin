# mcp_plugins

Plugins para conectar con webs en WordPress via MCP

## EG Publicación

Plugin de WordPress de enricgomez Studio. Expone `/wp-json/eg/v1/publish`, que usa el escenario de Make "Blogs clientes: Notion → WordPress" para publicar los posts de blog de los clientes (idioma y traducción WPML, categoría, SEO de Rank Math, imagen destacada, imagen dentro del contenido y aviso por email).

### Instalación en una web

1. Descarga `eg-publicacion.zip` de la última [release](https://github.com/enricgomez/mcp_wpplugin/releases/latest).
2. En WordPress: Plugins → Añadir nuevo → Subir plugin, y actívalo.
3. Si la web tenía la versión antigua como mu-plugin, borra `wp-content/mu-plugins/eg-studio-rest-publisher.php`. La clave y los ajustes se conservan, así que Make sigue funcionando.
4. La clave para Make está en Ajustes → EG Publicación.

### Publicar una versión nueva

1. Cambia la versión en `eg-publicacion.php` (cabecera `Version:` y constante `EG_PUB_VERSION`).
2. Haz commit y crea el tag: `git tag v2.3.1 && git push origin main --tags`.
3. La GitHub Action crea la release con el zip. Cada web la detecta en unas horas y se actualiza sola (o al momento con "Buscar actualizaciones" en la fila del plugin).

Si el repositorio es privado, cada web necesita en `wp-config.php`:

```php
define( 'EG_PUB_GITHUB_TOKEN', 'github_pat_...' ); // token de solo lectura (Contents: Read) de este repositorio
```
