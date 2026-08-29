<?php
/**
 * Uninstall handler. Runs when the user clicks "Delete" on the Plugins screen.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Removes every option and transient this plugin creates, on single site and on
 * each site of a multisite network. The previous version only deleted the option
 * on the current site, so network installs kept orphaned rows forever.
 */
function wpasb_uninstall_cleanup() {
	global $wpdb;

	delete_option( 'wpasb_modules' );
	delete_option( 'wpasb_login_slug' );
	delete_option( 'wpasb_login_redirect' );

	$user_transients = $wpdb->get_col(
		"SELECT option_name FROM {$wpdb->options}
		 WHERE option_name LIKE '\\_transient\\_wpasb\\_cleanup\\_report\\_%'
		    OR option_name LIKE '\\_transient\\_timeout\\_wpasb\\_cleanup\\_report\\_%'"
	);

	foreach ( $user_transients as $option_name ) {
		delete_option( $option_name );
	}
}

if ( is_multisite() ) {
	$site_ids = get_sites(
		[
			'fields'                 => 'ids',
			'number'                 => 0,
			'update_site_meta_cache' => false,
		]
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		wpasb_uninstall_cleanup();
		restore_current_blog();
	}
} else {
	wpasb_uninstall_cleanup();
}
