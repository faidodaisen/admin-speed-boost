<?php
/**
 * Uninstall handler — runs when the user clicks "Delete" on the Plugins screen.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wpasb_modules' );
