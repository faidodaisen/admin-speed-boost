<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'slug'        => 'disable-app-passwords',
	'name'        => 'Disable Application Passwords',
	'description' => 'Hide the Application Passwords UI on user profiles. Saves one REST call per profile load.',
	'default'     => true,
	'init'        => function () {
		add_filter( 'wp_is_application_passwords_available', '__return_false' );
	},
];
