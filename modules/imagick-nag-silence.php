<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'slug'        => 'imagick-nag-silence',
	'name'        => 'Silence Imagick Site Health Nag',
	'description' => 'Remove the "Imagick not installed" recommendation from Site Health. GD ships with PHP and handles every WP image op.',
	'default'     => true,
	'init'        => function () {
		add_filter( 'site_status_test_php_modules', function ( $modules ) {
			unset( $modules['imagick'] );
			return $modules;
		} );
	},
];
