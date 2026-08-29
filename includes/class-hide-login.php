<?php
/**
 * Hide Login engine.
 *
 * Moves wp-login.php to a custom slug and blocks wp-admin for logged-out users.
 * Behaviour ported from WPS Hide Login (GPL-2.0-or-later).
 *
 * @package WP_Admin_Speedboost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPASB_Hide_Login {

	const OPT_SLUG     = 'wpasb_login_slug';
	const OPT_REDIRECT = 'wpasb_login_redirect';

	/** Request was for the real wp-login.php and must be 404'd. */
	private $wp_login_php = false;

	public function __construct() {
		add_action( 'plugins_loaded', [ $this, 'plugins_loaded' ], 9999 );
		add_action( 'wp_loaded', [ $this, 'wp_loaded' ] );
		add_action( 'setup_theme', [ $this, 'setup_theme' ], 1 );
		add_action( 'init', [ $this, 'block_signup_access' ] );
		add_action( 'template_redirect', [ $this, 'redirect_export_data' ] );

		add_filter( 'site_url', [ $this, 'filter_site_url' ], 10, 4 );
		add_filter( 'network_site_url', [ $this, 'filter_network_site_url' ], 10, 3 );
		add_filter( 'wp_redirect', [ $this, 'filter_wp_redirect' ], 10, 2 );
		add_filter( 'login_url', [ $this, 'filter_login_url' ], 10, 3 );
		add_filter( 'site_option_welcome_email', [ $this, 'welcome_email' ] );
		add_filter( 'user_request_action_email_content', [ $this, 'user_request_action_email_content' ], 999, 2 );

		// Core would otherwise bounce /login and /admin straight back to wp-admin.
		remove_action( 'template_redirect', 'wp_redirect_admin_locations', 1000 );
	}

	/* ---------------------------------------------------------------- slugs */

	public static function default_slug() {
		return 'login';
	}

	public static function default_redirect() {
		return '404';
	}

	public function new_login_slug() {
		$slug = get_option( self::OPT_SLUG );
		$slug = is_string( $slug ) ? trim( $slug ) : '';

		return '' !== $slug ? $slug : self::default_slug();
	}

	public function new_redirect_slug() {
		$slug = get_option( self::OPT_REDIRECT );
		$slug = is_string( $slug ) ? trim( $slug ) : '';

		return '' !== $slug ? $slug : self::default_redirect();
	}

	private function use_trailing_slashes() {
		return '/' === substr( (string) get_option( 'permalink_structure' ), -1, 1 );
	}

	private function user_trailingslashit( $string ) {
		return $this->use_trailing_slashes() ? trailingslashit( $string ) : untrailingslashit( $string );
	}

	public function new_login_url( $scheme = null ) {
		$url = home_url( '/', $scheme );

		if ( get_option( 'permalink_structure' ) ) {
			return $this->user_trailingslashit( $url . $this->new_login_slug() );
		}

		return $url . '?' . $this->new_login_slug();
	}

	public function new_redirect_url( $scheme = null ) {
		$url = home_url( '/', $scheme );

		if ( get_option( 'permalink_structure' ) ) {
			return $this->user_trailingslashit( $url . $this->new_redirect_slug() );
		}

		return $url . '?' . $this->new_redirect_slug();
	}

	/**
	 * Slugs that would collide with WP query vars.
	 *
	 * @return string[]
	 */
	public static function forbidden_slugs() {
		$wp = new WP();

		return array_merge( $wp->public_query_vars, $wp->private_query_vars );
	}

	/* -------------------------------------------------------------- routing */

	private function wp_template_loader() {
		global $pagenow;

		$pagenow = 'index.php';

		if ( ! defined( 'WP_USE_THEMES' ) ) {
			define( 'WP_USE_THEMES', true );
		}

		wp();

		require_once ABSPATH . WPINC . '/template-loader.php';
		die;
	}

	public function plugins_loaded() {
		global $pagenow;

		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		$uri     = rawurldecode( wp_unslash( $_SERVER['REQUEST_URI'] ) );
		$request = wp_parse_url( $uri );
		$path    = isset( $request['path'] ) ? $request['path'] : '';

		if ( ( false !== strpos( $uri, 'wp-login.php' ) || ( $path && untrailingslashit( $path ) === site_url( 'wp-login', 'relative' ) ) ) && ! is_admin() ) {

			$this->wp_login_php = true;

			$_SERVER['REQUEST_URI'] = $this->user_trailingslashit( '/' . str_repeat( '-/', 10 ) );

			$pagenow = 'index.php';

		} elseif (
			( $path && untrailingslashit( $path ) === home_url( $this->new_login_slug(), 'relative' ) )
			|| ( ! get_option( 'permalink_structure' )
				&& isset( $_GET[ $this->new_login_slug() ] )
				&& '' === $_GET[ $this->new_login_slug() ] )
		) {

			$_SERVER['SCRIPT_NAME'] = $this->new_login_slug();

			$pagenow = 'wp-login.php';

		} elseif ( ( false !== strpos( $uri, 'wp-register.php' ) || ( $path && untrailingslashit( $path ) === site_url( 'wp-register', 'relative' ) ) ) && ! is_admin() ) {

			$this->wp_login_php = true;

			$_SERVER['REQUEST_URI'] = $this->user_trailingslashit( '/' . str_repeat( '-/', 10 ) );

			$pagenow = 'index.php';
		}
	}

	public function setup_theme() {
		global $pagenow;

		if ( ! is_user_logged_in() && 'customize.php' === $pagenow ) {
			wp_die( esc_html__( 'This has been disabled.', 'wp-admin-speedboost' ), 403 );
		}
	}

	public function wp_loaded() {
		global $pagenow;

		$request = ! empty( $_SERVER['REQUEST_URI'] )
			? wp_parse_url( rawurldecode( wp_unslash( $_SERVER['REQUEST_URI'] ) ) )
			: [];
		$path    = isset( $request['path'] ) ? $request['path'] : '';

		// Password-protected post submissions post to wp-login.php on purpose.
		if ( isset( $_GET['action'] ) && 'postpass' === $_GET['action'] && isset( $_POST['post_password'] ) ) {
			return;
		}

		if (
			is_admin() && ! is_user_logged_in()
			&& ! defined( 'WP_CLI' ) && ! wp_doing_ajax() && ! wp_doing_cron()
			&& 'admin-post.php' !== $pagenow
			&& '/wp-admin/options.php' !== $path
		) {
			wp_safe_redirect( $this->new_redirect_url() );
			die;
		}

		if ( ! is_user_logged_in() && isset( $_GET['wc-ajax'] ) && 'profile.php' === $pagenow ) {
			wp_safe_redirect( $this->new_redirect_url() );
			die;
		}

		if ( ! is_user_logged_in() && '/wp-admin/options.php' === $path ) {
			wp_redirect( $this->new_redirect_url() );
			die;
		}

		if ( 'wp-login.php' === $pagenow && $path && $path !== $this->user_trailingslashit( $path ) && get_option( 'permalink_structure' ) ) {

			wp_safe_redirect(
				$this->user_trailingslashit( $this->new_login_url() )
				. ( ! empty( $_SERVER['QUERY_STRING'] ) ? '?' . $_SERVER['QUERY_STRING'] : '' )
			);
			die;

		} elseif ( $this->wp_login_php ) {

			$referer = wp_get_referer();

			if ( $referer && false !== strpos( $referer, 'wp-activate.php' ) ) {
				$parsed = wp_parse_url( $referer );

				if ( ! empty( $parsed['query'] ) ) {
					parse_str( $parsed['query'], $referer_args );

					require_once ABSPATH . WPINC . '/ms-functions.php';

					if ( ! empty( $referer_args['key'] ) ) {
						$result = wpmu_activate_signup( $referer_args['key'] );

						if ( is_wp_error( $result )
							&& ( 'already_active' === $result->get_error_code() || 'blog_taken' === $result->get_error_code() ) ) {

							wp_safe_redirect(
								$this->new_login_url()
								. ( ! empty( $_SERVER['QUERY_STRING'] ) ? '?' . $_SERVER['QUERY_STRING'] : '' )
							);
							die;
						}
					}
				}
			}

			$this->wp_template_loader();

		} elseif ( 'wp-login.php' === $pagenow ) {

			global $error, $interim_login, $action, $user_login;

			$requested_redirect_to = isset( $_REQUEST['redirect_to'] ) ? $_REQUEST['redirect_to'] : '';

			if ( is_user_logged_in() && ! isset( $_REQUEST['action'] ) ) {
				$user = wp_get_current_user();

				/** Filter the destination for an already logged-in visitor hitting the login slug. */
				$logged_in_redirect = apply_filters( 'wpasb_logged_in_redirect', admin_url(), $requested_redirect_to, $user );

				wp_safe_redirect( $logged_in_redirect );
				die;
			}

			require_once ABSPATH . 'wp-login.php';
			die;
		}
	}

	public function block_signup_access() {
		if ( is_multisite() || empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		$uri = rawurldecode( wp_unslash( $_SERVER['REQUEST_URI'] ) );

		if ( ( false !== strpos( $uri, 'wp-signup' ) || false !== strpos( $uri, 'wp-activate' ) )
			&& false === apply_filters( 'wpasb_hide_login_signup_enable', false ) ) {

			wp_die( esc_html__( 'This feature is not enabled.', 'wp-admin-speedboost' ) );
		}
	}

	public function redirect_export_data() {
		if ( ! isset( $_GET['action'], $_GET['request_id'], $_GET['confirm_key'] ) || 'confirmaction' !== $_GET['action'] ) {
			return;
		}

		$request_id = (int) $_GET['request_id'];
		$key        = sanitize_text_field( wp_unslash( $_GET['confirm_key'] ) );

		if ( ! is_wp_error( wp_validate_user_request_key( $request_id, $key ) ) ) {
			wp_safe_redirect(
				add_query_arg(
					[
						'action'      => 'confirmaction',
						'request_id'  => $request_id,
						'confirm_key' => $key,
					],
					$this->new_login_url()
				)
			);
			exit;
		}
	}

	/* -------------------------------------------------------------- filters */

	public function filter_site_url( $url, $path, $scheme, $blog_id ) {
		return $this->filter_wp_login_php( $url, $scheme );
	}

	public function filter_network_site_url( $url, $path, $scheme ) {
		return $this->filter_wp_login_php( $url, $scheme );
	}

	public function filter_wp_redirect( $location, $status = 302 ) {
		if ( false !== strpos( $location, 'https://wordpress.com/wp-login.php' ) ) {
			return $location;
		}

		return $this->filter_wp_login_php( $location );
	}

	public function filter_wp_login_php( $url, $scheme = null ) {
		global $pagenow;

		$origin_url = $url;

		if ( false !== strpos( $url, 'wp-login.php?action=postpass' ) ) {
			return $url;
		}

		if ( is_multisite() && 'install.php' === $pagenow ) {
			return $url;
		}

		if ( false !== strpos( $url, 'wp-login.php' ) && false === strpos( (string) wp_get_referer(), 'wp-login.php' ) ) {

			if ( is_ssl() ) {
				$scheme = 'https';
			}

			$args = explode( '?', $url );

			if ( isset( $args[1] ) ) {
				parse_str( $args[1], $args );

				if ( isset( $args['login'] ) ) {
					$args['login'] = rawurlencode( $args['login'] );
				}

				$url = add_query_arg( $args, $this->new_login_url( $scheme ) );
			} else {
				$url = $this->new_login_url( $scheme );
			}
		}

		// A post password form submit must keep the original wp-login.php target.
		if ( isset( $_POST['post_password'] ) ) {
			global $current_user;

			if ( ! is_user_logged_in()
				&& is_wp_error( wp_authenticate_username_password( null, isset( $current_user->user_login ) ? $current_user->user_login : '', wp_unslash( $_POST['post_password'] ) ) ) ) {
				return $origin_url;
			}
		}

		if ( ! is_user_logged_in() && isset( $_GET['gf_page'] ) && file_exists( WP_CONTENT_DIR . '/plugins/gravityforms/gravityforms.php' ) ) {
			return $origin_url;
		}

		return $url;
	}

	public function filter_login_url( $login_url, $redirect, $force_reauth ) {
		if ( is_404() ) {
			return '#';
		}

		if ( false === $force_reauth || empty( $redirect ) ) {
			return $login_url;
		}

		$redirect = explode( '?', $redirect );

		if ( admin_url( 'options.php' ) === $redirect[0] ) {
			$login_url = admin_url();
		}

		return $login_url;
	}

	public function welcome_email( $value ) {
		return str_replace( 'wp-login.php', trailingslashit( $this->new_login_slug() ), $value );
	}

	public function user_request_action_email_content( $email_text, $email_data ) {
		if ( empty( $email_data['confirm_url'] ) ) {
			return $email_text;
		}

		return str_replace(
			'###CONFIRM_URL###',
			esc_url_raw( str_replace( $this->new_login_slug() . '/', 'wp-login.php', $email_data['confirm_url'] ) ),
			$email_text
		);
	}
}

/**
 * Boots the hide-login engine unless a dedicated hide-login plugin is already active.
 */
function wpasb_hide_login_init() {
	// Emergency escape hatch. If an admin loses the slug they can add this to
	// wp-config.php over FTP and get wp-login.php back without touching the DB.
	if ( defined( 'WPASB_DISABLE_HIDE_LOGIN' ) && WPASB_DISABLE_HIDE_LOGIN ) {
		return;
	}

	if ( wpasb_hide_login_conflict() ) {
		add_action( 'admin_notices', 'wpasb_hide_login_conflict_notice' );

		return;
	}

	new WPASB_Hide_Login();
}

/**
 * @return string Conflicting plugin name, empty when none.
 */
function wpasb_hide_login_conflict() {
	$known = [
		'wps-hide-login/wps-hide-login.php'   => 'WPS Hide Login',
		'rename-wp-login/rename-wp-login.php' => 'Rename wp-login.php',
	];

	$active = (array) get_option( 'active_plugins', [] );

	if ( is_multisite() ) {
		$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
	}

	foreach ( $known as $basename => $label ) {
		if ( in_array( $basename, $active, true ) ) {
			return $label;
		}
	}

	return '';
}

function wpasb_hide_login_conflict_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-warning"><p>%s</p></div>',
		sprintf(
			/* translators: %s: conflicting plugin name */
			esc_html__( 'Admin Speedboost: the Hide Login URL module is off because %s is active. Deactivate that plugin to use the built-in module.', 'wp-admin-speedboost' ),
			'<strong>' . esc_html( wpasb_hide_login_conflict() ) . '</strong>'
		)
	);
}
