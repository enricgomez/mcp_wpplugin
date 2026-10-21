<?php
/**
 * Las mismas funciones que los endpoints de Make, registradas como Abilities de WordPress (WP 6.9 o superior).
 *
 * Así cualquier cliente MCP (Claude incluido) las puede usar a través del MCP Adapter oficial de WordPress,
 * con un usuario de WordPress y su contraseña de aplicación en lugar de la clave X-EG-Key. Los endpoints
 * /wp-json/eg/v1/... siguen igual para Make. Solo se registran las de los módulos activos en esta web.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EG_PUB_ABILITY_CATEGORY = 'eg-publicacion';

add_action( 'wp_abilities_api_categories_init', function () {
	if ( function_exists( 'wp_register_ability_category' ) ) {
		wp_register_ability_category( EG_PUB_ABILITY_CATEGORY, array(
			'label'       => 'EG Publicación',
			'description' => 'Publicación de posts, landings, textos, plantillas, menús y redirecciones de enricgomez Studio.',
		) );
	}
} );

add_action( 'wp_abilities_api_init', function () {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}
	foreach ( eg_pub_ability_defs() as $name => $def ) {
		if ( $def['module'] && ! eg_pub_module_enabled( $def['module'] ) ) {
			continue;
		}
		$cap  = $def['cap'];
		$args = array(
			'label'               => $def['label'],
			'description'         => $def['description'],
			'category'            => EG_PUB_ABILITY_CATEGORY,
			'execute_callback'    => $def['execute'],
			'permission_callback' => function () use ( $cap ) {
				return current_user_can( $cap );
			},
			'meta'                => array(
				'annotations'  => $def['annotations'],
				'show_in_rest' => true,
				'mcp'          => array( 'public' => true ), // Visible en el servidor por defecto del MCP Adapter.
			),
		);
		if ( ! empty( $def['input'] ) ) {
			$args['input_schema'] = array_merge( array( 'type' => 'object', 'additionalProperties' => true ), $def['input'] );
		}
		wp_register_ability( 'eg-publicacion/' . $name, $args );
	}
} );

/**
 * Ejecuta el manejador del endpoint con la entrada de la ability y devuelve sus datos
 * (o un WP_Error si el endpoint responde con error).
 */
function eg_pub_ability_call( $callback, $route, $input ) {
	$request = new WP_REST_Request( 'POST', '/eg/v1/' . $route );
	$request->set_body_params( is_array( $input ) ? $input : array() );
	$response = rest_ensure_response( call_user_func( $callback, $request ) );
	if ( is_wp_error( $response ) ) {
		return $response;
	}
	$data = $response->get_data();
	if ( $response->get_status() >= 400 ) {
		return new WP_Error(
			is_array( $data ) && ! empty( $data['code'] ) ? $data['code'] : 'eg_error',
			is_array( $data ) && ! empty( $data['message'] ) ? $data['message'] : 'Error ' . $response->get_status(),
			array( 'status' => $response->get_status() )
		);
	}
	return $data;
}

/** Ability que llama al endpoint tal cual. */
function eg_pub_ability_route( $callback, $route ) {
	return function ( $input = null ) use ( $callback, $route ) {
		return eg_pub_ability_call( $callback, $route, $input );
	};
}

/** Propiedad de esquema JSON con su descripción. */
function eg_pub_prop( $type, $description ) {
	return array( 'type' => $type, 'description' => $description );
}

function eg_pub_ability_defs() {
	$write   = array( 'readonly' => false, 'destructive' => false, 'idempotent' => true );
	$replace = array( 'readonly' => false, 'destructive' => true, 'idempotent' => false );
	$read    = array( 'readonly' => true, 'destructive' => false, 'idempotent' => true );
	$page    = eg_pub_prop( 'array', 'Páginas: [{"page_id": 123} o {"landing_id": "..."}, con "heading" opcional].' );

	return array(
		'status'          => array(
			'module'      => '',
			'cap'         => 'edit_posts',
			'label'       => 'Estado del plugin',
			'description' => 'Versión de EG Publicación, tipologías y módulos activos, si la versión está retenida, idiomas (WPML) y plugins relevantes de la web.',
			'annotations' => $read,
			'input'       => array(),
			'execute'     => 'eg_pub_ability_status',
		),
		'publish-post'    => array(
			'module'      => 'blog',
			'cap'         => 'publish_posts',
			'label'       => 'Publicar o actualizar un post',
			'description' => 'Crea o actualiza un post del blog por notion_id, con idioma y traducción (WPML), categoría, SEO de Rank Math, imagen destacada y fecha de programación. El autor es el configurado en Ajustes → EG Publicación.',
			'annotations' => $write,
			'input'       => array(
				'required'   => array( 'notion_id', 'title', 'content' ),
				'properties' => array(
					'notion_id'             => eg_pub_prop( 'string', 'Identificador único del post (el de la ficha de Notion). Si ya existe, se actualiza.' ),
					'title'                 => eg_pub_prop( 'string', 'Título.' ),
					'content'               => eg_pub_prop( 'string', 'Contenido en HTML.' ),
					'excerpt'               => eg_pub_prop( 'string', 'Extracto y meta description.' ),
					'slug'                  => eg_pub_prop( 'string', 'Slug.' ),
					'lang'                  => eg_pub_prop( 'string', 'Código de idioma de WPML (es, ca, en...).' ),
					'translation_of_notion' => eg_pub_prop( 'string', 'notion_id del post original del que este es traducción.' ),
					'date'                  => eg_pub_prop( 'string', 'Fecha de publicación (futura = programado).' ),
					'category_name'         => eg_pub_prop( 'string', 'Nombre de la categoría.' ),
					'category_slug'         => eg_pub_prop( 'string', 'Slug de la categoría.' ),
					'image_url'             => eg_pub_prop( 'string', 'URL de la imagen destacada.' ),
					'image_alt'             => eg_pub_prop( 'string', 'Texto alternativo de la imagen destacada.' ),
				),
			),
			'execute'     => function ( $input = null ) {
				// Igual que desde Make: publicar como el autor configurado si existe.
				$author = (int) get_option( EG_PUB_OPTION_USER );
				$before = get_current_user_id();
				if ( $author && get_userdata( $author ) && user_can( $author, 'publish_posts' ) ) {
					wp_set_current_user( $author );
				}
				$result = eg_pub_ability_call( 'eg_pub_handle', 'publish', $input );
				wp_set_current_user( $before );
				return $result;
			},
		),
		'create-landing'  => array(
			'module'      => 'landings',
			'cap'         => 'publish_pages',
			'label'       => 'Crear o actualizar una landing',
			'description' => 'Clona una página maestra de Elementor (template_id) y sustituye sus marcadores {{clave}} con replacements. Con dry_run devuelve los marcadores, imágenes y widgets de la maestra sin crear nada.',
			'annotations' => $write,
			'input'       => array(
				'properties' => array(
					'landing_id'     => eg_pub_prop( 'string', 'Identificador único de la landing. Si ya existe, se actualiza.' ),
					'template_id'    => eg_pub_prop( 'integer', 'ID de la página maestra de Elementor.' ),
					'title'          => eg_pub_prop( 'string', 'Título de la página.' ),
					'slug'           => eg_pub_prop( 'string', 'Slug.' ),
					'lang'           => eg_pub_prop( 'string', 'Código de idioma de WPML.' ),
					'translation_of' => eg_pub_prop( 'string', 'landing_id de la landing original.' ),
					'parent_id'      => eg_pub_prop( 'integer', 'Página madre.' ),
					'status'         => eg_pub_prop( 'string', 'publish o draft.' ),
					'replacements'   => eg_pub_prop( 'object', 'Marcadores {{clave}} => texto.' ),
					'dry_run'        => eg_pub_prop( 'boolean', 'Solo analizar la página maestra.' ),
				),
			),
			'execute'     => eg_pub_ability_route( 'eg_pub_landing_handle', 'landing' ),
		),
		'search-text'     => array(
			'module'      => 'textos',
			'cap'         => 'manage_options',
			'label'       => 'Buscar textos en la web',
			'description' => 'Lista dónde aparece cada cadena (títulos, contenido, Elementor, plantillas, Rank Math y opciones), con fragmentos. No cambia nada.',
			'annotations' => $read,
			'input'       => array(
				'required'   => array( 'search' ),
				'properties' => array(
					'search'     => eg_pub_prop( 'array', 'Cadenas a buscar (sin distinguir mayúsculas).' ),
					'post_types' => eg_pub_prop( 'array', 'Limitar a estos tipos de contenido.' ),
					'ids'        => eg_pub_prop( 'array', 'Limitar a estos IDs (o "option:nombre").' ),
				),
			),
			'execute'     => function ( $input = null ) {
				$input = is_array( $input ) ? $input : array();
				unset( $input['replace'] );
				return eg_pub_ability_call( 'eg_pub_text_handle', 'text', $input );
			},
		),
		'replace-text'    => array(
			'module'      => 'textos',
			'cap'         => 'manage_options',
			'label'       => 'Sustituir textos en la web',
			'description' => 'Sustituye cadenas exactas solo en el texto visible (nunca en enlaces ni etiquetas HTML). Conviene probar antes con dry_run.',
			'annotations' => $replace,
			'input'       => array(
				'required'   => array( 'replace' ),
				'properties' => array(
					'replace'    => array( 'type' => array( 'object', 'array' ), 'description' => '{"de": "a"} o [{"from": "...", "to": "..."}].' ),
					'dry_run'    => eg_pub_prop( 'boolean', 'Contar los cambios sin guardar.' ),
					'post_types' => eg_pub_prop( 'array', 'Limitar a estos tipos de contenido.' ),
					'ids'        => eg_pub_prop( 'array', 'Limitar a estos IDs (o "option:nombre").' ),
				),
			),
			'execute'     => function ( $input = null ) {
				$input = is_array( $input ) ? $input : array();
				unset( $input['search'] );
				return eg_pub_ability_call( 'eg_pub_text_handle', 'text', $input );
			},
		),
		'save-template'   => array(
			'module'      => 'plantillas',
			'cap'         => 'edit_pages',
			'label'       => 'Guardar una plantilla de Elementor',
			'description' => 'Crea o actualiza una plantilla de Elementor (Plantillas guardadas) por clave e idioma, y la enlaza con su traducción en WPML.',
			'annotations' => $write,
			'input'       => array(
				'required'   => array( 'key', 'title', 'elements' ),
				'properties' => array(
					'key'            => eg_pub_prop( 'string', 'Clave de la plantilla.' ),
					'lang'           => eg_pub_prop( 'string', 'Código de idioma de WPML.' ),
					'title'          => eg_pub_prop( 'string', 'Título.' ),
					'elements'       => eg_pub_prop( 'array', 'JSON de elementos de Elementor.' ),
					'type'           => eg_pub_prop( 'string', 'Tipo de plantilla (section por defecto).' ),
					'translation_of' => eg_pub_prop( 'string', 'Idioma de la plantilla original.' ),
				),
			),
			'execute'     => eg_pub_ability_route( 'eg_pub_template_handle', 'template' ),
		),
		'append-template' => array(
			'module'      => 'plantillas',
			'cap'         => 'edit_pages',
			'label'       => 'Añadir una plantilla al pie de páginas',
			'description' => 'Añade al final de varias páginas de Elementor una sección con un H2 y la plantilla indicada; si ya estaba, la sustituye. Con remove la quita.',
			'annotations' => $write,
			'input'       => array(
				'required'   => array( 'template_key' ),
				'properties' => array(
					'template_key'    => eg_pub_prop( 'string', 'Clave de la plantilla.' ),
					'lang'            => eg_pub_prop( 'string', 'Código de idioma de WPML.' ),
					'pages'           => $page,
					'children_of'     => eg_pub_prop( 'integer', 'Incluir también las páginas hijas de esta.' ),
					'heading_pattern' => eg_pub_prop( 'string', 'Título por defecto, con {title} para el de la página.' ),
					'remove'          => eg_pub_prop( 'boolean', 'Quitar la sección en lugar de añadirla.' ),
					'dry_run'         => eg_pub_prop( 'boolean', 'Ver qué páginas cambiarían sin guardar.' ),
				),
			),
			'execute'     => eg_pub_ability_route( 'eg_pub_append_handle', 'append' ),
		),
		'edit-menu'       => array(
			'module'      => 'menus',
			'cap'         => 'edit_theme_options',
			'label'       => 'Editar un menú',
			'description' => 'Añade páginas a un menú en el orden indicado y quita las que se pidan. Con dry_run lista los menús y los elementos del indicado.',
			'annotations' => $write,
			'input'       => array(
				'properties' => array(
					'menu'    => array( 'type' => array( 'integer', 'string' ), 'description' => 'ID, slug o nombre del menú.' ),
					'lang'    => eg_pub_prop( 'string', 'Idioma: se usa la traducción del menú en WPML.' ),
					'items'   => eg_pub_prop( 'array', 'Páginas que añadir.' ),
					'dry_run' => eg_pub_prop( 'boolean', 'Solo listar.' ),
				),
			),
			'execute'     => eg_pub_ability_route( 'eg_pub_menu_handle', 'menu' ),
		),
		'create-redirect' => array(
			'module'      => 'redirecciones',
			'cap'         => 'manage_options',
			'label'       => 'Crear una redirección',
			'description' => 'Crea una redirección en Rank Math.',
			'annotations' => array( 'readonly' => false, 'destructive' => false, 'idempotent' => false ),
			'input'       => array(
				'required'   => array( 'from', 'to' ),
				'properties' => array(
					'from' => eg_pub_prop( 'string', 'URL o ruta de origen.' ),
					'to'   => eg_pub_prop( 'string', 'URL o ruta de destino.' ),
					'code' => eg_pub_prop( 'integer', '301 (por defecto), 302, 307, 410 o 451.' ),
				),
			),
			'execute'     => eg_pub_ability_route( 'eg_pub_redirect_handle', 'redirect' ),
		),
	);
}

function eg_pub_ability_status() {
	$languages = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );
	return array(
		'version'      => EG_PUB_VERSION,
		'held'         => eg_pub_update_held(),
		'types'        => eg_pub_active_types(),
		'modules'      => eg_pub_active_modules(),
		'available'    => array_keys( eg_pub_types() ),
		'site'         => home_url( '/' ),
		'wordpress'    => get_bloginfo( 'version' ),
		'languages'    => is_array( $languages ) ? array_keys( $languages ) : array(),
		'elementor'    => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '',
		'elementor_pro' => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : '',
		'woocommerce'  => defined( 'WC_VERSION' ) ? WC_VERSION : '',
		'jetengine'    => defined( 'JET_ENGINE_VERSION' ) ? JET_ENGINE_VERSION : '',
		'rank_math'    => defined( 'RANK_MATH_VERSION' ) ? RANK_MATH_VERSION : '',
	);
}
