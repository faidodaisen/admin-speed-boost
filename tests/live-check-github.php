<?php
/**
 * Live check: runs the real updater against the real GitHub API, with an
 * installed version of 1.5.0, to prove a site one version behind is actually
 * offered v1.6.0 and a working download URL.
 *
 * Run: php tests/live-check-github.php
 */

define( 'ABSPATH', __DIR__ );
define( 'WPASB_VERSION', '1.5.0' ); // Pretend we are one release behind.
define( 'WPASB_FILE', 'C:/wp/wp-content/plugins/wp-admin-speedboost/wp-admin-speedboost.php' );

$GLOBALS['t'] = [ 'options' => [], 'transients' => [] ];

function get_option( $k, $d = false ) { return $GLOBALS['t']['options'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['t']['options'][ $k ] = $v; return true; }
function get_transient( $k ) { return $GLOBALS['t']['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['t']['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['t']['transients'][ $k ] ); return true; }
function apply_filters( $tag, $value ) { return $value; }
function add_filter() {}
function add_action() {}
function home_url() { return 'https://example.test'; }
function get_bloginfo( $s ) { return '6.7'; }
function plugin_basename( $f ) { return 'wp-admin-speedboost/wp-admin-speedboost.php'; }
function esc_html( $t ) { return htmlspecialchars( $t, ENT_QUOTES ); }
function esc_url( $t ) { return $t; }
function esc_attr( $t ) { return htmlspecialchars( $t, ENT_QUOTES ); }
function esc_html__( $t, $d = '' ) { return $t; }
function __( $t, $d = '' ) { return $t; }
function wpautop( $t ) { return '<p>' . $t . '</p>'; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function untrailingslashit( $s ) { return rtrim( $s, '/\\' ); }
function trailingslashit( $s ) { return untrailingslashit( $s ) . '/'; }

class WP_Error {
	public $m;
	public function __construct( $c = '', $m = '' ) { $this->m = $m; }
	public function get_error_message() { return $this->m; }
}

/** Real HTTP, via curl, so this genuinely hits GitHub. */
function wp_remote_get( $url, $args = [] ) {
	$ch = curl_init( $url );
	$headers = [];
	foreach ( $args['headers'] ?? [] as $k => $v ) {
		$headers[] = "$k: $v";
	}
	curl_setopt_array(
		$ch,
		[
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_FOLLOWLOCATION => true,
		]
	);
	$body = curl_exec( $ch );
	if ( false === $body ) {
		$err = curl_error( $ch );
		curl_close( $ch );
		return new WP_Error( 'http', $err );
	}
	$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );
	return [ 'response' => [ 'code' => $code ], 'body' => $body ];
}
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }

require_once __DIR__ . '/../includes/class-updater.php';

$fail = 0;
function check( $label, $ok, $detail = '' ) {
	global $fail;
	if ( ! $ok ) { $fail++; }
	echo ( $ok ? '  PASS  ' : '  FAIL  ' ) . $label . ( '' !== $detail ? " — $detail" : '' ) . "\n";
}

echo "\nLive GitHub lookup (installed 1.5.0)\n";
$release = WPASB_Updater::latest_release( true );

if ( null === $release ) {
	echo "  FAIL  no release returned — " . WPASB_Updater::last_error() . "\n";
	exit( 1 );
}

check( 'release found', true, $release['version'] );
check( 'version is 1.6.0', '1.6.0' === $release['version'], $release['version'] );
check( 'is offered as an update', WPASB_Updater::is_newer( $release['version'] ) );
check( 'built zip asset used (not zipball)', true === $release['asset'], $release['package'] );

echo "\nUpdate payload WordPress would receive\n";
$u   = WPASB_Updater::instance();
$pay = $u->check_for_update( false, [], 'wp-admin-speedboost/wp-admin-speedboost.php' );
check( 'payload returned', is_array( $pay ) );
check( 'new_version = 1.6.0', '1.6.0' === ( $pay['new_version'] ?? '' ) );
check( 'package set', ! empty( $pay['package'] ) );
echo '        package: ' . $pay['package'] . "\n";

echo "\nDownload URL actually serves a zip\n";
$ch = curl_init( $pay['package'] );
curl_setopt_array( $ch, [ CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60 ] );
$zip  = curl_exec( $ch );
$code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
curl_close( $ch );

check( 'HTTP 200', 200 === $code, (string) $code );
check( 'looks like a zip (PK header)', is_string( $zip ) && 'PK' === substr( $zip, 0, 2 ) );
check( 'non-trivial size', strlen( (string) $zip ) > 50000, strlen( (string) $zip ) . ' bytes' );

$tmp = sys_get_temp_dir() . '/asb-live.zip';
file_put_contents( $tmp, $zip );
$za = new ZipArchive();
if ( true === $za->open( $tmp ) ) {
	$roots = [];
	$has_main = false;
	for ( $i = 0; $i < $za->numFiles; $i++ ) {
		$name    = $za->getNameIndex( $i );
		$roots[] = explode( '/', $name )[0];
		if ( 'wp-admin-speedboost/wp-admin-speedboost.php' === $name ) {
			$has_main = true;
			$header   = $za->getFromIndex( $i );
		}
	}
	$roots = array_unique( $roots );
	check( 'single root folder wp-admin-speedboost', [ 'wp-admin-speedboost' ] === array_values( $roots ), implode( ',', $roots ) );
	check( 'main plugin file present', $has_main );
	check( 'shipped header says 1.6.0', false !== strpos( $header ?? '', 'Version:           1.6.0' ) );
	check( 'updater class shipped', false !== $za->locateName( 'wp-admin-speedboost/includes/class-updater.php' ) );
	check( 'no tests folder shipped', false === $za->locateName( 'wp-admin-speedboost/tests/test-updater.php' ) );
	check( 'no dotfiles shipped', 0 === count( array_filter( $roots, fn( $r ) => str_starts_with( $r, '.' ) ) ) );
	$za->close();
} else {
	check( 'zip opens', false );
}
@unlink( $tmp );

echo "\n" . ( $fail ? "FAILED: $fail" : 'ALL PASS' ) . "\n";
exit( $fail ? 1 : 0 );
