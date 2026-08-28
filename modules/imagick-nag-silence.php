<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'slug'        => 'imagick-nag-silence',
	'name'        => 'Silence Imagick Site Health Nag',
	'description' => 'Remove the "Imagick not installed" recommendation from Site Health when GD is available. GD ships with PHP and handles every core image operation.',
	'default'     => true,
	'init'        => function () {
		add_filter( 'site_status_test_php_modules', function ( $modules ) {
			if ( ! is_array( $modules ) ) {
				return $modules;
			}

			// Only hide the nag when there is a working image backend. If neither
			// Imagick nor GD exists, the warning is genuine and must stay visible.
			if ( ! extension_loaded( 'gd' ) && ! extension_loaded( 'imagick' ) ) {
				return $modules;
			}

			unset( $modules['imagick'] );

			return $modules;
		} );
	},
];
