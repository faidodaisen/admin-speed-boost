<?php
/**
 * Standalone harness for WPASB_Updater — no WordPress required.
 *
 * Stubs the handful of WP functions the updater touches and feeds it a canned
 * GitHub /releases payload, so release selection, version comparison and the
 * transient injection can be verified before shipping.
 *
 * Run: php tests/test-updater.php
 */

define( 'ABSPATH', __DIR__ );
define( 'WPASB_VERSION', '1.5.0' );
define( 'WPASB_FILE', 'C:/wp/wp-content/plugins/wp-admin-speedboost/wp-admin-speedboost.php' );

// ---------------------------------------------------------------- WP stubs

$GLOBALS['wpasb_test'] = [
	'options'    => [],
	'transients' => [],
	'http'       => null,
	'last_url'   => '',
	'last_args'  => [],
];

function get_option( $k, $d = false ) { return $GLOBALS['wpasb_test']['options'][ $k ] ?? $d; }
function update_option( $k, $v ) { $GLOBALS['wpasb_test']['options'][ $k ] = $v; return true; }
function get_transient( $k ) { return $GLOBALS['wpasb_test']['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $t = 0 ) { $GLOBALS['wpasb_test']['transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['wpasb_test']['transients'][ $k ] ); return true; }
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
	public $msg;
	public function __construct( $c = '', $m = '' ) { $this->msg = $m; }
	public function get_error_message() { return $this->msg; }
}

function wp_remote_get( $url, $args = [] ) {
	$GLOBALS['wpasb_test']['last_url']  = $url;
	$GLOBALS['wpasb_test']['last_args'] = $args;
	return $GLOBALS['wpasb_test']['http'];
}
function wp_remote_retrieve_response_code( $r ) { return $r['response']['code'] ?? 0; }
function wp_remote_retrieve_body( $r ) { return $r['body'] ?? ''; }

require_once __DIR__ . '/../includes/class-updater.php';

// ---------------------------------------------------------------- helpers

$failed = 0;
$passed = 0;

function check( $label, $ok, $detail = '' ) {
	global $failed, $passed;
	if ( $ok ) {
		$passed++;
		echo "  PASS  $label\n";
	} else {
		$failed++;
		echo "  FAIL  $label" . ( '' !== $detail ? " — $detail" : '' ) . "\n";
	}
}

function reset_state( array $releases, $code = 200, array $options = [] ) {
	$GLOBALS['wpasb_test']['transients'] = [];
	$GLOBALS['wpasb_test']['options']    = $options;
	$GLOBALS['wpasb_test']['http']       = [
		'response' => [ 'code' => $code ],
		'body'     => json_encode( $releases ),
	];
}

function release( $tag, array $extra = [] ) {
	return array_merge(
		[
			'tag_name'     => $tag,
			'draft'        => false,
			'prerelease'   => false,
			'html_url'     => 'https://github.com/faidodaisen/admin-speed-boost/releases/tag/' . $tag,
			'body'         => "- did a thing\n- did another",
			'published_at' => '2026-09-20T00:00:00Z',
			// GitHub always sends this, asset or not.
			'zipball_url'  => 'https://api.github.com/repos/faidodaisen/admin-speed-boost/zipball/' . $tag,
			'assets'       => [
				[
					'name'                 => 'wp-admin-speedboost-' . ltrim( $tag, 'v' ) . '.zip',
					'url'                  => 'https://api.github.com/repos/faidodaisen/admin-speed-boost/releases/assets/1',
					'browser_download_url' => 'https://github.com/faidodaisen/admin-speed-boost/releases/download/' . $tag . '/x.zip',
				],
			],
		],
		$extra
	);
}

$u = WPASB_Updater::instance();

echo "\nNewest-release selection\n";
reset_state( [ release( 'v1.4.0' ), release( 'v1.6.0' ), release( 'v1.5.1' ) ] );
$r = WPASB_Updater::latest_release();
check( 'picks highest version regardless of order', '1.6.0' === $r['version'], var_export( $r['version'] ?? null, true ) );
check( 'prefers the .zip asset over zipball', false !== strpos( $r['package'], '/releases/download/' ), $r['package'] );
check( 'asset flag set', true === $r['asset'] );

echo "\nFiltering\n";
reset_state( [ release( 'v2.0.0', [ 'draft' => true ] ), release( 'v1.6.0' ) ] );
check( 'drafts skipped', '1.6.0' === WPASB_Updater::latest_release()['version'] );

reset_state( [ release( 'v2.0.0-beta1', [ 'prerelease' => true ] ), release( 'v1.6.0' ) ] );
check( 'prereleases skipped by default', '1.6.0' === WPASB_Updater::latest_release()['version'] );

reset_state(
	[ release( 'v2.0.0-beta1', [ 'prerelease' => true ] ), release( 'v1.6.0' ) ],
	200,
	[ WPASB_Updater::OPTION => [ 'prereleases' => true ] ]
);
check( 'prereleases honoured when opted in', '2.0.0-beta1' === WPASB_Updater::latest_release()['version'] );

reset_state( [ release( 'nightly' ), release( 'v1.6.0' ) ] );
check( 'unparseable tag skipped', '1.6.0' === WPASB_Updater::latest_release()['version'] );

reset_state( [ release( 'v1.6.0', [ 'assets' => [] ] ) ] );
$r = WPASB_Updater::latest_release();
check( 'falls back to zipball when no asset', false === $r['asset'] );

echo "\nVersion comparison (installed 1.5.0)\n";
check( 'newer is newer', true === WPASB_Updater::is_newer( '1.6.0' ) );
check( 'same version is not an update', false === WPASB_Updater::is_newer( '1.5.0' ) );
check( 'older is not an update', false === WPASB_Updater::is_newer( '1.4.9' ) );

echo "\nupdate_plugins_github.com hook\n";
reset_state( [ release( 'v1.6.0' ) ] );
$out = $u->check_for_update( false, [], 'wp-admin-speedboost/wp-admin-speedboost.php' );
check( 'returns payload for our plugin file', is_array( $out ) && '1.6.0' === $out['new_version'] );
check( 'payload carries plugin basename', 'wp-admin-speedboost/wp-admin-speedboost.php' === $out['plugin'] );
$other = $u->check_for_update( false, [], 'some-other/plugin.php' );
check( 'passes through other plugins untouched', false === $other );

reset_state( [ release( 'v1.5.0' ) ] );
check( 'no payload when remote equals installed', false === $u->check_for_update( false, [], 'wp-admin-speedboost/wp-admin-speedboost.php' ) );

echo "\nTransient injection\n";
reset_state( [ release( 'v1.6.0' ) ] );
$t = (object) [ 'response' => [], 'no_update' => [ 'wp-admin-speedboost/wp-admin-speedboost.php' => (object) [] ] ];
$t = $u->inject_update( $t );
check( 'entry added to response', isset( $t->response['wp-admin-speedboost/wp-admin-speedboost.php'] ) );
check( 'removed from no_update', ! isset( $t->no_update['wp-admin-speedboost/wp-admin-speedboost.php'] ) );

reset_state( [ release( 'v1.5.0' ) ] );
$t = (object) [ 'response' => [ 'wp-admin-speedboost/wp-admin-speedboost.php' => (object) [ 'new_version' => '1.5.0' ] ] ];
$t = $u->inject_update( $t );
check( 'stale entry cleared when no update', ! isset( $t->response['wp-admin-speedboost/wp-admin-speedboost.php'] ) );

check( 'non-object transient returned as-is', false === $u->inject_update( false ) );

echo "\nPrivate repo / token handling\n";
reset_state( [ release( 'v1.6.0' ) ], 200, [ WPASB_Updater::OPTION => [ 'token' => 'ghp_secret' ] ] );
$r = WPASB_Updater::latest_release();
check( 'uses API asset URL when a token is set', false !== strpos( $r['package'], 'api.github.com' ), $r['package'] );
$hdr = $GLOBALS['wpasb_test']['last_args']['headers'];
check( 'Authorization header sent', 'Bearer ghp_secret' === ( $hdr['Authorization'] ?? '' ) );
check( 'User-Agent sent (GitHub rejects requests without one)', ! empty( $hdr['User-Agent'] ) );

$args = $u->authorize_download( [], 'https://api.github.com/repos/faidodaisen/admin-speed-boost/releases/assets/1' );
check( 'download request gets the token', 'Bearer ghp_secret' === ( $args['headers']['Authorization'] ?? '' ) );
check( 'download request asks for octet-stream', 'application/octet-stream' === ( $args['headers']['Accept'] ?? '' ) );

$args = $u->authorize_download( [], 'https://api.github.com/repos/someone/else/releases/assets/9' );
check( 'token NOT leaked to another repo', ! isset( $args['headers']['Authorization'] ) );

$GLOBALS['wpasb_test']['options'] = [];
$args = $u->authorize_download( [], 'https://api.github.com/repos/faidodaisen/admin-speed-boost/releases/assets/1' );
check( 'no Authorization header when no token', ! isset( $args['headers']['Authorization'] ) );

echo "\nError handling\n";
reset_state( [], 404 );
check( '404 yields null', null === WPASB_Updater::latest_release() );
check( '404 message mentions private repo', false !== strpos( WPASB_Updater::last_error(), 'private' ), WPASB_Updater::last_error() );

reset_state( [], 403 );
WPASB_Updater::latest_release();
check( '403 message mentions rate limit/token', false !== strpos( WPASB_Updater::last_error(), 'rate limit' ) );

$GLOBALS['wpasb_test']['transients'] = [];
$GLOBALS['wpasb_test']['options']    = [];
$GLOBALS['wpasb_test']['http']       = new WP_Error( 'http', 'connection timed out' );
check( 'WP_Error yields null', null === WPASB_Updater::latest_release() );
check( 'WP_Error message cached', 'connection timed out' === WPASB_Updater::last_error() );

reset_state( [ release( 'v1.6.0' ) ], 200, [ WPASB_Updater::OPTION => [ 'enabled' => false ] ] );
check( 'disabled config short-circuits', null === WPASB_Updater::latest_release() );

echo "\nCaching\n";
reset_state( [ release( 'v1.6.0' ) ] );
WPASB_Updater::latest_release();
$GLOBALS['wpasb_test']['http'] = [ 'response' => [ 'code' => 500 ], 'body' => '' ];
check( 'second call served from cache', '1.6.0' === ( WPASB_Updater::latest_release()['version'] ?? null ) );
check( 'force bypasses cache', null === WPASB_Updater::latest_release( true ) );

echo "\nView-details modal\n";
reset_state( [ release( 'v1.6.0' ) ] );
$info = $u->plugin_info( false, 'plugin_information', (object) [ 'slug' => 'wp-admin-speedboost' ] );
check( 'returns info object for our slug', is_object( $info ) && '1.6.0' === $info->version );
check( 'changelog rendered from notes', false !== strpos( $info->sections['changelog'], '<li>did a thing</li>' ) );
$info = $u->plugin_info( false, 'plugin_information', (object) [ 'slug' => 'akismet' ] );
check( 'other slugs passed through', false === $info );

reset_state( [ release( 'v1.6.0', [ 'body' => '<script>alert(1)</script>' ] ) ] );
$info = $u->plugin_info( false, 'plugin_information', (object) [ 'slug' => 'wp-admin-speedboost' ] );
check( 'HTML in release notes is escaped', false === strpos( $info->sections['changelog'], '<script>' ) );

echo "\n" . ( $failed ? "FAILED: $failed" : 'ALL PASS' ) . " ($passed passed)\n";
exit( $failed ? 1 : 0 );
