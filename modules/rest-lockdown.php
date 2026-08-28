<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'slug'        => 'rest-lockdown',
	'name'        => 'REST API User Lockdown',
	'description' => 'Block /wp-json/wp/v2/users for unauthenticated requests. Stops user enumeration via the REST API.',
	'default'     => true,
	'init'        => function () {
		add_filter( 'rest_endpoints', function ( $endpoints ) {
			if ( ! is_user_logged_in() ) {
				foreach ( [ '/wp/v2/users', '/wp/v2/users/(?P<id>[\d]+)' ] as $route ) {
					if ( isset( $endpoints[ $route ] ) ) {
						unset( $endpoints[ $route ] );
					}
				}
			}
			return $endpoints;
		} );
	},
];
