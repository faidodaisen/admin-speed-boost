<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'slug'        => 'heartbeat',
	'name'        => 'Heartbeat Throttle',
	'description' => 'Throttle admin-ajax heartbeat to 60s and disable it on screens that do not need it. The post editor and post list keep heartbeat so autosave and post locking still work.',
	'default'     => true,
	'init'        => function () {
		add_filter( 'heartbeat_settings', function ( $settings ) {
			if ( ! is_array( $settings ) ) {
				$settings = [];
			}
			$settings['interval'] = 60;
			return $settings;
		} );

		// Must run after the current screen is set, otherwise get_current_screen()
		// returns null and heartbeat gets killed on the editor too (breaks
		// autosave + post locking). admin_enqueue_scripts is the correct hook.
		add_action( 'admin_enqueue_scripts', function () {
			if ( wp_doing_ajax() ) {
				return;
			}

			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			$base   = $screen ? $screen->base : '';

			/**
			 * Screens where heartbeat is functionally required.
			 * post  = editor (autosave, post lock)
			 * edit  = list table (post lock indicator)
			 */
			$keep = apply_filters( 'wpasb_heartbeat_keep_screens', [ 'post', 'edit' ] );

			if ( in_array( $base, (array) $keep, true ) ) {
				return;
			}

			// Do not fight the block editor / customizer dependency chains.
			if ( $screen && ( $screen->is_block_editor() || 'customize' === $base ) ) {
				return;
			}

			/**
			 * wp-auth-check (the "session expired" login modal) declares heartbeat as
			 * a dependency. Deregistering heartbeat alone makes WP_Scripts::add emit
			 * "enqueued with dependencies that are not registered: heartbeat" on every
			 * admin page load. Both must go together, and the modal is useless without
			 * heartbeat anyway since it has no way to poll.
			 */
			wp_dequeue_script( 'wp-auth-check' );
			wp_deregister_script( 'wp-auth-check' );
			wp_dequeue_style( 'wp-auth-check' );
			remove_action( 'admin_print_footer_scripts', 'wp_auth_check_html', 5 );

			wp_dequeue_script( 'heartbeat' );
			wp_deregister_script( 'heartbeat' );
		}, 1 );
	},
];
