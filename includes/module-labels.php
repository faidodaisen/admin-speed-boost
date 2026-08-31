<?php
/**
 * Translatable module labels.
 *
 * Module files are discovered on `plugins_loaded`, which is earlier than `init`.
 * Calling __() there triggers the WP 6.7+ "_load_textdomain_just_in_time was
 * called incorrectly" notice. So module files carry plain English strings and the
 * translated copy lives here, resolved lazily only when the settings page renders
 * (well after `init`).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @return array<string, array{name:string, description:string}>
 */
function wpasb_module_labels() {
	return [
		'heartbeat'             => [
			'name'        => __( 'Heartbeat Throttle', 'wp-admin-speedboost' ),
			'description' => __( 'Throttle admin-ajax heartbeat to 60s and disable it on screens that do not need it. The post editor and post list keep heartbeat so autosave and post locking still work.', 'wp-admin-speedboost' ),
		],
		'dashboard-widgets'     => [
			'name'        => __( 'Dashboard Widget Cleanup', 'wp-admin-speedboost' ),
			'description' => __( 'Remove WP news, Site Health, and known vendor widgets that fire HTTP probes on dashboard load.', 'wp-admin-speedboost' ),
		],
		'emoji-oembed'          => [
			'name'        => __( 'Disable Emoji & oEmbed', 'wp-admin-speedboost' ),
			'description' => __( 'Strip the WP emoji loader and front-end oEmbed discovery. The block editor keeps wp-embed so embed blocks still render in admin.', 'wp-admin-speedboost' ),
		],
		'rest-lockdown'         => [
			'name'        => __( 'REST API User Lockdown', 'wp-admin-speedboost' ),
			'description' => __( 'Block /wp-json/wp/v2/users for unauthenticated requests. Stops user enumeration without breaking logged-in editors or authenticated integrations.', 'wp-admin-speedboost' ),
		],
		'jquery-migrate'        => [
			'name'        => __( 'Disable jQuery Migrate (Frontend)', 'wp-admin-speedboost' ),
			'description' => __( 'Drop the jquery-migrate dependency on the public site. Admin and the customizer keep it. Disable this module if an old theme or plugin relies on removed jQuery APIs.', 'wp-admin-speedboost' ),
		],
		'disable-comments'      => [
			'name'        => __( 'Disable Comments Site-Wide', 'wp-admin-speedboost' ),
			'description' => __( 'Strip comments from every post type, hide the Comments admin area, drop comment REST routes, and kill the comment feed and pingbacks. Use for institutional or business sites.', 'wp-admin-speedboost' ),
		],
		'site-health-prune'     => [
			'name'        => __( 'Site Health Probe Prune', 'wp-admin-speedboost' ),
			'description' => __( 'Remove async HTTP probes (loopback, WordPress.org, background updates) that fire on Site Health loads. Direct security and PHP tests are kept.', 'wp-admin-speedboost' ),
		],
		'admin-bar-prune'       => [
			'name'        => __( 'Admin Bar Cleanup', 'wp-admin-speedboost' ),
			'description' => __( 'Remove the WP logo, Comments shortcut, and Updates icon from the admin bar.', 'wp-admin-speedboost' ),
		],
		'lazy-gravatars'        => [
			'name'        => __( 'Lazy-Load Gravatars', 'wp-admin-speedboost' ),
			'description' => __( 'Add loading="lazy" and decoding="async" to Gravatar img tags that do not already declare them. Defers external image fetches.', 'wp-admin-speedboost' ),
		],
		'disable-app-passwords' => [
			'name'        => __( 'Disable Application Passwords', 'wp-admin-speedboost' ),
			'description' => __( 'Hide the Application Passwords UI on user profiles. Leave this OFF if you use the REST API, Jetpack, or any app that authenticates with an application password.', 'wp-admin-speedboost' ),
		],
		'vendor-notices-hide'   => [
			'name'        => __( 'Hide Vendor Promo Notices', 'wp-admin-speedboost' ),
			'description' => __( 'Suppress non-critical promo notices from common plugins. Error notices, the Updates screen, and Site Health stay untouched.', 'wp-admin-speedboost' ),
		],
		'imagick-nag-silence'   => [
			'name'        => __( 'Silence Imagick Site Health Nag', 'wp-admin-speedboost' ),
			'description' => __( 'Remove the "Imagick not installed" recommendation from Site Health when GD is available. GD ships with PHP and handles every core image operation.', 'wp-admin-speedboost' ),
		],
		'classic-editor'        => [
			'name'        => __( 'Enable Classic Editor', 'wp-admin-speedboost' ),
			'description' => __( 'Use the classic TinyMCE editor instead of the block editor for posts, pages and widgets. Turn this OFF if your content relies on Gutenberg blocks.', 'wp-admin-speedboost' ),
		],
		'disable-xmlrpc'        => [
			'name'        => __( 'Disable XML-RPC', 'wp-admin-speedboost' ),
			'description' => __( 'Turn off xmlrpc.php, pingbacks and the RSD link. Blocks a common brute-force and DDoS amplification target. Turn this OFF if you use the WordPress mobile app, Jetpack, or a remote publishing client.', 'wp-admin-speedboost' ),
		],
		'hide-login'            => [
			'name'        => __( 'Hide Login URL', 'wp-admin-speedboost' ),
			'description' => __( 'Move wp-login.php to a custom slug and send logged-out visitors hitting wp-admin or wp-login.php to a 404. Set the slug in the Login URL field below. Bookmark the new URL before you save.', 'wp-admin-speedboost' ),
		],
	];
}
