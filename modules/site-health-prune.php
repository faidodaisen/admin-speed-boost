<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'slug'        => 'site-health-prune',
	'name'        => 'Site Health Probe Prune',
	'description' => 'Remove the async HTTP probes (loopback, dotorg, background updates) that fire on every dashboard load.',
	'default'     => true,
	'init'        => function () {
		add_filter( 'site_status_tests', function ( $tests ) {
			unset( $tests['async']['dotorg_communication'] );
			unset( $tests['async']['background_updates'] );
			unset( $tests['async']['loopback_requests'] );
			unset( $tests['async']['authorization_header'] );
			return $tests;
		} );
	},
];
