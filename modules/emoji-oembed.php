<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'slug'        => 'emoji-oembed',
	'name'        => 'Disable Emoji & oEmbed',
	'description' => 'Strip the WP emoji loader and front-end oEmbed discovery. The block editor keeps wp-embed so embed blocks still render in admin.',
	'default'     => true,
	'init'        => function () {
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'admin_print_styles',  'print_emoji_styles' );
		remove_action( 'wp_head',             'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles',     'print_emoji_styles' );
		remove_action( 'embed_head',          'print_emoji_detection_script' );

		// Since WP 6.4 the emoji stylesheet is enqueued rather than printed.
		// Removing only print_emoji_styles left the stylesheet loading.
		remove_action( 'wp_enqueue_scripts',    'wp_enqueue_emoji_styles' );
		remove_action( 'enqueue_embed_scripts', 'wp_enqueue_emoji_styles' );

		remove_filter( 'the_content_feed',    'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss',    'wp_staticize_emoji' );
		remove_filter( 'wp_mail',             'wp_staticize_emoji_for_email' );

		add_filter( 'emoji_svg_url', '__return_false' );

		add_filter( 'tiny_mce_plugins', function ( $plugins ) {
			return is_array( $plugins ) ? array_values( array_diff( $plugins, [ 'wpemoji' ] ) ) : [];
		} );

		add_filter( 'wp_resource_hints', function ( $hints, $relation ) {
			if ( 'dns-prefetch' !== $relation || ! is_array( $hints ) ) {
				return $hints;
			}
			foreach ( $hints as $i => $hint ) {
				if ( is_string( $hint ) && false !== strpos( $hint, 's.w.org' ) ) {
					unset( $hints[ $i ] );
				}
			}
			return array_values( $hints );
		}, 10, 2 );

		// wp-embed is a dependency of the block editor bundle. Deregistering it in
		// admin breaks embed blocks, so scope this to the front end only.
		add_action( 'wp_footer', function () {
			wp_deregister_script( 'wp-embed' );
		}, 1 );

		// WP registers discovery links at BOTH priority 4 and the default 10
		// (see wp-includes/default-filters.php). Removing one leaves the other live.
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links', 4 );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );
	},
];
