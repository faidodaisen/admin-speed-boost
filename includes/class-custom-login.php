<?php
/**
 * Custom Login engine.
 *
 * Restyles wp-login.php into a split-screen layout: the login form sits in a
 * left column, a splash image fills the right. The site logo replaces the
 * default WordPress logo, and the form is restyled Laravel-style. No markup is
 * injected into wp-login.php; everything is done with enqueued CSS plus a small
 * inline block that carries the splash and logo URLs.
 *
 * Helper functions live here (not in the module file) because a module file can
 * be included more than once, and redeclaring a function there would be fatal.
 *
 * @package WP_Admin_Speedboost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPASB_Custom_Login {

	/** Attachment ID of the admin-chosen splash image. 0 = use bundled default. */
	const OPT_SPLASH = 'wpasb_login_splash_id';

	public function __construct() {
		add_action( 'login_enqueue_scripts', [ $this, 'enqueue' ] );
		add_filter( 'login_headerurl', [ $this, 'header_url' ] );
		add_filter( 'login_headertext', [ $this, 'header_text' ] );
		add_filter( 'login_body_class', [ $this, 'body_class' ] );
	}

	/**
	 * Bundled fallback shipped with the plugin. Used when no image is chosen or
	 * the chosen attachment has been deleted.
	 */
	public static function default_splash_url() {
		return WPASB_URL . 'assets/login/splash-default.jpg';
	}

	public function splash_url() {
		$id = (int) get_option( self::OPT_SPLASH, 0 );

		if ( $id > 0 ) {
			$url = wp_get_attachment_image_url( $id, 'full' );
			if ( $url ) {
				return $url;
			}
		}

		return self::default_splash_url();
	}

	/**
	 * The site logo that replaces the WordPress logo. Prefers the theme's custom
	 * logo, then the site icon. Empty string means "no image, fall back to the
	 * site name as text".
	 */
	public function logo_url() {
		$custom_logo_id = (int) get_theme_mod( 'custom_logo' );
		if ( $custom_logo_id > 0 ) {
			$url = wp_get_attachment_image_url( $custom_logo_id, 'full' );
			if ( $url ) {
				return $url;
			}
		}

		if ( function_exists( 'get_site_icon_url' ) ) {
			$icon = get_site_icon_url( 192 );
			if ( $icon ) {
				return $icon;
			}
		}

		return '';
	}

	public function header_url() {
		return home_url( '/' );
	}

	public function header_text() {
		return get_bloginfo( 'name', 'display' );
	}

	public function body_class( $classes ) {
		if ( ! is_array( $classes ) ) {
			$classes = [];
		}
		$classes[] = 'wpasb-custom-login';
		return $classes;
	}

	public function enqueue() {
		wp_enqueue_style(
			'wpasb-login',
			WPASB_URL . 'assets/login/login.css',
			[ 'login' ],
			WPASB_VERSION
		);

		$splash = $this->css_url( $this->splash_url() );
		$logo   = $this->logo_url();

		$css  = ".wpasb-custom-login::before{background-image:url('" . $splash . "');}";

		if ( $logo ) {
			$logo = $this->css_url( $logo );
			$css .= ".wpasb-custom-login h1 a{background-image:url('" . $logo . "');background-size:contain;background-position:left center;width:100%;max-width:220px;height:72px;}";
		} else {
			// No logo image: show the site name as styled text instead of an empty box.
			$css .= ".wpasb-custom-login h1 a{background-image:none;width:auto;height:auto;line-height:1.2;font-size:26px;font-weight:700;color:#111827;text-indent:0;overflow:visible;text-decoration:none;}";
		}

		wp_add_inline_style( 'wpasb-login', $css );
	}

	/**
	 * Make a URL safe to drop inside a single-quoted CSS url('...') value.
	 *
	 * esc_url_raw sanitises the URL for storage/HTTP but does not encode the
	 * quote, paren, or backslash characters that could otherwise break out of
	 * the CSS string context. The sources here are admin-controlled (a Media
	 * Library attachment or the theme logo), so this is defense-in-depth rather
	 * than a fix for a known injection, but it costs nothing.
	 */
	private function css_url( $url ) {
		$url = esc_url_raw( (string) $url );

		return strtr(
			$url,
			[
				'\\' => '%5C',
				"'"  => '%27',
				'"'  => '%22',
				'('  => '%28',
				')'  => '%29',
			]
		);
	}
}

/**
 * Module entry point. Guarded so repeated module includes never double-bind.
 */
function wpasb_custom_login_init() {
	static $instance = null;

	if ( null === $instance ) {
		$instance = new WPASB_Custom_Login();
	}

	return $instance;
}
