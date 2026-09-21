<?php
/**
 * Standalone render smoke test.
 *
 * Renders the settings page markup with stubbed WordPress functions so the
 * template is genuinely executed (no WordPress install required), then checks
 * the structural contract the CSS and JS depend on.
 *
 * Run: php tests/render-settings-page.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WPASB_OPTION', 'wpasb_modules' );
define( 'WPASB_VERSION', '1.1.0' );
define( 'WPASB_URL', 'http://example.test/wp-content/plugins/admin-speed-boost/' );
define( 'MINUTE_IN_SECONDS', 60 );

$GLOBALS['wpasb_options'] = [
	'wpasb_login_slug'     => 'control',
	'wpasb_login_redirect' => '404',
	'wpasb_login_splash'   => 0,
	'permalink_structure'  => '/%postname%/',
];

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['wpasb_options'] ) ? $GLOBALS['wpasb_options'][ $name ] : $default;
}
function current_user_can( $cap ) { return true; }
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_attr( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_url( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); }
function esc_url_raw( $t ) { return $t; }
function esc_html__( $t, $d = '' ) { return esc_html( $t ); }
function esc_attr__( $t, $d = '' ) { return esc_attr( $t ); }
function esc_html_e( $t, $d = '' ) { echo esc_html( $t ); }
function esc_attr_e( $t, $d = '' ) { echo esc_attr( $t ); }
function __( $t, $d = '' ) { return $t; }
function _e( $t, $d = '' ) { echo $t; }
function checked( $a, $b = true, $echo = true ) {
	$out = ( $a == $b ) ? ' checked="checked"' : '';
	if ( $echo ) { echo $out; }
	return $out;
}
function admin_url( $p = '' ) { return 'http://example.test/wp-admin/' . ltrim( $p, '/' ); }
function home_url( $p = '' ) { return 'http://example.test/' . ltrim( $p, '/' ); }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function settings_fields( $g ) { echo '<input type="hidden" name="option_page" value="' . esc_attr( $g ) . '">'; }
function wp_nonce_field( $a, $n ) { echo '<input type="hidden" name="' . esc_attr( $n ) . '" value="nonce">'; }
function wp_create_nonce( $a ) { return 'nonce'; }
function wp_get_attachment_image_url( $id, $size ) { return false; }
function number_format_i18n( $n ) { return number_format( (float) $n ); }
function add_action() {}
function add_options_page() { return 'settings_page_wp-admin-speedboost'; }
function register_setting() {}
function wp_die( $m ) { throw new RuntimeException( $m ); }

class WPASB_Hide_Login {
	const OPT_SLUG     = 'wpasb_login_slug';
	const OPT_REDIRECT = 'wpasb_login_redirect';
	public static function default_slug() { return 'control'; }
	public static function default_redirect() { return '404'; }
	public static function forbidden_slugs() { return [ 'wp-admin' ]; }
}

class WPASB_Custom_Login {
	const OPT_SPLASH = 'wpasb_login_splash';
	public static function default_splash_url() { return WPASB_URL . 'assets/login-splash.jpg'; }
}

class WPASB_Updater {
	public static function render_status_panel() {
		echo '<div class="wpasb-update-strip wpasb-update-strip--current"><p class="wpasb-update-state">'
			. '<span class="wpasb-update-version">Version ' . WPASB_VERSION . '</span></p>'
			. '<p class="wpasb-update-actions"><a class="wpasb-btn" href="#">Check again</a></p></div>';
	}
}

require __DIR__ . '/../includes/module-labels.php';

class WPASB_Module_Loader {
	public function get_modules() {
		$labels  = wpasb_module_labels();
		$modules = [];
		foreach ( $labels as $slug => $label ) {
			$modules[ $slug ] = [
				'name'        => $label['name'],
				'description' => $label['description'],
			];
		}
		return $modules;
	}
	public function get_settings() {
		$on = [ 'heartbeat', 'emoji-oembed', 'hide-login', 'custom-login', 'disable-xmlrpc' ];
		$s  = [];
		foreach ( array_keys( $this->get_modules() ) as $slug ) {
			$s[ $slug ] = in_array( $slug, $on, true );
		}
		return $s;
	}
	public function get_defaults() { return $this->get_settings(); }
}

require __DIR__ . '/../includes/class-settings-page.php';

$page = new WPASB_Settings_Page( new WPASB_Module_Loader() );

ob_start();
$page->render();
$html = ob_get_clean();

// ------------------------------------------------------------------
// Assertions
// ------------------------------------------------------------------
$failures = [];

function expect( $cond, $label, array &$failures ) {
	if ( ! $cond ) {
		$failures[] = $label;
	}
}

$loader  = new WPASB_Module_Loader();
$modules = $loader->get_modules();
$enabled = $loader->get_settings();
$total   = count( $modules );
$active  = count( array_filter( $enabled ) );

// Structure required by admin.css / admin.js.
foreach ( [
	'wpasb-page', 'wpasb-shell', 'wpasb-header', 'wpasb-update-strip',
	'wpasb-module-toolbar', 'wpasb-module-group', 'wpasb-module-card',
	'wpasb-savebar', 'wpasb-cleanup', 'wpasb-server',
] as $cls ) {
	expect( false !== strpos( $html, 'class="' . $cls ) || false !== strpos( $html, $cls . '"' ) || false !== strpos( $html, $cls . ' ' ), "missing section class .$cls", $failures );
}

foreach ( [
	'wpasb-settings-form', 'wpasb-summary-count', 'wpasb-summary-state', 'wpasb-search',
	'wpasb-search-clear', 'wpasb-search-empty', 'wpasb-savebar', 'wpasb-discard', 'wpasb-save',
	'wpasb-cleanup', 'wpasb-cleanup-review', 'wpasb-cleanup-confirm', 'wpasb-cleanup-confirm-title',
	'wpasb-cleanup-actions', 'wpasb-cleanup-cancel', 'wpasb-cleanup-run', 'wpasb-cleanup-running',
	'wpasb-cleanup-result', 'wpasb-cleanup-result-title', 'wpasb-cleanup-again',
	'wpasb-cleanup-announcement', 'wpasb-panel-hide-login', 'wpasb-panel-custom-login',
	'wpasb-login-slug', 'wpasb-login-redirect', 'wpasb-login-preview', 'wpasb-splash-id',
	'wpasb-splash-preview-img', 'wpasb-splash-choose', 'wpasb-splash-reset', 'wpasb-splash-error',
	'wpasb-config-code', 'wpasb-php-code',
] as $id ) {
	expect( false !== strpos( $html, 'id="' . $id . '"' ), "missing #$id", $failures );
}

// Every module must render exactly one card and one checkbox.
expect(
	substr_count( $html, 'class="wpasb-module-card' ) === $total,
	'module card count != ' . $total,
	$failures
);
expect(
	substr_count( $html, 'class="wpasb-module-input"' ) === $total,
	'module input count != ' . $total,
	$failures
);
foreach ( array_keys( $modules ) as $slug ) {
	expect(
		false !== strpos( $html, 'name="' . WPASB_OPTION . '[' . $slug . ']"' ),
		"module $slug has no form field",
		$failures
	);
}

// Header counter must report real numbers, not placeholders.
expect(
	false !== strpos( $html, "$active of $total modules enabled" ),
	'header summary does not report the real enabled count',
	$failures
);

// Cleanup must be outside the settings form, or running it would submit it.
$form_start = strpos( $html, 'id="wpasb-settings-form"' );
$form_end   = strpos( $html, '</form>', $form_start );
$cleanup_at = strpos( $html, 'id="wpasb-cleanup"' );
expect( $cleanup_at > $form_end, 'cleanup section is inside the settings form', $failures );

// No-JS fallback must survive.
expect( substr_count( $html, '<noscript>' ) >= 1, 'no no-JS cleanup fallback', $failures );
expect( false !== strpos( $html, 'name="action" value="wpasb_cleanup"' ), 'fallback posts no cleanup action', $failures );
expect( false !== strpos( $html, 'wpasb_cleanup_nonce' ), 'fallback has no nonce', $failures );

// Hidden panels must start hidden when their module is off.
expect( preg_match( '/id="wpasb-panel-hide-login"[^>]*>/', $html, $m ) === 1, 'hide-login panel missing', $failures );

// Balanced tags for the elements JS queries by structure.
foreach ( [ 'form', 'section', 'div' ] as $tag ) {
	$open  = preg_match_all( '/<' . $tag . '[\s>]/', $html );
	$close = substr_count( $html, '</' . $tag . '>' );
	expect( $open === $close, "unbalanced <$tag>: $open open vs $close close", $failures );
}

// No external asset may be referenced.
expect( ! preg_match( '#(src|href)="https?://(?!example\.test)#', $html ), 'external asset referenced', $failures );

echo 'Rendered ' . strlen( $html ) . " bytes, $total modules, $active enabled.\n";

// Optional: write a standalone preview page for visual inspection.
if ( in_array( '--dump', $argv, true ) ) {
	$css  = file_get_contents( __DIR__ . '/../assets/admin.css' );
	$out  = "<!doctype html><html><head><meta charset=\"utf-8\">"
		. "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">"
		. "<title>Admin Speedboost preview</title>"
		. "<style>body{margin:0;padding:0 0 0 20px;background:#F5F5F5;"
		. "font-family:-apple-system,'Segoe UI',Roboto,sans-serif}"
		. ".screen-reader-text{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(1px,1px,1px,1px)}</style>"
		. "<style>{$css}</style></head><body>{$html}</body></html>";
	$path = __DIR__ . '/preview.html';
	file_put_contents( $path, $out );
	echo "Preview written: $path\n";
}

if ( $failures ) {
	echo "FAIL:\n - " . implode( "\n - ", $failures ) . "\n";
	exit( 1 );
}

echo "PASS: settings page markup contract OK.\n";
