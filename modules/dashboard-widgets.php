<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'slug'        => 'dashboard-widgets',
	'name'        => 'Dashboard Widget Cleanup',
	'description' => 'Remove WP news, Site Health, and known vendor widgets (Rank Math, Yoast) that fire HTTP probes on dashboard load.',
	'default'     => true,
	'init'        => function () {
		add_action( 'wp_dashboard_setup', function () {
			$kill = [
				'dashboard_primary',
				'dashboard_secondary',
				'dashboard_quick_press',
				'dashboard_incoming_links',
				'dashboard_plugins',
				'dashboard_recent_drafts',
				'dashboard_site_health',
				'rank_math_dashboard_widget',
				'wpseo-dashboard-overview',
			];
			foreach ( $kill as $id ) {
				remove_meta_box( $id, 'dashboard', 'normal' );
				remove_meta_box( $id, 'dashboard', 'side' );
			}
		}, 999 );
	},
];
