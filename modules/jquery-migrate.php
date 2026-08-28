<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'slug'        => 'jquery-migrate',
	'name'        => 'Disable jQuery Migrate (Frontend)',
	'description' => 'Drop the jquery-migrate dependency on the public site. Admin still uses it where needed.',
	'default'     => true,
	'init'        => function () {
		add_action( 'wp_default_scripts', function ( $scripts ) {
			if ( is_admin() || empty( $scripts->registered['jquery'] ) ) {
				return;
			}
			$jq = $scripts->registered['jquery'];
			if ( ! empty( $jq->deps ) ) {
				$jq->deps = array_diff( $jq->deps, [ 'jquery-migrate' ] );
			}
		} );
	},
];
