<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'slug'        => 'jquery-migrate',
	'name'        => 'Disable jQuery Migrate (Frontend)',
	'description' => 'Drop the jquery-migrate dependency on the public site. Admin and the customizer keep it. Disable this module if an old theme or plugin relies on removed jQuery APIs.',
	'default'     => true,
	'init'        => function () {
		add_action( 'wp_default_scripts', function ( $scripts ) {
			if ( is_admin() || ! isset( $scripts->registered['jquery'] ) ) {
				return;
			}

			// Login and customizer screens are not is_admin() but still load admin JS.
			if ( is_customize_preview() ) {
				return;
			}

			$jq = $scripts->registered['jquery'];
			if ( ! empty( $jq->deps ) ) {
				$jq->deps = array_values( array_diff( $jq->deps, [ 'jquery-migrate' ] ) );
			}
		} );
	},
];
