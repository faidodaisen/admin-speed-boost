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
			if ( ! $bar instanceof WP_Admin_Bar ) {
				return;
			}
			foreach ( [ 'wp-logo', 'comments', 'updates' ] as $node ) {
				$bar->remove_node( $node );
			}
		}, 999 );
	},
];
