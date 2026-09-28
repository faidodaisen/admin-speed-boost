<?php
/**
 * Admin Menu Editor module.
 *
 * The engine lives in includes/class-admin-menu.php and the editor screen in
 * includes/class-admin-menu-page.php, because a module file can be included
 * more than once (each Module_Loader instance re-includes it).
 *
 * @package WP_Admin_Speedboost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'slug'        => 'admin-menu-editor',
	'name'        => 'Admin Menu Editor',
	'description' => 'Hide items from the wp-admin sidebar and block the pages behind them, for everyone except administrators. Pick the items under Speedboost > Admin Menu. This hides menus and pages, it does not remove user capabilities.',
	'default'     => false,
	'init'        => 'wpasb_admin_menu_init',
];
