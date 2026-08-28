<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'slug'        => 'site-health-prune',
	'name'        => 'Site Health Probe Prune',
	'description' => 'Remove async HTTP probes (loopback, WordPress.org, background updates) that fire on Site Health loads. Direct security and PHP tests are kept.',
	'default'     => true,
	'init'        => function () {
		add_filter( 'site_status_tests', function ( $tests ) {
			if ( ! is_array( $tests ) || empty( $tests['async'] ) ) {
				return $tests;
			}

			$remove = apply_filters( 'wpasb_site_health_async_removed', [
				'dotorg_communication',
				'background_updates',
				'loopback_requests',
				'authorization_header',
			] );

			foreach ( (array) $remove as $key ) {
				unset( $tests['async'][ $key ] );
			}

			return $tests;
		} );
	},
];
