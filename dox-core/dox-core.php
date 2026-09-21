<?php
/**
 * Dox Core: el menú común de los plugins de Dox Studio.
 *
 * Junta todos los plugins Dox bajo un solo menú "Dox Plugins" en vez de que
 * cada uno cuelgue su propia entrada del menú principal de WordPress. Cada
 * plugin sigue siendo independiente: lleva su copia de esta carpeta y funciona
 * igual esté solo o acompañado (ver loader.php).
 *
 * El menú se llama "Dox Plugins" y no "Dox Studio" a propósito: el tema de Dox
 * Studio (UiCore con marca blanca) ya crea un menú "Dox Studio" con el slug
 * `uicore`, y dos entradas con el mismo nombre en la misma barra confunden.
 *
 * Cómo se apunta un plugin, desde su propio archivo:
 *
 *     add_action( 'dox_core_register', function ( $core ) {
 *         $core->register_plugin( [
 *             'slug'    => 'dox-sales-booster',
 *             'name'    => 'Sales Booster',
 *             'version' => DSB_VERSION,
 *             'summary' => 'Social proof for WooCommerce.',
 *             'page'    => [
 *                 'menu_title' => 'Sales Booster',
 *                 'page_title' => 'Dox Sales Booster',
 *                 'callback'   => 'dsb_render_page',
 *             ],
 *         ] );
 *     } );
 *
 * El submenú lo crea el core, no el plugin, para que el orden y las
 * capacidades sean iguales en todos.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// La declaración va dentro de un if a propósito: PHP registra las clases de
// primer nivel al compilar el archivo, antes de ejecutar su primera línea, así
// que un class_exists() suelto aquí arriba daría true en esta misma copia.
if ( ! class_exists( 'Dox_Core' ) ) :

class Dox_Core {

	const MENU_SLUG = 'dox-plugins';

	private static $instance = null;

	/** Plugins registrados, por slug. */
	private $plugins = [];

	/** Hook de pantalla de cada subpágina creada, por slug. */
	private $hooks = [];

	/** Carpeta de la copia que ha ganado, para las vistas y las traducciones. */
	private $path;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->path = trailingslashit( __DIR__ );

		add_action( 'init', [ $this, 'load_textdomain' ], 1 );
		// Prioridad 9: antes de que los plugins registren nada suyo en el menú.
		add_action( 'admin_menu', [ $this, 'register_menu' ], 9 );
	}

	public function path() {
		return $this->path;
	}

	/**
	 * Versión de la copia que ha ganado. Se escribe solo en version.php.
	 */
	public function version() {
		$file = $this->path . 'version.php';

		return file_exists( $file ) ? (string) require $file : '';
	}

	public function load_textdomain() {
		$locale = determine_locale();
		$mofile = $this->path . 'languages/dox-core-' . $locale . '.mo';

		// WordPress no pasa de es_CO o es_MX a es_ES por su cuenta, y casi todas
		// las tiendas de Dox Studio están en alguna variante del español: sin
		// esto verían el menú en inglés.
		if ( ! file_exists( $mofile ) && preg_match( '/^es(_|$)/', $locale ) ) {
			$mofile = $this->path . 'languages/dox-core-es_ES.mo';
		}

		if ( file_exists( $mofile ) ) {
			load_textdomain( 'dox-core', $mofile );
		}
	}

	/**
	 * Apunta un plugin en el menú común.
	 *
	 * Un plugin de una sola pantalla pasa 'page' y el core le crea el submenú.
	 * Uno que ya tiene su propio menú (Dox Feedback) o que vive en otro sitio
	 * (Dox POS, bajo WooCommerce) pasa 'settings_url' y solo sale en la portada,
	 * que es como Crocoblock lista sus plugins grandes sin moverlos de su menú.
	 *
	 * @param array $args slug, name, version, summary, url, settings_url y
	 *                    (opcional) page: menu_title, page_title, capability,
	 *                    menu_slug, callback.
	 */
	public function register_plugin( array $args ) {

		if ( empty( $args['slug'] ) || empty( $args['name'] ) ) return;

		$args = wp_parse_args( $args, [
			'slug'         => '',
			'name'         => '',
			'version'      => '',
			'summary'      => '',
			'url'          => 'https://doxstudio.com/plugins/',
			'settings_url' => '',
			'page'         => [],
		] );

		if ( ! empty( $args['page'] ) ) {
			$args['page'] = wp_parse_args( $args['page'], [
				'menu_title' => $args['name'],
				'page_title' => $args['name'],
				'capability' => 'manage_options',
				'menu_slug'  => $args['slug'],
				'callback'   => '',
			] );
		}

		$this->plugins[ $args['slug'] ] = $args;
	}

	public function get_plugins() {
		return $this->plugins;
	}

	/**
	 * Hook de pantalla de la subpágina de un plugin, para que pueda encolar sus
	 * estilos solo ahí. Al pasar de menú principal a submenú, el hook deja de
	 * ser `toplevel_page_x` y pasa a ser `dox-plugins_page_x`.
	 */
	public function page_hook( $slug ) {
		return isset( $this->hooks[ $slug ] ) ? $this->hooks[ $slug ] : '';
	}

	public function register_menu() {

		do_action( 'dox_core_register', $this );

		if ( ! $this->plugins ) return; // nada que enseñar

		// Con un solo plugin que además vive en su propio menú, este menú sería
		// una entrada de más para enseñar un enlace: no se crea.
		$con_pagina = 0;
		foreach ( $this->plugins as $plugin ) {
			if ( ! empty( $plugin['page'] ) ) $con_pagina++;
		}
		if ( ! $con_pagina && count( $this->plugins ) < 2 ) return;

		add_menu_page(
			__( 'Dox Plugins', 'dox-core' ),
			__( 'Dox Plugins', 'dox-core' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render_dashboard' ],
			$this->menu_icon(),
			'58.9'
		);

		// Sin esto, WordPress repite el nombre del menú como primer submenú.
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Dox Plugins', 'dox-core' ),
			__( 'Installed', 'dox-core' ),
			'manage_options',
			self::MENU_SLUG
		);

		foreach ( $this->plugins as $slug => $plugin ) {
			if ( empty( $plugin['page'] ) || empty( $plugin['page']['callback'] ) ) continue;

			$hook = add_submenu_page(
				self::MENU_SLUG,
				$plugin['page']['page_title'],
				$plugin['page']['menu_title'],
				$plugin['page']['capability'],
				$plugin['page']['menu_slug'],
				$plugin['page']['callback']
			);

			if ( $hook ) $this->hooks[ $slug ] = $hook;
		}
	}

	public function render_dashboard() {
		$view = $this->path . 'views/dashboard.php';
		if ( file_exists( $view ) ) {
			include $view;
		}
	}

	private function menu_icon() {
		$svg = $this->asset( 'icon-menu.svg' );

		if ( ! $svg ) {
			// Respaldo por si el archivo no viajara en el zip: un cuadrado naranja
			// es preferible a que WordPress pinte el icono roto por defecto.
			$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><rect width="24" height="24" rx="4" fill="#ff8d27"/></svg>';
		}

		return 'data:image/svg+xml;base64,' . base64_encode( $svg );
	}

	/**
	 * Lockup "dox plugins" para la cabecera del panel: el isotipo de la marca y
	 * las dos palabras en Poppins, la tipografía del logo, ya convertidas en
	 * curvas para no depender de ninguna fuente instalada. Va en línea y no como
	 * <img> para que herede el tamaño por CSS sin pedir otro archivo.
	 */
	public function logo_svg() {
		return $this->asset( 'logo-plugins.svg' );
	}

	private function asset( $file ) {
		$path = $this->path . 'assets/' . $file;

		return file_exists( $path ) ? (string) file_get_contents( $path ) : '';
	}
}

endif;

/**
 * Acceso corto, como wc() o WC().
 */
if ( ! function_exists( 'dox_core' ) ) {
	function dox_core() {
		return Dox_Core::instance();
	}
}

dox_core();
