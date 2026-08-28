<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'slug'        => 'disable-comments',
	'name'        => 'Disable Comments Site-Wide',
	'description' => 'Strip comments from every post type, hide the Comments admin area, drop comment REST routes, and kill the comment feed and pingbacks. Use for institutional or business sites.',
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
		}, 9999 );

		add_action( 'admin_bar_menu', function ( $bar ) {
			if ( $bar instanceof WP_Admin_Bar ) {
				$bar->remove_node( 'comments' );
			}
		}, 999 );

		/**
		 * The Activity dashboard widget still queries comments and links into
		 * edit-comments.php even when comments are off everywhere else.
		 *
		 * Rather than re-implementing the core widget (which changes between
		 * releases), the original callback is kept and wrapped: comment queries are
		 * forced empty only for the duration of that callback. "Recently Published"
		 * and "Publishing Soon" keep working.
		 */
		add_action( 'wp_dashboard_setup', function () {
			global $wp_meta_boxes;

			if ( empty( $wp_meta_boxes['dashboard']['normal']['core']['dashboard_activity']['callback'] ) ) {
				return;
			}

			$original = $wp_meta_boxes['dashboard']['normal']['core']['dashboard_activity']['callback'];

			$wp_meta_boxes['dashboard']['normal']['core']['dashboard_activity']['callback'] = function () use ( $original ) {
				$silence = '__return_empty_array';

				add_filter( 'the_comments', $silence, 9999 );
				if ( is_callable( $original ) ) {
					call_user_func( $original );
				}
				remove_filter( 'the_comments', $silence, 9999 );
			};
		}, 1000 );

		// "At a Glance" prints its own comment counter and links to edit-comments.php.
		// dashboard_glance_items only covers custom rows, so the comment line is
		// stripped from the rendered markup instead.
		add_action( 'admin_head-index.php', function () {
			echo '<style id="wpasb-hide-glance-comments">'
				. '#dashboard_right_now .comment-count,'
				. '#dashboard_right_now .comment-mod-count'
				. '{display:none !important;}'
				. '</style>';
		} );

		add_action( 'admin_init', function () {
			global $pagenow;

			if ( in_array( $pagenow, [ 'edit-comments.php', 'options-discussion.php' ], true )
				&& ! wp_doing_ajax()
			) {
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
				if ( 0 === strpos( $route, '/wp/v2/comments' ) ) {
					unset( $endpoints[ $route ] );
				}
			}
			return $endpoints;
		}, 20 );

		// Only suppress the comment feed link. The previous implementation removed
		// feed_links_extra outright, which also killed category, tag, author and
		// post-type archive feeds that have nothing to do with comments.
		add_filter( 'feed_links_show_comments_feed', '__return_false' );
		add_filter( 'post_comments_feed_link', '__return_empty_string' );

		add_filter( 'wp_headers', function ( $headers ) {
			if ( is_array( $headers ) ) {
				unset( $headers['X-Pingback'] );
			}
			return $headers;
		} );

		add_filter( 'bloginfo_url', function ( $output, $show ) {
			return ( 'pingback_url' === $show ) ? '' : $output;
		}, 10, 2 );

		add_filter( 'xmlrpc_methods', function ( $methods ) {
			unset( $methods['pingback.ping'], $methods['pingback.extensions.getPingbacks'] );
			return $methods;
		} );
	},
];
