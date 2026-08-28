<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'slug'        => 'emoji-oembed',
	'name'        => 'Disable Emoji & oEmbed',
	'description' => 'Strip the WP emoji loader and oEmbed discovery scripts. Saves an inline script and 2 HTTP calls per page load.',
	'default'     => true,
	'init'        => function () {
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'admin_print_styles',  'print_emoji_styles' );
		remove_action( 'wp_head',             'print_emoji_detection_script', 7 );
		remove_action( 'wp_print_styles',     'print_emoji_styles' );
		remove_filter( 'the_content_feed',    'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss',    'wp_staticize_emoji' );
		remove_filter( 'wp_mail',             'wp_staticize_emoji_for_email' );

		add_filter( 'tiny_mce_plugins', function ( $plugins ) {
			return is_array( $plugins ) ? array_diff( $plugins, [ 'wpemoji' ] ) : [];
		} );

		add_action( 'init', function () {
			wp_deregister_script( 'wp-embed' );
		}, 99 );

		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );
	},
];
