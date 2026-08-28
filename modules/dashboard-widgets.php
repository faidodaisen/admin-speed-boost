<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'slug'        => 'dashboard-widgets',
	'name'        => 'Dashboard Widget Cleanup',
	'description' => 'Remove WP news, Site Health, and known vendor widgets that fire HTTP probes on dashboard load.',
	'default'     => true,
	'init'        => function () {
		add_action( 'wp_dashboard_setup', function () {
			$kill = apply_filters( 'wpasb_dashboard_widgets_removed', [
				'dashboard_primary',
				'dashboard_secondary',
				'dashboard_quick_press',
				'dashboard_incoming_links',
				'dashboard_plugins',
				'dashboard_recent_drafts',
				'dashboard_site_health',
				'rank_math_dashboard_widget',
				'wpseo-dashboard-overview',
			] );

			foreach ( (array) $kill as $id ) {
				foreach ( [ 'normal', 'side', 'column3', 'column4', 'advanced' ] as $context ) {
					remove_meta_box( $id, 'dashboard', $context );
				}
			}
		}, 999 );

		// Network dashboard uses its own screen id.
		add_action( 'wp_network_dashboard_setup', function () {
			remove_meta_box( 'network_dashboard_primary', 'dashboard-network', 'side' );
		}, 999 );
	},
];
