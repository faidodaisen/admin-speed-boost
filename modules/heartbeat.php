<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'slug'        => 'heartbeat',
	'name'        => 'Heartbeat Throttle',
	'description' => 'Throttle admin-ajax heartbeat to 60s and disable it outside the post editor. Reduces background CPU load 4x.',
	'default'     => true,
	'init'        => function () {
		add_filter( 'heartbeat_settings', function ( $settings ) {
			$settings['interval'] = 60;
			return $settings;
		} );
		add_action( 'init', function () {
			if ( ! is_admin() ) {
				return;
			}
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			$base   = $screen ? $screen->base : '';
			if ( $base !== 'post' ) {
				wp_deregister_script( 'heartbeat' );
			}
		}, 1 );
	},
];
