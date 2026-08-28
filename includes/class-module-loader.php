<?php
/**
 * Discovers module files in /modules/, reads their config, conditionally initialises
 * those whose toggle is ON.
 *
 * Each module file returns an array:
 *   [
 *     'slug'        => 'unique-slug',
 *     'name'        => 'Display Name',
 *     'description' => 'What it does.',
 *     'default'     => true|false,
 *     'init'        => callable,
 *   ]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPASB_Module_Loader {
	private $modules = [];

	public function __construct() {
		$this->discover();
	}

	private function discover() {
		$files = glob( WPASB_DIR . 'modules/*.php' );
		if ( ! $files ) {
			return;
		}
		sort( $files );
		foreach ( $files as $file ) {
			$module = include $file;
			if ( is_array( $module ) && ! empty( $module['slug'] ) ) {
				$this->modules[ $module['slug'] ] = $module;
			}
		}
	}

	public function get_modules() {
		return $this->modules;
	}

	public function get_defaults() {
		$defaults = [];
		foreach ( $this->modules as $slug => $module ) {
			$defaults[ $slug ] = ! empty( $module['default'] );
		}
		return $defaults;
	}

	public function load() {
		$enabled = get_option( WPASB_OPTION, $this->get_defaults() );
		foreach ( $this->modules as $slug => $module ) {
			if ( ! empty( $enabled[ $slug ] ) && is_callable( $module['init'] ) ) {
				call_user_func( $module['init'] );
			}
		}
	}
}
