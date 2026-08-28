<?php
/**
 * Plugin Name:       WP Admin Speedboost
 * Plugin URI:        https://fidodesign.dev/
 * Description:       Modular WordPress admin performance booster. 12 toggle-able modules: heartbeat throttle, dashboard widget cleanup, disable comments, REST lockdown, emoji/oEmbed disable, jQuery Migrate kill, and more. Plus one-click DB cleanup.
 * Version:           1.0.0
 * Author:            FidoDesign
 * Author URI:        https://fidodesign.dev/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-admin-speedboost
 * Requires at least: 5.5
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPASB_VERSION', '1.0.0' );
define( 'WPASB_FILE', __FILE__ );
define( 'WPASB_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPASB_URL', plugin_dir_url( __FILE__ ) );
define( 'WPASB_OPTION', 'wpasb_modules' );

require_once WPASB_DIR . 'includes/class-module-loader.php';
require_once WPASB_DIR . 'includes/class-settings-page.php';
require_once WPASB_DIR . 'includes/class-db-cleanup.php';
require_once WPASB_DIR . 'includes/class-plugin.php';

WPASB_Plugin::instance();

add_filter( 'plugin_action_links_' . plugin_basename( WPASB_FILE ), function ( $links ) {
	$settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=wp-admin-speedboost' ) ) . '">Settings</a>';
	array_unshift( $links, $settings_link );
	return $links;
} );
