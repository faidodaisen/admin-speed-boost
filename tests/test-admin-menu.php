<?php
/**
 * Admin Menu Editor: matcher, sanitiser, row builder and admin-bar mapping.
 *
 * Pure logic, no WordPress. Run: php tests/test-admin-menu.php
 */

define( 'ABSPATH', __DIR__ . '/' );

function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function add_action() {}

require __DIR__ . '/../includes/class-admin-menu.php';
require __DIR__ . '/../includes/class-admin-menu-page.php';

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

$M = 'WPASB_Admin_Menu';

// ------------------------------------------------------------------ normalize
check( 'normalize decodes &amp;', 'admin.php?a=1&page=x' === $M::normalize( 'admin.php?page=x&amp;a=1' ) );
check( 'normalize drops customizer return', 'customize.php' === $M::normalize( 'customize.php?return=%2Fwp-admin%2Fadmin-ajax.php' ) );
check( 'normalize keeps plain', 'tools.php' === $M::normalize( ' tools.php ' ) );

// ------------------------------------------------------------------ settings
$s = $M::clean_settings( [
	'menu'    => [ 'edit.php', 'wp-admin-speedboost', 'wpasb-admin-menu', 'edit.php' ],
	'submenu' => [ 'options-general.php' => [ 'options-writing.php', 'wp-admin-speedboost' ] ],
] );
check( 'protected top slugs dropped', [ 'edit.php' ] === $s['menu'] );
check( 'protected child dropped', [ 'options-writing.php' ] === $s['submenu']['options-general.php'] );
check( 'include_admin defaults false', false === $s['include_admin'] );

// ------------------------------------------------------------------ matcher
function ctx( $pagenow, $get = [], $post_type = '', $tax = [] ) {
	return [ 'pagenow' => $pagenow, 'get' => $get, 'post_type' => $post_type, 'taxonomy_types' => $tax ];
}

$tree = [
	'edit.php'                    => [ 'edit.php', 'post-new.php', 'edit-tags.php?taxonomy=category', 'edit-tags.php?taxonomy=post_tag' ],
	'edit.php?post_type=page'     => [ 'edit.php?post_type=page', 'post-new.php?post_type=page' ],
	'edit.php?post_type=product'  => [ 'edit.php?post_type=product', 'post-new.php?post_type=product', 'edit-tags.php?post_type=product&taxonomy=product_cat' ],
	'upload.php'                  => [ 'upload.php', 'media-new.php' ],
	'edit-comments.php'           => [],
	'themes.php'                  => [ 'themes.php', 'customize.php', 'nav-menus.php', 'custom-header' ],
	'tools.php'                   => [ 'tools.php', 'import.php', 'export.php' ],
	'options-general.php'         => [ 'options-general.php', 'options-writing.php', 'options-reading.php' ],
	'wpcf7'                       => [ 'wpcf7', 'wpcf7-new' ],
	'woocommerce'                 => [ 'wc-admin', 'wc-admin&path=/customers', 'wc-settings' ],
	'wp-admin-speedboost'         => [ 'wp-admin-speedboost', 'wpasb-admin-menu' ],
];

function blocks( $ctx, $menu = [], $sub = [], $include_admin = false ) {
	global $tree;
	$s = WPASB_Admin_Menu::clean_settings( [ 'menu' => $menu, 'submenu' => $sub, 'include_admin' => $include_admin ] );
	return WPASB_Admin_Menu::blocking_slug( $ctx, $s, $tree );
}

// Posts hidden
$posts = [ 'edit.php' ];
check( 'posts: edit.php blocked', 'edit.php' === blocks( ctx( 'edit.php', [], 'post' ), $posts ) );
check( 'posts: post-new blocked', 'edit.php' === blocks( ctx( 'post-new.php', [], 'post' ), $posts ) );
check( 'posts: post.php of a post blocked', 'edit.php' === blocks( ctx( 'post.php', [ 'post' => 5, 'action' => 'edit' ], 'post' ), $posts ) );
check( 'posts: category screen blocked', 'edit.php' === blocks( ctx( 'edit-tags.php', [ 'taxonomy' => 'category' ], '', [ 'post' ] ), $posts ) );
check( 'posts: term.php of post_tag blocked', 'edit.php' === blocks( ctx( 'term.php', [ 'taxonomy' => 'post_tag', 'tag_ID' => 3 ], '', [ 'post' ] ), $posts ) );
check( 'posts: pages list NOT blocked', '' === blocks( ctx( 'edit.php', [ 'post_type' => 'page' ], 'page' ), $posts ) );
check( 'posts: page editor NOT blocked', '' === blocks( ctx( 'post.php', [ 'post' => 9 ], 'page' ), $posts ) );
check( 'posts: shared taxonomy NOT blocked', '' === blocks( ctx( 'edit-tags.php', [ 'taxonomy' => 'shared' ], '', [ 'post', 'page' ] ), $posts ) );
check( 'posts: dashboard never blocked', '' === blocks( ctx( 'index.php' ), $posts ) );

// Pages hidden
$pages = [ 'edit.php?post_type=page' ];
check( 'pages: list blocked', 'edit.php?post_type=page' === blocks( ctx( 'edit.php', [ 'post_type' => 'page' ], 'page' ), $pages ) );
check( 'pages: posts list NOT blocked', '' === blocks( ctx( 'edit.php', [], 'post' ), $pages ) );
check( 'pages: post.php of page blocked', 'edit.php?post_type=page' === blocks( ctx( 'post.php', [ 'post' => 9 ], 'page' ), $pages ) );

// CPT product
$prod = [ 'edit.php?post_type=product' ];
check( 'product: cat screen blocked', 'edit.php?post_type=product' === blocks( ctx( 'edit-tags.php', [ 'taxonomy' => 'product_cat', 'post_type' => 'product' ], 'product', [ 'product' ] ), $prod ) );

// Media
check( 'media: upload.php blocked', 'upload.php' === blocks( ctx( 'upload.php', [], 'attachment' ), [ 'upload.php' ] ) );
check( 'media: attachment edit blocked', 'upload.php' === blocks( ctx( 'post.php', [ 'post' => 7 ], 'attachment' ), [ 'upload.php' ] ) );
check( 'media: async-upload never blocked', '' === blocks( ctx( 'async-upload.php' ), [ 'upload.php' ] ) );

// Comments related screen
check( 'comments: comment.php blocked', 'edit-comments.php' === blocks( ctx( 'comment.php', [ 'action' => 'editcomment' ] ), [ 'edit-comments.php' ] ) );

// Appearance: plugin page under themes.php via cascade, and core child
check( 'themes: custom-header page blocked via cascade', 'themes.php' === blocks( ctx( 'themes.php', [ 'page' => 'custom-header' ] ), [ 'themes.php' ] ) );
check( 'themes: customize.php blocked', 'themes.php' === blocks( ctx( 'customize.php', [ 'return' => '/wp-admin/' ] ), [ 'themes.php' ] ) );

// Submenu only
$sub = [ 'options-general.php' => [ 'options-writing.php' ] ];
check( 'sub: writing blocked', 'options-writing.php' === blocks( ctx( 'options-writing.php' ), [], $sub ) );
check( 'sub: general NOT blocked', '' === blocks( ctx( 'options-general.php' ), [], $sub ) );
check( 'sub: reading NOT blocked', '' === blocks( ctx( 'options-reading.php' ), [], $sub ) );
check( 'sub: options.php (save) never blocked', '' === blocks( ctx( 'options.php' ), [], $sub ) );

// Hiding the Tools file must not swallow plugin pages that live on tools.php
check( 'tools: tools.php?page=x NOT blocked by submenu tools.php', '' === blocks( ctx( 'tools.php', [ 'page' => 'some-plugin' ] ), [], [ 'tools.php' => [ 'tools.php' ] ] ) );

// Plugin pages
check( 'plugin: wpcf7 blocked on admin.php', 'wpcf7' === blocks( ctx( 'admin.php', [ 'page' => 'wpcf7' ] ), [ 'wpcf7' ] ) );
check( 'plugin: wpcf7-new blocked via cascade', 'wpcf7' === blocks( ctx( 'admin.php', [ 'page' => 'wpcf7-new' ] ), [ 'wpcf7' ] ) );
check( 'plugin: other page NOT blocked', '' === blocks( ctx( 'admin.php', [ 'page' => 'other' ] ), [ 'wpcf7' ] ) );
check( 'plugin: woo child with args blocked', 'wc-admin&path=/customers' === blocks( ctx( 'admin.php', [ 'page' => 'wc-admin', 'path' => '/customers' ] ), [], [ 'woocommerce' => [ 'wc-admin&path=/customers' ] ] ) );
check( 'plugin: woo home NOT blocked by customers child', '' === blocks( ctx( 'admin.php', [ 'page' => 'wc-admin' ] ), [], [ 'woocommerce' => [ 'wc-admin&path=/customers' ] ] ) );

// Guards
check( 'guard: speedboost page never blocked even if forced', '' === WPASB_Admin_Menu::blocking_slug(
	ctx( 'admin.php', [ 'page' => 'wp-admin-speedboost' ] ),
	[ 'menu' => [ 'wp-admin-speedboost' ], 'submenu' => [], 'include_admin' => true ],
	$tree
) );
check( 'guard: menu editor page never blocked', '' === WPASB_Admin_Menu::blocking_slug(
	ctx( 'admin.php', [ 'page' => 'wpasb-admin-menu' ] ),
	[ 'menu' => [ 'wp-admin-speedboost' ], 'submenu' => [], 'include_admin' => true ],
	$tree
) );
check( 'guard: index.php?page=<hidden plugin> IS blocked', 'wpcf7' === blocks( ctx( 'index.php', [ 'page' => 'wpcf7' ] ), [ 'wpcf7' ] ) );
check( 'guard: profile.php?page=<hidden plugin> IS blocked', 'wpcf7' === blocks( ctx( 'profile.php', [ 'page' => 'wpcf7-new' ] ), [ 'wpcf7' ] ) );
check( 'guard: index.php?page=speedboost still exempt', '' === blocks( ctx( 'index.php', [ 'page' => 'wp-admin-speedboost' ] ), [ 'wpcf7' ] ) );
check( 'pages: revision of a page blocked', 'edit.php?post_type=page' === blocks( ctx( 'revision.php', [ 'revision' => 7 ], 'page' ), [ 'edit.php?post_type=page' ] ) );
check( 'pages: revision of a post NOT blocked', '' === blocks( ctx( 'revision.php', [ 'revision' => 8 ], 'post' ), [ 'edit.php?post_type=page' ] ) );
check( 'guard: profile never blocked', '' === blocks( ctx( 'profile.php' ), [ 'users.php' ] ) );
check( 'guard: users.php blocked', 'users.php' === blocks( ctx( 'user-edit.php', [ 'user_id' => 2 ] ), [ 'users.php' ] ) );

// ------------------------------------------------------------------ admin bar
$nodes = WPASB_Admin_Menu::admin_bar_nodes(
	$M::clean_settings( [ 'menu' => [ 'edit.php', 'edit-comments.php', 'upload.php', 'edit.php?post_type=product' ], 'submenu' => [ 'users.php' => [ 'user-new.php' ] ] ] ),
	$tree
);
sort( $nodes );
check( 'admin bar nodes', [ 'comments', 'new-media', 'new-post', 'new-product', 'new-user' ] === $nodes );
check( 'admin bar: post-new submenu alone', [ 'new-page' ] === WPASB_Admin_Menu::admin_bar_nodes( $M::clean_settings( [ 'submenu' => [ 'edit.php?post_type=page' => [ 'post-new.php?post_type=page' ] ] ] ), $tree ) );

// ------------------------------------------------------------------ labels
check( 'label strips bubble', 'Plugins' === WPASB_Admin_Menu::clean_label( 'Plugins <span class="update-plugins count-3"><span class="plugin-count">3</span></span>' ) );
check( 'label strips awaiting-mod', 'Comments' === WPASB_Admin_Menu::clean_label( 'Comments <span class="awaiting-mod count-0"><span class="pending-count" aria-hidden="true">0</span><span class="comments-in-moderation-text screen-reader-text">0 Comments in moderation</span></span>' ) );
check( 'label fallback', 'wpcf7' === WPASB_Admin_Menu::clean_label( '', 'wpcf7' ) );

// ------------------------------------------------------------------ sanitize
$P       = 'WPASB_Admin_Menu_Page';
$snap    = [ 'menu' => [ 'edit.php', 'tools.php', 'wp-admin-speedboost' ], 'submenu' => [ 'options-general.php' => [ 'options-writing.php', 'options-reading.php' ] ] ];
$current = $M::clean_settings( [ 'menu' => [ 'old-plugin' ] ] );

$out = $P::sanitize_input(
	[
		'rows'          => [ 'm:edit.php', 'm:tools.php', 'm:old-plugin', 'm:evil.php', 'm:wp-admin-speedboost', 's:options-general.php>options-writing.php', 's:options-general.php>options-reading.php', 's:options-general.php>nope.php' ],
		'shown'         => [ 'm:tools.php', 's:options-general.php>options-reading.php' ],
		'include_admin' => '1',
	],
	$current,
	$snap
);
check( 'sanitize: hidden = rows - shown, whitelisted', [ 'edit.php', 'old-plugin' ] === $out['menu'] );
check( 'sanitize: submenu whitelisted', [ 'options-general.php' => [ 'options-writing.php' ] ] === $out['submenu'] );
check( 'sanitize: include_admin', true === $out['include_admin'] );
check( 'sanitize: unknown slug rejected', ! in_array( 'evil.php', $out['menu'], true ) );
check( 'sanitize: protected slug rejected', ! in_array( 'wp-admin-speedboost', $out['menu'], true ) );

$out = $P::sanitize_input( [ 'rows' => [ 'm:edit.php' ], 'shown' => [ 'm:edit.php' ] ], $current, $snap );
check( 'sanitize: all shown -> empty', [] === $out['menu'] && [] === $out['submenu'] && false === $out['include_admin'] );
$out = $P::sanitize_input( [ 'rows' => [ 'm:edit.php', [ 'x' => 1 ], 'm:index.php', 'm:profile.php' ], 'shown' => 'str' ], $current, [ 'menu' => [ 'edit.php', 'index.php', 'profile.php' ] ] );
check( 'sanitize: non-string rows skipped, dashboard/profile locked, shown non-array ok', [ 'edit.php' ] === $out['menu'] );

$out = $P::sanitize_input( 'garbage', $current, 'garbage' );
check( 'sanitize: garbage input safe', [] === $out['menu'] );

// ------------------------------------------------------------------ rows
$captured = [
	'menu'    => [
		2  => [ 'Dashboard', 'read', 'index.php', '', 'menu-top', 'menu-dashboard', 'dashicons-dashboard' ],
		4  => [ '', 'read', 'separator1', '', 'wp-menu-separator' ],
		5  => [ 'Posts', 'edit_posts', 'edit.php', '', 'menu-top', 'menu-posts', 'dashicons-admin-post' ],
		25 => [ 'Comments <span class="awaiting-mod count-2"><span class="pending-count">2</span></span>', 'edit_posts', 'edit-comments.php', '', '', '', 'dashicons-admin-comments' ],
		81 => [ 'Speedboost', 'manage_options', 'wp-admin-speedboost', '', '', '', 'dashicons-performance' ],
	],
	'submenu' => [
		'edit.php'            => [ 5 => [ 'All Posts', 'edit_posts', 'edit.php' ], 10 => [ 'Add New Post', 'edit_posts', 'post-new.php' ] ],
		'wp-admin-speedboost' => [ 0 => [ 'Modules', 'manage_options', 'wp-admin-speedboost' ], 1 => [ 'Admin Menu', 'manage_options', 'wpasb-admin-menu' ] ],
	],
];
$settings = $M::clean_settings( [ 'menu' => [ 'edit.php', 'gone-plugin' ], 'submenu' => [ 'edit.php' => [ 'post-new.php' ], 'vanished' => [ 'x' ] ] ] );
$rows     = $P::build_rows( $captured, $settings );
$slugs    = array_column( $rows, 'slug' );
check( 'rows: order + separator skipped + missing appended', [ 'index.php', 'edit.php', 'edit-comments.php', 'wp-admin-speedboost', 'gone-plugin', 'vanished' ] === $slugs );
check( 'rows: posts hidden', true === $rows[1]['hidden'] );
check( 'rows: child hidden flag', true === $rows[1]['children'][1]['hidden'] && false === $rows[1]['children'][0]['hidden'] );
check( 'rows: comments label clean', 'Comments' === $rows[2]['label'] );
check( 'rows: speedboost protected', true === $rows[3]['protected'] && true === $rows[3]['children'][1]['protected'] );
check( 'rows: gone plugin missing', true === $rows[4]['missing'] && true === $rows[4]['hidden'] );
check( 'rows: orphan submenu kept', ! empty( $rows[5]['orphan'] ) && 'x' === $rows[5]['children'][0]['slug'] );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
