<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'slug'        => 'vendor-notices-hide',
	'name'        => 'Hide Vendor Promo Notices',
	'description' => 'Suppress non-critical promo notices from common plugins. Error notices, the Updates screen, and Site Health stay untouched.',
	'default'     => true,
	'init'        => function () {
		add_action( 'admin_head', function () {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			// Never hide the core update nag on screens that exist to show updates,
			// otherwise an admin can miss a security release.
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			$id     = $screen ? $screen->id : '';

			$protected = [ 'update-core', 'update-core-network', 'plugins', 'plugins-network', 'site-health', 'themes' ];
			if ( in_array( $id, $protected, true ) ) {
				return;
			}

			$css = '.notice.update-nag,'
				. '.rank-math-notice:not(.notice-error),'
				. '.acf-admin-notice.notice-info,'
				. '.yoast-notice-go-premium,'
				. '.breakdance-notice:not(.notice-error)'
				. '{display:none !important;}';

			$css = apply_filters( 'wpasb_hidden_notice_css', $css );

			echo '<style id="wpasb-hide-notices">' . esc_html( $css ) . '</style>';
		}, 1 );
	},
];
