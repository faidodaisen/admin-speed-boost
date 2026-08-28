<?php
/**
 * Bootstrap class. Wires module loader, settings page, and DB cleanup handler.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPASB_Plugin {

	private static $instance = null;

	/**
	 * @var WPASB_Module_Loader
	 */
	private $loader;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Loaded on init, not in the constructor. WP 6.7+ warns when a textdomain
		// resolves before init, and plugins_loaded is too early.
		add_action( 'init', [ $this, 'load_textdomain' ] );

		$this->loader = new WPASB_Module_Loader();
		$this->loader->load();

		if ( is_admin() ) {
			new WPASB_Settings_Page( $this->loader );
			new WPASB_DB_Cleanup();
		}
	}

	public function load_textdomain() {
		load_plugin_textdomain(
			'wp-admin-speedboost',
			false,
			dirname( plugin_basename( WPASB_FILE ) ) . '/languages'
		);
	}

	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Seeds defaults on activation. Static so it can be registered from the main
	 * plugin file before the class is instantiated.
	 */
	public static function on_activate() {
		if ( false !== get_option( WPASB_OPTION ) ) {
			return;
		}

		$loader = new WPASB_Module_Loader();
		add_option( WPASB_OPTION, $loader->get_defaults(), '', false );
	}

	private function __clone() {}

	public function __wakeup() {
		throw new \RuntimeException( 'WPASB_Plugin cannot be unserialized.' );
	}
}
