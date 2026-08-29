<?php
/**
 * Hide Login module.
 *
 * The helper functions live in includes/class-hide-login.php because a module
 * file can be included more than once (each Module_Loader instance re-includes
 * it), and redeclaring a function there would be fatal.
 *
 * @package WP_Admin_Speedboost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'slug'        => 'hide-login',
	'name'        => 'Hide Login URL',
	'description' => 'Move wp-login.php to a custom slug and send logged-out visitors hitting wp-admin or wp-login.php to a 404. Set the slug in the Login URL field below. Bookmark the new URL before you save.',
	'default'     => false,
	'init'        => 'wpasb_hide_login_init',
];
