<?php
/**
 * Custom Login Page module.
 *
 * The engine lives in includes/class-custom-login.php because a module file can
 * be included more than once (each Module_Loader instance re-includes it), and
 * redeclaring a class there would be fatal.
 *
 * @package WP_Admin_Speedboost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'slug'        => 'custom-login',
	'name'        => 'Custom Login Page',
	'description' => 'Restyle wp-login.php into a split-screen layout: login form on the left with your site logo, a splash image on the right. Choose the splash image below. A bundled image is used until you pick one.',
	'default'     => false,
	'init'        => 'wpasb_custom_login_init',
];
