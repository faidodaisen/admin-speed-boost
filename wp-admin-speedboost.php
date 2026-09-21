<?php
/**
 * Plugin Name:       WP Admin Speedboost
 * Plugin URI:        https://fidodesign.dev/
 * Description:       Modular WordPress admin performance booster. 17 toggle-able modules: heartbeat throttle, dashboard widget cleanup, disable comments, REST lockdown, emoji/oEmbed disable, jQuery Migrate kill, classic editor, XML-RPC disable, hide login URL, custom login page, duplicate page & post, and more. Plus one-click DB cleanup.
 * Version:           1.7.0
 * Requires at least: 5.6
 * Tested up to:      7.1
 * Requires PHP:      7.4
 * Author:            FidoDesign
 * Author URI:        https://fidodesign.dev/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-admin-speedboost
 * Domain Path:       /languages
 * Update URI:        https://github.com/faidodaisen/admin-speed-boost
 *
 * @package WP_Admin_Speedboost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPASB_VERSION', '1.7.0' );
define( 'WPASB_FILE', __FILE__ );
define( 'WPASB_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPASB_URL', plugin_dir_url( __FILE__ ) );
define( 'WPASB_OPTION', 'wpasb_modules' );

require_once WPASB_DIR . 'includes/module-labels.php';
require_once WPASB_DIR . 'includes/class-hide-login.php';
require_once WPASB_DIR . 'includes/class-custom-login.php';
require_once WPASB_DIR . 'includes/class-duplicate-post.php';
require_once WPASB_DIR . 'includes/class-module-loader.php';
require_once WPASB_DIR . 'includes/class-settings-page.php';
require_once WPASB_DIR . 'includes/class-db-cleanup.php';
require_once WPASB_DIR . 'includes/class-updater.php';
require_once WPASB_DIR . 'includes/class-plugin.php';

/**
 * Activation must be registered from the main plugin file at load time, not from
 * inside a constructor that runs on every request. Registering it in the class
 * constructor meant the hook was attached after activation had already fired.
 */
register_activation_hook( __FILE__, [ 'WPASB_Plugin', 'on_activate' ] );

add_action( 'plugins_loaded', [ 'WPASB_Plugin', 'instance' ], 5 );

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	if ( ! is_array( $links ) ) {
		$links = [];
	}

	array_unshift(
		$links,
		sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=wp-admin-speedboost' ) ),
			esc_html__( 'Settings', 'wp-admin-speedboost' )
		)
	);

	return $links;
} );
