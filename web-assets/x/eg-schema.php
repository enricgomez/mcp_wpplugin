<?php
/**
 * Plugin Name: EG schema (enricgomez.com)
 * Description: Completa el JSON-LD de Rank Math: ProfessionalService con dirección y redes, Enric Gómez como autor, WebPage en vez de Article en páginas y Service en las de servicios.
 */
if (!defined('ABSPATH')) exit;
add_filter('rank_math/json_ld', function ($data, $jsonld) {
	$home = home_url('/');
	$org  = $home . '#organization';
	$per  = $home . '#enric-gomez';
	$es_pagina = is_page() || is_front_page();
	$path = trim((string) wp_parse_url(get_permalink(), PHP_URL_PATH), '/');
	$servicio = $es_pagina && (strpos($path, 'servicios/') === 0 || $path === 'diseno-web-calella' || $path === 'urgencias-web' || $path === 'diagnostico-ia');
	foreach ($data as $k => &$n) {
		if (!is_array($n)) continue;
		$id = $n['@id'] ?? '';
		if ($id === $org) {
			$n['@type'] = ['Organization', 'ProfessionalService'];
			$n['legalName'] = 'EG Creative Studio S.L.';
			$n['url'] = $home;
			$n['email'] = 'info@enricgomez.com';
			$n['foundingDate'] = '2006';
			$n['description'] = 'Estudio de diseño y desarrollo web en Calella (Barcelona) especializado en WordPress y WooCommerce desde 2006. También inteligencia artificial, consultoría digital y sistemas a medida.';
			$n['address'] = ['@type' => 'PostalAddress', 'streetAddress' => 'C/ Costa i Fornaguera, 27', 'postalCode' => '08370', 'addressLocality' => 'Calella', 'addressRegion' => 'Barcelona', 'addressCountry' => 'ES'];
			$n['areaServed'] = ['Calella', 'Maresme', 'Barcelona', 'Cataluña', 'España'];
			$n['knowsAbout'] = ['Diseño web', 'Desarrollo web', 'WordPress', 'WooCommerce', 'Tiendas online', 'SEO', 'Webs multidioma', 'Inteligencia artificial', 'Consultoría digital', 'Sistemas a medida'];
			$n['founder'] = ['@id' => $per];
			$n['image'] = $home . 'wp-content/uploads/2026/11/enricgomez-studio-og.jpg';
			$n['priceRange'] = '€€';
			$n['sameAs'] = ['https://www.facebook.com/enricgomezstudio', 'https://www.instagram.com/enricgomezstudio', 'https://www.linkedin.com/company/enricgomez-web-design-studio/', 'https://x.com/_enricgomez'];
		}
		if (($n['@type'] ?? '') === 'Person') {
			$n['@id'] = $per;
			$n['name'] = 'Enric Gómez';
			$n['url'] = $home . 'estudio/';
			$n['jobTitle'] = 'Diseñador y desarrollador web';
			$n['alumniOf'] = ['@type' => 'CollegeOrUniversity', 'name' => 'CITM, Universitat Politècnica de Catalunya (UPC)'];
			$n['knowsAbout'] = ['Diseño web', 'WordPress', 'WooCommerce', 'SEO', 'Inteligencia artificial'];
			$n['worksFor'] = ['@id' => $org];
			$n['sameAs'] = [$home . 'estudio/'];
			unset($n['image']);
		}
		if ($es_pagina && ($n['@type'] ?? '') === 'Article') { unset($data[$k]); continue; }
		if (isset($n['author']['@id'])) $n['author'] = ['@id' => $per, 'name' => 'Enric Gómez'];
	}
	unset($n);
	if ($servicio) {
		$data['egService'] = [
			'@type' => 'Service',
			'@id' => get_permalink() . '#service',
			'name' => wp_strip_all_tags(get_the_title()),
			'url' => get_permalink(),
			'description' => (string) get_post_meta(get_the_ID(), 'rank_math_description', true),
			'provider' => ['@id' => $org],
			'areaServed' => ['Calella', 'Maresme', 'Barcelona', 'Cataluña', 'España'],
			'inLanguage' => 'es',
		];
	}
	return $data;
}, 99, 2);
