<?php
/**
 * Sidebar style: a roomier wp-admin sidebar and brand colours for it.
 *
 * Part of the Admin Menu Editor module and set on the same screen. Purely
 * cosmetic: it applies to every user, and the stylesheet only loads when one
 * of the two switches is on (or on the editor screen, for the live preview).
 *
 * Lives in includes/ because module files can be included more than once.
 *
 * @package WP_Admin_Speedboost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPASB_Admin_Sidebar {

	const OPT   = 'wpasb_sidebar_style';
	const WIDTH = 200;

	/** WordPress's own default ("Default" colour scheme) values. */
	const DEFAULT_SIDEBAR = '#1d2327';
	const DEFAULT_SUBMENU = '#2c3338';
	const DEFAULT_ACTIVE  = '#2271b1';

	const INK_LIGHT = '#ffffff';
	const INK_DARK  = '#1d2327';

	private static $booted = false;

	public static function init() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_filter( 'admin_body_class', [ __CLASS__, 'body_class' ] );
		// After wp_color_scheme_settings() (admin_head, 10) prints the scheme.
		add_action( 'admin_head', [ __CLASS__, 'icon_colors' ], 20 );
	}

	// ------------------------------------------------------------------
	// Settings (pure)
	// ------------------------------------------------------------------

	public static function defaults() {
		return [
			'comfortable' => false,
			'brand'       => false,
			'sidebar'     => self::DEFAULT_SIDEBAR,
			'submenu'     => self::DEFAULT_SUBMENU,
			'active'      => self::DEFAULT_ACTIVE,
		];
	}

	public static function get_settings() {
		$raw = get_option( self::OPT, [] );

		return self::clean( is_array( $raw ) ? $raw : [] );
	}

	/**
	 * @return array{comfortable:bool, brand:bool, sidebar:string, submenu:string, active:string}
	 */
	public static function clean( array $raw ) {
		$out = self::defaults();

		$out['comfortable'] = ! empty( $raw['comfortable'] );
		$out['brand']       = ! empty( $raw['brand'] );

		foreach ( [ 'sidebar', 'submenu', 'active' ] as $key ) {
			$hex = isset( $raw[ $key ] ) ? self::hex( $raw[ $key ] ) : '';
			if ( '' !== $hex ) {
				$out[ $key ] = $hex;
			}
		}

		return $out;
	}

	/**
	 * The posted fieldset, or the stored value when it was not posted.
	 * Checkboxes are absent when off, so a fieldset is recognised by its
	 * colour fields (always posted) or its marker.
	 */
	public static function sanitize_input( $input, array $current ) {
		if ( ! is_array( $input ) || ( empty( $input['present'] ) && ! isset( $input['sidebar'] ) ) ) {
			return $current;
		}

		return self::clean( $input );
	}

	/**
	 * Lowercase #rrggbb, or '' when the value is not a hex colour.
	 */
	public static function hex( $value ) {
		if ( ! is_string( $value ) ) {
			return '';
		}
		$value = strtolower( trim( $value ) );
		if ( ! preg_match( '/^#?([0-9a-f]{3}|[0-9a-f]{6})$/', $value, $m ) ) {
			return '';
		}
		$h = $m[1];
		if ( 3 === strlen( $h ) ) {
			$h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
		}
		return '#' . $h;
	}

	private static function rgb( $hex ) {
		$hex = ltrim( self::hex( $hex ), '#' );
		if ( '' === $hex ) {
			return [ 0, 0, 0 ];
		}
		return [ hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) ];
	}

	/**
	 * WCAG relative luminance, 0..1.
	 */
	public static function luminance( $hex ) {
		$c = array_map(
			function ( $v ) {
				$v /= 255;
				return $v <= 0.03928 ? $v / 12.92 : pow( ( $v + 0.055 ) / 1.055, 2.4 );
			},
			self::rgb( $hex )
		);
		return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
	}

	public static function contrast( $a, $b ) {
		$la = self::luminance( $a );
		$lb = self::luminance( $b );
		return ( max( $la, $lb ) + 0.05 ) / ( min( $la, $lb ) + 0.05 );
	}

	/**
	 * White or near-black, whichever reads better on $bg.
	 */
	public static function ink( $bg ) {
		return self::contrast( $bg, self::INK_LIGHT ) >= self::contrast( $bg, self::INK_DARK ) ? self::INK_LIGHT : self::INK_DARK;
	}

	/**
	 * $a moved towards $b by $amount (0..1), as #rrggbb.
	 */
	public static function mix( $a, $b, $amount ) {
		$x   = self::rgb( $a );
		$y   = self::rgb( $b );
		$out = '#';
		for ( $i = 0; $i < 3; $i++ ) {
			$out .= sprintf( '%02x', (int) round( $x[ $i ] + ( $y[ $i ] - $x[ $i ] ) * $amount ) );
		}
		return $out;
	}

	/**
	 * Every colour the stylesheet uses, derived from the three picks. Text
	 * colours follow each background, so a light brand colour gets dark text.
	 * Mirrored in assets/admin-menu.js (palette()) for the live preview.
	 */
	public static function palette( array $s ) {
		$sb_ink  = self::ink( $s['sidebar'] );
		$sub_ink = self::ink( $s['submenu'] );
		$act_ink = self::ink( $s['active'] );

		return [
			'sb-bg'     => $s['sidebar'],
			'sb-ink'    => $sb_ink,
			'sb-muted'  => self::mix( $s['sidebar'], $sb_ink, 0.72 ),
			'sb-hover'  => self::mix( $s['sidebar'], $sb_ink, 0.08 ),
			'sub-bg'    => $s['submenu'],
			'sub-ink'   => $sub_ink,
			'sub-muted' => self::mix( $s['submenu'], $sub_ink, 0.72 ),
			'act-bg'    => $s['active'],
			'act-ink'   => $act_ink,
		];
	}

	public static function css_vars( array $s ) {
		$out = ':root{--wpasb-sb-width:' . (int) self::WIDTH . 'px;';
		foreach ( self::palette( $s ) as $name => $value ) {
			$out .= '--wpasb-' . $name . ':' . $value . ';';
		}
		return $out . '}';
	}

	public static function is_active( array $s ) {
		return $s['comfortable'] || $s['brand'];
	}

	public static function classes( array $s ) {
		$out = [];
		if ( $s['comfortable'] ) {
			$out[] = 'wpasb-sb-comfy';
		}
		if ( $s['brand'] ) {
			$out[] = 'wpasb-sb-brand';
		}
		return $out;
	}

	// ------------------------------------------------------------------
	// Runtime hooks
	// ------------------------------------------------------------------

	private static function on_editor_screen() {
		// phpcs:ignore WordPress.Security.NonceVerification -- read-only routing.
		return isset( $_GET['page'] ) && WPASB_Admin_Menu::PAGE_SLUG === $_GET['page'];
	}

	public static function enqueue( $hook = '' ) {
		$s = self::get_settings();
		// The editor screen always loads it so the switches preview live.
		if ( ! self::is_active( $s ) && ! self::on_editor_screen() ) {
			return;
		}

		wp_enqueue_style( 'wpasb-sidebar', WPASB_URL . 'assets/sidebar.css', [], WPASB_VERSION );
		wp_add_inline_style( 'wpasb-sidebar', self::css_vars( $s ) );
	}

	public static function body_class( $classes ) {
		$add = self::classes( self::get_settings() );

		return $add ? rtrim( (string) $classes ) . ' ' . implode( ' ', $add ) . ' ' : $classes;
	}

	/**
	 * Plugin menu icons drawn as SVG are recoloured by core's svg-painter
	 * from the colour scheme. Only a light brand sidebar needs that changed;
	 * on a dark one core's grey icons already read fine.
	 */
	public static function icon_colors() {
		$s = self::get_settings();
		if ( ! $s['brand'] || self::INK_DARK !== self::ink( $s['sidebar'] ) ) {
			return;
		}
		$p     = self::palette( $s );
		$icons = [
			'base'    => $p['sb-muted'],
			'focus'   => $p['sb-ink'],
			'current' => $p['act-ink'],
		];
		echo '<script>window._wpColorScheme=window._wpColorScheme||{};window._wpColorScheme.icons=' . wp_json_encode( $icons ) . ';</script>' . "\n";
	}
}
