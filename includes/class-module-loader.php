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
		if ( ! is_array( $files ) || ! $files ) {
			return;
		}

		sort( $files );

		foreach ( $files as $file ) {
			if ( ! is_readable( $file ) ) {
				continue;
			}

			$module = include $file;

			if ( ! is_array( $module ) || empty( $module['slug'] ) || ! isset( $module['init'] ) ) {
				continue;
			}

			$slug = sanitize_key( $module['slug'] );
			if ( '' === $slug ) {
				continue;
			}

			$module['slug']        = $slug;
			$module['name']        = isset( $module['name'] ) ? (string) $module['name'] : $slug;
			$module['description'] = isset( $module['description'] ) ? (string) $module['description'] : '';
			$module['default']     = ! empty( $module['default'] );

			$this->modules[ $slug ] = $module;
		}
	}

	/**
	 * Modules with their labels translated.
	 *
	 * Only call this on or after `init`. Module files store plain English so that
	 * discovery on `plugins_loaded` never triggers a just-in-time textdomain load.
	 */
	public function get_modules() {
		$labels  = function_exists( 'wpasb_module_labels' ) ? wpasb_module_labels() : [];
		$modules = $this->modules;

		foreach ( $modules as $slug => $module ) {
			if ( ! empty( $labels[ $slug ]['name'] ) ) {
				$modules[ $slug ]['name'] = $labels[ $slug ]['name'];
			}
			if ( ! empty( $labels[ $slug ]['description'] ) ) {
				$modules[ $slug ]['description'] = $labels[ $slug ]['description'];
			}
		}

		return $modules;
	}

	/**
	 * Raw module definitions with untranslated labels. Safe at any hook.
	 */
	public function get_raw_modules() {
		return $this->modules;
	}

	public function get_defaults() {
		$defaults = [];
		foreach ( $this->modules as $slug => $module ) {
			$defaults[ $slug ] = ! empty( $module['default'] );
		}
		return $defaults;
	}

	/**
	 * Saved settings merged over defaults.
	 *
	 * Without this merge, a module added in a later plugin version would never run
	 * on an existing install, because the stored option has no key for it.
	 */
	public function get_settings() {
		$saved = get_option( WPASB_OPTION, [] );
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}

		$settings = [];
		foreach ( $this->get_defaults() as $slug => $default ) {
			$settings[ $slug ] = array_key_exists( $slug, $saved ) ? ! empty( $saved[ $slug ] ) : $default;
		}

		return $settings;
	}

	public function load() {
		$enabled = $this->get_settings();

		foreach ( $this->modules as $slug => $module ) {
			if ( empty( $enabled[ $slug ] ) ) {
				continue;
			}

			if ( ! is_callable( $module['init'] ) ) {
				continue;
			}

			call_user_func( $module['init'] );
		}
	}
}
