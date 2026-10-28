<?php
/**
 * Sidebar style: settings cleaning, sanitising and the derived palette.
 *
 * Pure logic, no WordPress. Run: php tests/test-admin-sidebar.php
 */

define( 'ABSPATH', __DIR__ . '/' );

function add_action() {}
function add_filter() {}

require __DIR__ . '/../includes/class-admin-sidebar.php';

$fail = 0;
$pass = 0;
function check( $label, $cond ) {
	global $fail, $pass;
	if ( $cond ) {
		$pass++;
	} else {
		$fail++;
		echo "FAIL: $label\n";
	}
}

$S = 'WPASB_Admin_Sidebar';

// ------------------------------------------------------------------ hex
check( 'hex 6 digit', '#1d2327' === $S::hex( '#1D2327' ) );
check( 'hex 3 digit expands', '#aabbcc' === $S::hex( 'abc' ) );
check( 'hex rejects junk', '' === $S::hex( 'red' ) );
check( 'hex rejects css injection', '' === $S::hex( '#fff;}body{display:none' ) );
check( 'hex rejects non-string', '' === $S::hex( [ '#fff' ] ) );

// ------------------------------------------------------------------ clean
$d = $S::clean( [] );
check( 'defaults off', false === $d['comfortable'] && false === $d['brand'] );
check( 'default colours are core', '#1d2327' === $d['sidebar'] && '#2c3338' === $d['submenu'] && '#2271b1' === $d['active'] );

$c = $S::clean( [ 'comfortable' => '1', 'sidebar' => 'bogus', 'submenu' => '#ABCDEF', 'extra' => 'x' ] );
check( 'checkbox on', true === $c['comfortable'] );
check( 'bad colour falls back', '#1d2327' === $c['sidebar'] );
check( 'good colour kept lowercase', '#abcdef' === $c['submenu'] );
check( 'unknown keys dropped', ! isset( $c['extra'] ) );

// ------------------------------------------------------------------ sanitize
$current = $S::clean( [ 'comfortable' => true, 'brand' => true, 'sidebar' => '#112233' ] );
check( 'null input keeps current', $current === $S::sanitize_input( null, $current ) );
check( 'unrelated array keeps current', $current === $S::sanitize_input( [ 'foo' => 1 ], $current ) );

$posted = $S::sanitize_input( [ 'present' => '1', 'sidebar' => '#0a0a0a', 'submenu' => '#202020', 'active' => '#ff6600' ], $current );
check( 'unchecked boxes save as off', false === $posted['comfortable'] && false === $posted['brand'] );
check( 'posted colour saved', '#ff6600' === $posted['active'] );
check( 'present marker not stored', ! isset( $posted['present'] ) );
check( 'second pass (clean value) is idempotent', $posted === $S::sanitize_input( $posted, $current ) );

// ------------------------------------------------------------------ palette
check( 'white ink on dark', '#ffffff' === $S::ink( '#1d2327' ) );
check( 'dark ink on light', '#1d2327' === $S::ink( '#f5f5f5' ) );
check( 'dark ink on yellow', '#1d2327' === $S::ink( '#ffd400' ) );
check( 'mix midpoint', '#808080' === $S::mix( '#000000', '#ffffff', 0.5 ) || '#7f7f7f' === $S::mix( '#000000', '#ffffff', 0.5 ) );

$p = $S::palette( $S::clean( [ 'sidebar' => '#ffffff', 'submenu' => '#0b3d91', 'active' => '#ffd400' ] ) );
check( 'palette sidebar ink dark on white', '#1d2327' === $p['sb-ink'] );
check( 'palette submenu ink white on navy', '#ffffff' === $p['sub-ink'] );
check( 'palette active ink dark on yellow', '#1d2327' === $p['act-ink'] );
foreach ( $p as $k => $v ) {
	check( "palette $k is hex", (bool) preg_match( '/^#[0-9a-f]{6}$/', $v ) );
}
check( 'muted text still readable (>= 4.5:1)', $S::contrast( $p['sb-muted'], $p['sb-bg'] ) >= 4.5 );

$vars = $S::css_vars( $S::defaults() );
check( 'css vars carry width', false !== strpos( $vars, '--wpasb-sb-width:200px;' ) );
check( 'css vars carry sidebar', false !== strpos( $vars, '--wpasb-sb-bg:#1d2327;' ) );

// ------------------------------------------------------------------ classes
check( 'no classes when off', [] === $S::classes( $S::defaults() ) );
check( 'both classes', [ 'wpasb-sb-comfy', 'wpasb-sb-brand' ] === $S::classes( $S::clean( [ 'comfortable' => 1, 'brand' => 1 ] ) ) );
check( 'inactive when off', false === $S::is_active( $S::defaults() ) );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
