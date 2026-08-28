<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'slug'        => 'disable-app-passwords',
	'name'        => 'Disable Application Passwords',
	'description' => 'Hide the Application Passwords UI on user profiles. Turn this OFF if you use the REST API, WP-CLI over HTTP, Jetpack, or any app that authenticates with an application password.',
	'default'     => false,
	'init'        => function () {
		add_filter( 'wp_is_application_passwords_available', '__return_false' );
	},
];
