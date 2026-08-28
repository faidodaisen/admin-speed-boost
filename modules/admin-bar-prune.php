<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'slug'        => 'admin-bar-prune',
	'name'        => 'Admin Bar Cleanup',
	'description' => 'Remove the WP logo, Comments shortcut, and Updates icon from the admin bar.',
	'default'     => true,
	'init'        => function () {
		add_action( 'admin_bar_menu', function ( $bar ) {
			$bar->remove_node( 'wp-logo' );
			$bar->remove_node( 'comments' );
			$bar->remove_node( 'updates' );
		}, 999 );
	},
];
