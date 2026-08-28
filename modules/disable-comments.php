<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'slug'        => 'disable-comments',
	'name'        => 'Disable Comments Site-Wide',
	'description' => 'Strip comments from every post type, hide the Comments admin area, drop comment REST routes, and kill comment RSS feeds + pingbacks. Use for institutional or business sites.',
	'default'     => false,
	'init'        => function () {
		add_action( 'init', function () {
			foreach ( get_post_types() as $pt ) {
				if ( post_type_supports( $pt, 'comments' ) ) {
					remove_post_type_support( $pt, 'comments' );
					remove_post_type_support( $pt, 'trackbacks' );
				}
			}
		}, 100 );

		add_filter( 'comments_open',  '__return_false', 20 );
		add_filter( 'pings_open',     '__return_false', 20 );
		add_filter( 'comments_array', '__return_empty_array', 10 );

		add_action( 'admin_menu', function () {
			remove_menu_page( 'edit-comments.php' );
			remove_submenu_page( 'options-general.php', 'options-discussion.php' );
		} );

		add_action( 'wp_before_admin_bar_render', function () {
			global $wp_admin_bar;
			if ( $wp_admin_bar ) {
				$wp_admin_bar->remove_menu( 'comments' );
			}
		} );

		add_action( 'admin_init', function () {
			global $pagenow;
			if ( in_array( $pagenow, [ 'edit-comments.php', 'options-discussion.php' ], true ) ) {
				wp_safe_redirect( admin_url() );
				exit;
			}
			foreach ( get_post_types() as $pt ) {
				remove_meta_box( 'commentstatusdiv', $pt, 'normal' );
				remove_meta_box( 'commentsdiv',      $pt, 'normal' );
				remove_meta_box( 'trackbacksdiv',    $pt, 'normal' );
			}
		} );

		add_filter( 'rest_endpoints', function ( $endpoints ) {
			foreach ( array_keys( $endpoints ) as $route ) {
				if ( strpos( $route, '/wp/v2/comments' ) === 0 ) {
					unset( $endpoints[ $route ] );
				}
			}
			return $endpoints;
		}, 20 );

		remove_action( 'wp_head', 'feed_links_extra', 3 );
		add_filter( 'feed_links_show_comments_feed', '__return_false' );

		add_filter( 'wp_headers', function ( $headers ) {
			unset( $headers['X-Pingback'] );
			return $headers;
		} );

		add_action( 'xmlrpc_call', function ( $action ) {
			if ( $action === 'pingback.ping' ) {
				wp_die( 'Pingbacks disabled.', 'Forbidden', [ 'response' => 403 ] );
			}
		} );
	},
];
