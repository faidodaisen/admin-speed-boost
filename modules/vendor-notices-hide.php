<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'slug'        => 'vendor-notices-hide',
	'name'        => 'Hide Vendor Promo Notices',
	'description' => 'Suppress non-critical promo notices from common plugins (Rank Math, ACF, Yoast, Breakdance, update-nag). Keeps critical errors visible.',
	'default'     => true,
	'init'        => function () {
		add_action( 'admin_head', function () {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			echo '<style>
				.notice.update-nag,
				.rank-math-notice:not(.notice-error),
				.acf-admin-notice.notice-info,
				.yoast-notice-go-premium,
				.breakdance-notice:not(.notice-error) { display:none !important; }
			</style>';
		}, 1 );
	},
];
