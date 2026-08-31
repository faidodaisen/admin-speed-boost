<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'slug'        => 'disable-xmlrpc',
	'name'        => 'Disable XML-RPC',
	'description' => 'Turn off xmlrpc.php, pingbacks and the RSD link. Blocks a common brute-force and DDoS amplification target. Turn this OFF if you use the WordPress mobile app, Jetpack, or a remote publishing client.',
	'default'     => true,
	'init'        => function () {
		add_filter( 'xmlrpc_enabled', '__return_false' );

		// Refuse every XML-RPC method, including ones registered by plugins.
		add_filter(
			'xmlrpc_methods',
			function () {
				return [];
			},
			100
		);

		// Drop the RSD discovery link so clients stop probing.
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );

		// Kill pingbacks, which ride on XML-RPC.
		add_filter(
			'wp_headers',
			function ( $headers ) {
				unset( $headers['X-Pingback'], $headers['x-pingback'] );
				return $headers;
			},
			100
		);

		add_filter( 'pings_open', '__return_false', 100 );
		add_filter( 'pre_option_default_ping_status', function () {
			return 'closed';
		} );

		// Return a hard 403 before WordPress parses the request body.
		add_action(
			'xmlrpc_call',
			function () {
				wp_die(
					'XML-RPC is disabled on this site.',
					'XML-RPC disabled',
					[ 'response' => 403 ]
				);
			}
		);
	},
];
