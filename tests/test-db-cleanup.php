<?php
/**
 * Database cleanup against a REAL WordPress database (scan -> approve -> run).
 *
 * Needs a WordPress install with this plugin active, so it is run through
 * WP-CLI, not plain php. Use a throwaway site: it seeds rows, then runs the
 * destructive steps on them.
 *
 *   wp --path=C:/path/to/scratch-wp eval-file tests/test-db-cleanup.php
 *
 * Drives the same admin-ajax handlers the settings page calls, with the same
 * nonce, so the capability check, the lock and the token are all exercised.
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Run through WP-CLI: wp eval-file tests/test-db-cleanup.php\n" );
	exit( 1 );
}

// eval-file runs inside a function, so the counters must be declared global to
// be the same variables asb_check() updates.
global $wpdb, $fail, $pass;

$fail = 0;
$pass = 0;

function asb_check( $label, $cond ) {
	global $fail, $pass;
	if ( $cond ) {
		$pass++;
	} else {
		$fail++;
		echo "FAIL: $label\n";
	}
}

/**
 * Calls an ajax handler the way admin-ajax.php does and returns the decoded
 * JSON envelope. wp_send_json_* ends in wp_die(); the filter turns that into
 * an exception so the script can read the output and carry on.
 */
function asb_ajax( $op, array $post = [], $nonce = true ) {
	$_POST    = $post;
	$_REQUEST = $post;
	if ( $nonce ) {
		$_POST['nonce'] = $_REQUEST['nonce'] = wp_create_nonce( 'wpasb_cleanup' );
	}

	$die = static function () {
		return static function () {
			throw new RuntimeException( 'wp_die' );
		};
	};
	add_filter( 'wp_doing_ajax', '__return_true' );
	add_filter( 'wp_die_ajax_handler', $die );

	ob_start();
	try {
		( new WPASB_DB_Cleanup() )->{'ajax_' . $op}();
	} catch ( RuntimeException $e ) {
		// expected: wp_send_json_* ended the request.
	}
	$out = ob_get_clean();

	remove_filter( 'wp_doing_ajax', '__return_true' );
	remove_filter( 'wp_die_ajax_handler', $die );

	$json = json_decode( $out, true );
	return is_array( $json ) ? $json : [ 'success' => false, 'data' => [ 'raw' => $out ] ];
}

function asb_cat( array $scan, $key ) {
	foreach ( $scan['categories'] as $c ) {
		if ( $c['key'] === $key ) {
			return $c;
		}
	}
	return null;
}

function asb_scan() {
	$res = asb_ajax( 'scan' );
	return ! empty( $res['success'] ) ? $res['data'] : [ 'categories' => [] ];
}

wp_set_current_user( 1 );
add_filter( 'wpasb_cleanup_batch_size', static function () {
	return 25; // the minimum, so the 90 seeded revisions need several batches.
} );
delete_transient( WPASB_DB_Cleanup::LOCK_TRANSIENT );

// ------------------------------------------------------------------ baseline
$before = asb_scan();
asb_check( 'scan returns 5 categories', 5 === count( $before['categories'] ) );
$base = [];
foreach ( $before['categories'] as $c ) {
	$base[ $c['key'] ] = $c['count'];
}

// ------------------------------------------------------------------ seed
$posts = [];
for ( $i = 0; $i < 3; $i++ ) {
	$posts[] = wp_insert_post( [ 'post_title' => 'Seed <b>post</b> ' . $i, 'post_status' => 'publish', 'post_content' => str_repeat( 'x', 500 ) ] );
}
$wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent IN (" . implode( ',', $posts ) . ')' ); // drop auto-made ones
$seed_revisions = 0;
foreach ( $posts as $pid ) {
	for ( $r = 1; $r <= 30; $r++ ) {
		wp_insert_post(
			[
				'post_type'    => 'revision',
				'post_status'  => 'inherit',
				'post_parent'  => $pid,
				'post_name'    => $pid . '-revision-v' . $r,
				'post_title'   => 'rev ' . $r,
				'post_content' => str_repeat( 'y', 200 ),
			]
		);
		$seed_revisions++;
	}
}

$past = time() - 3600;
update_option( '_transient_asb_old1', 'v', false );
update_option( '_transient_timeout_asb_old1', $past, false );
update_option( '_site_transient_asb_old2', 'v', false );
update_option( '_site_transient_timeout_asb_old2', $past, false );
update_option( '_transient_timeout_asb_old3', $past, false ); // timeout whose value is already gone
set_transient( 'asb_live', 'keep me', HOUR_IN_SECONDS );
$seed_transients = 3;

$wpdb->insert( $wpdb->postmeta, [ 'post_id' => 9999991, 'meta_key' => 'asb_orphan_a', 'meta_value' => 'a' ] );
$wpdb->insert( $wpdb->postmeta, [ 'post_id' => 9999992, 'meta_key' => 'asb_orphan_b', 'meta_value' => 'b' ] );
$wpdb->insert( $wpdb->commentmeta, [ 'comment_id' => 9999991, 'meta_key' => 'asb_orphan_c', 'meta_value' => 'c' ] );
$live_meta_post = $posts[0];
update_post_meta( $live_meta_post, 'asb_live_meta', 'keep me' );

// ------------------------------------------------------------------ scan sees exactly the seed
$scan = asb_scan();
asb_check( 'revisions counted', asb_cat( $scan, 'revisions' )['count'] === $base['revisions'] + $seed_revisions );
asb_check( 'expired transients counted, live one ignored', asb_cat( $scan, 'transients' )['count'] === $base['transients'] + $seed_transients );
asb_check( 'orphan postmeta counted, live meta ignored', asb_cat( $scan, 'orphan_postmeta' )['count'] === $base['orphan_postmeta'] + 2 );
asb_check( 'orphan commentmeta counted', asb_cat( $scan, 'orphan_commentmeta' )['count'] === $base['orphan_commentmeta'] + 1 );

$rev = asb_cat( $scan, 'revisions' );
asb_check( 'preview is capped at PREVIEW_ROWS', count( $rev['rows'] ) === WPASB_DB_Cleanup::PREVIEW_ROWS && $rev['has_more'] );
asb_check( 'revision row names the parent post (tags stripped)', false !== strpos( $rev['rows'][0]['title'], 'Seed post' ) && false === strpos( $rev['rows'][0]['title'], '<b>' ) );
asb_check( 'revision cutoff is the highest revision ID', $rev['cutoff'] === (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type='revision'" ) );
asb_check( 'revision size reported', $rev['bytes'] > 0 && '' !== $rev['size'] );
asb_check( 'tables listed in full', count( asb_cat( $scan, 'tables' )['rows'] ) === 11 );

$page2 = asb_ajax( 'list', [ 'category' => 'revisions', 'cutoff' => $rev['cutoff'], 'offset' => WPASB_DB_Cleanup::PREVIEW_ROWS ] );
asb_check( 'list endpoint pages the records', ! empty( $page2['success'] ) && count( $page2['data']['rows'] ) > 0 );

// ------------------------------------------------------------------ guards
asb_check( 'scan without nonce is refused', empty( asb_ajax( 'scan', [], false )['success'] ) );
$sub = wp_insert_user( [ 'user_login' => 'asb_sub_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ] );
wp_set_current_user( $sub );
asb_check( 'scan as a subscriber is refused', empty( asb_ajax( 'scan' )['success'] ) );
asb_check( 'start as a subscriber is refused', empty( asb_ajax( 'start' )['success'] ) );
wp_set_current_user( 1 );
wp_delete_user( $sub );

asb_check( 'step without a session token is refused', empty( asb_ajax( 'step', [ 'category' => 'revisions', 'cutoff' => $rev['cutoff'] ] )['success'] ) );
asb_check( 'nothing was deleted by the refused step', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='revision'" ) === $base['revisions'] + $seed_revisions );

// ------------------------------------------------------------------ run
$start = asb_ajax( 'start' );
asb_check( 'start opens a session', ! empty( $start['success'] ) && ! empty( $start['data']['token'] ) );
$token = $start['data']['token'];
asb_check( 'a second start is refused while locked (409)', empty( asb_ajax( 'start' )['success'] ) );
asb_check( 'a wrong token is refused', empty( asb_ajax( 'step', [ 'category' => 'revisions', 'cutoff' => $rev['cutoff'], 'token' => 'nope' ] )['success'] ) );
asb_check( 'an unknown category is refused', empty( asb_ajax( 'step', [ 'category' => 'users', 'cutoff' => 1, 'token' => $token ] )['success'] ) );

// A revision saved AFTER the scan was not approved, so it must survive.
$late = wp_insert_post( [ 'post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $posts[1], 'post_name' => $posts[1] . '-revision-v999', 'post_title' => 'late' ] );

$batches = 0;
$deleted = 0;
do {
	$res = asb_ajax( 'step', [ 'category' => 'revisions', 'cutoff' => $rev['cutoff'], 'token' => $token ] );
	asb_check( 'revision step succeeds', ! empty( $res['success'] ) );
	$deleted += (int) $res['data']['processed'];
	$batches++;
} while ( ! empty( $res['success'] ) && $res['data']['remaining'] > 0 && ! $res['data']['stalled'] && $batches < 100 );
asb_check( 'revisions finished in several real batches', $batches >= 4 );
asb_check( 'every approved revision was deleted', $deleted === $base['revisions'] + $seed_revisions );
asb_check( 'the revision saved after the scan survived', (bool) get_post( $late ) );
wp_delete_post( $late, true );

foreach ( [ 'transients', 'orphan_postmeta', 'orphan_commentmeta' ] as $key ) {
	$cat = asb_cat( $scan, $key );
	do {
		$res = asb_ajax( 'step', [ 'category' => $key, 'cutoff' => $cat['cutoff'], 'token' => $token ] );
		asb_check( "$key step succeeds", ! empty( $res['success'] ) );
	} while ( ! empty( $res['success'] ) && $res['data']['remaining'] > 0 && ! $res['data']['stalled'] );
	asb_check( "$key reports nothing remaining", 0 === (int) $res['data']['remaining'] );
}

$tables = asb_cat( $scan, 'tables' );
for ( $i = 0; $i < $tables['count']; $i++ ) {
	$res = asb_ajax( 'step', [ 'category' => 'tables', 'index' => $i, 'token' => $token ] );
	asb_check( 'table step succeeds', ! empty( $res['success'] ) && 1 === (int) $res['data']['processed'] );
}

$finish = asb_ajax( 'finish', [ 'token' => $token ] );
asb_check( 'finish succeeds', ! empty( $finish['success'] ) );
asb_check( 'finish releases the lock', false === get_transient( WPASB_DB_Cleanup::LOCK_TRANSIENT ) );

// ------------------------------------------------------------------ what survived
asb_check( 'published posts untouched', 3 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID IN (" . implode( ',', $posts ) . ") AND post_status='publish'" ) );
asb_check( 'live post meta untouched', 'keep me' === get_post_meta( $live_meta_post, 'asb_live_meta', true ) );
asb_check( 'live transient untouched', 'keep me' === get_transient( 'asb_live' ) );
asb_check( 'expired transient value gone', false === get_option( '_transient_asb_old1' ) );
asb_check( 'expired site transient gone', false === get_option( '_site_transient_asb_old2' ) );
asb_check( 'timeout row without a value is gone too', false === get_option( '_transient_timeout_asb_old3' ) );

$after = asb_scan();
foreach ( [ 'revisions', 'transients', 'orphan_postmeta', 'orphan_commentmeta' ] as $key ) {
	asb_check( "re-scan finds no $key left", 0 === asb_cat( $after, $key )['count'] );
}
asb_check( 'a new session can start after finish', ! empty( asb_ajax( 'start' )['success'] ) );
delete_transient( WPASB_DB_Cleanup::LOCK_TRANSIENT );

// ------------------------------------------------------------------ tidy up
foreach ( $posts as $pid ) {
	wp_delete_post( $pid, true );
}
delete_transient( 'asb_live' );

echo "\n{$pass} passed, {$fail} failed.\n";
if ( $fail ) {
	exit( 1 );
}
echo "PASS: database cleanup scan / approve / run verified against a real database.\n";
