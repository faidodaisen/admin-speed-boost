<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'slug'        => 'rest-lockdown',
	'name'        => 'REST API User Lockdown',
	'description' => 'Block /wp-json/wp/v2/users for unauthenticated requests. Stops user enumeration via the REST API without breaking logged-in editors or authenticated integrations.',
	'default'     => true,
	'init'        => function () {
		/**
		 * Enforced at dispatch, not at route registration.
		 *
		 * rest_endpoints fires while the server builds its route map, which happens
		 * before application-password / basic-auth authentication has resolved. Removing
		 * routes there would 404 legitimate authenticated API clients. rest_pre_dispatch
		 * runs after authentication, so the current user is known and accurate.
		 */
		add_filter( 'rest_pre_dispatch', function ( $result, $server, $request ) {
			if ( ! is_null( $result ) ) {
				return $result;
			}

			$route = $request->get_route();

			if ( 0 !== strpos( $route, '/wp/v2/users' ) ) {
				return $result;
			}

			if ( is_user_logged_in() ) {
				return $result;
			}

			/**
			 * Allow specific user routes through even when logged out.
			 * Empty by default; some SSO / registration flows need /users/register.
			 */
			$allowed = (array) apply_filters( 'wpasb_rest_user_routes_allowlist', [] );
			foreach ( $allowed as $allowed_route ) {
				if ( 0 === strpos( $route, $allowed_route ) ) {
					return $result;
				}
			}

			return new WP_Error(
				'rest_user_cannot_view',
				__( 'Sorry, you are not allowed to list users.', 'wp-admin-speedboost' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}, 10, 3 );

		// Also strip author links from REST post responses so enumeration cannot
		// be walked indirectly by anonymous clients.
		add_filter( 'rest_prepare_user', function ( $response ) {
			return is_user_logged_in() ? $response : new WP_Error(
				'rest_user_cannot_view',
				__( 'Sorry, you are not allowed to list users.', 'wp-admin-speedboost' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}, 10, 1 );
	},
];
