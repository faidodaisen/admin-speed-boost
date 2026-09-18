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
		add_filter( 'login_message', [ $this, 'branding' ] );

		// The centered language switcher sits under the form and clashes with the
		// split-screen layout. Hide it. Admins can still switch UI language under
		// Users > Profile.
		add_filter( 'login_display_language_dropdown', '__return_false' );
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

	/**
	 * Branding block printed above the form: the site logo (with rounded corners
	 * and a soft shadow, so a plain square logo still looks intentional) next to
	 * the site title and tagline.
	 *
	 * Rendered through the login_message filter. That filter runs inside
	 * login_header(), which fires on every login action, so the branding shows on
	 * the login, lost-password and reset screens alike. $message carries any
	 * existing notice and must be preserved.
	 */
	public function branding( $message ) {
		$logo    = $this->logo_url();
		$title   = get_bloginfo( 'name', 'display' );
		$tagline = get_bloginfo( 'description', 'display' );

		ob_start();
		?>
		<div class="wpasb-brand">
			<?php if ( $logo ) : ?>
				<img class="wpasb-brand-logo" src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $title ); ?>">
			<?php endif; ?>
			<div class="wpasb-brand-text">
				<?php if ( $title ) : ?>
					<span class="wpasb-brand-title"><?php echo esc_html( $title ); ?></span>
				<?php endif; ?>
				<?php if ( $tagline ) : ?>
					<span class="wpasb-brand-tagline"><?php echo esc_html( $tagline ); ?></span>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean() . $message;
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

		// The default h1 logo is hidden by CSS in favour of the branding block
		// printed via login_message, so only the splash needs an inline URL here.
		$css = ".wpasb-custom-login::before{background-image:url('" . $splash . "');}";

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
