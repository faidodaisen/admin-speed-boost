<?php
/**
 * Bootstrap class — wires module loader, settings page, and DB cleanup handler.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPASB_Plugin {
	private static $instance = null;
	private $loader;

	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->loader = new WPASB_Module_Loader();
		$this->loader->load();

		if ( is_admin() ) {
			new WPASB_Settings_Page( $this->loader );
			new WPASB_DB_Cleanup();
		}

		register_activation_hook( WPASB_FILE, [ $this, 'on_activate' ] );
	}

	public function on_activate() {
		if ( get_option( WPASB_OPTION ) === false ) {
			$loader = new WPASB_Module_Loader();
			update_option( WPASB_OPTION, $loader->get_defaults() );
		}
	}
}
