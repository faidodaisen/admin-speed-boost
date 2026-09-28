<?php
/**
 * Admin Menu Editor engine.
 *
 * Hides wp-admin sidebar items and blocks the screens behind them. Hiding a
 * menu entry on its own never removes the page (the URL still opens it), so
 * this does two things: removes the entry, and refuses the request with a 403
 * when a hidden screen is opened directly.
 *
 * This is UI plus page access, not a capability boundary: a user who still
 * holds the capability can reach the same data over REST or admin-ajax.
 *
 * Lives in includes/ because module files can be included more than once.
 *
 * @package WP_Admin_Speedboost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPASB_Admin_Menu {

	const OPT          = 'wpasb_admin_menu';
	const OPT_SNAPSHOT = 'wpasb_admin_menu_snapshot';
	const PAGE_SLUG    = 'wpasb-admin-menu';
	const KILL_SWITCH  = 'WPASB_DISABLE_MENU_EDITOR';

	/**
	 * $menu / $submenu as they were before this module removed anything.
	 *
	 * @var array{menu:array, submenu:array}|null
	 */
	private static $captured = null;

	private static $booted = false;

	/**
	 * Screens that can never be blocked. Plugin pages are matched on ?page=,
	 * core files on $pagenow.
	 */
	public static function protected_pages() {
		return [ 'wp-admin-speedboost', self::PAGE_SLUG ];
	}

	public static function protected_files() {
		return [ 'index.php', 'profile.php', 'options.php', 'admin-ajax.php', 'admin-post.php', 'async-upload.php' ];
	}

	/**
	 * Menu slugs whose toggle is locked on. The plugin's own menu must stay
	 * reachable, or nobody can reach the screen that un-hides things.
	 */
	public static function is_protected_slug( $slug ) {
		$slug = self::normalize( $slug );

		// Dashboard and Profile are never blocked (the 403 screen links back
		// to the Dashboard), so their menu entries are never hidden either.
		return in_array( $slug, self::protected_pages(), true ) || in_array( $slug, [ 'index.php', 'profile.php' ], true );
	}

	public static function disabled_by_constant() {
		return defined( self::KILL_SWITCH ) && constant( self::KILL_SWITCH );
	}

	public static function init() {
		if ( self::$booted || self::disabled_by_constant() ) {
			return;
		}
		self::$booted = true;

		add_action( 'admin_menu', [ __CLASS__, 'capture' ], 9998 );
		add_action( 'admin_menu', [ __CLASS__, 'apply' ], 9999 );
		// After the menu is final but before admin_init or any screen output,
		// and before core's own access check can answer with its generic
		// "not allowed" page.
		add_action( 'admin_menu', [ __CLASS__, 'guard' ], 10000 );
		add_action( 'admin_bar_menu', [ __CLASS__, 'prune_admin_bar' ], 999 );
	}

	// ------------------------------------------------------------------
	// Settings
	// ------------------------------------------------------------------

	/**
	 * @return array{menu:string[], submenu:array<string,string[]>, include_admin:bool}
	 */
	public static function get_settings() {
		$raw = get_option( self::OPT, [] );

		return self::clean_settings( is_array( $raw ) ? $raw : [] );
	}

	/**
	 * Shape + type normalisation for a stored (already sanitised) value.
	 */
	public static function clean_settings( array $raw ) {
		$menu = [];
		if ( ! empty( $raw['menu'] ) && is_array( $raw['menu'] ) ) {
			foreach ( $raw['menu'] as $slug ) {
				$slug = self::normalize( $slug );
				if ( '' !== $slug && ! self::is_protected_slug( $slug ) ) {
					$menu[ $slug ] = true;
				}
			}
		}

		$submenu = [];
		if ( ! empty( $raw['submenu'] ) && is_array( $raw['submenu'] ) ) {
			foreach ( $raw['submenu'] as $parent => $children ) {
				$parent = self::normalize( $parent );
				if ( '' === $parent || ! is_array( $children ) ) {
					continue;
				}
				foreach ( $children as $child ) {
					$child = self::normalize( $child );
					if ( '' !== $child && ! self::is_protected_slug( $child ) ) {
						$submenu[ $parent ][ $child ] = true;
					}
				}
			}
		}

		ksort( $submenu );

		return [
			'menu'          => array_keys( $menu ),
			'submenu'       => array_map( 'array_keys', $submenu ),
			'include_admin' => ! empty( $raw['include_admin'] ),
		];
	}

	/**
	 * Whether the hide/block rules apply to the current user.
	 */
	public static function applies_to_current_user( $settings = null ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		if ( null === $settings ) {
			$settings = self::get_settings();
		}
		if ( current_user_can( 'manage_options' ) && empty( $settings['include_admin'] ) ) {
			return false;
		}
		return true;
	}

	public static function has_rules( array $settings ) {
		return ! empty( $settings['menu'] ) || ! empty( $settings['submenu'] );
	}

	// ------------------------------------------------------------------
	// Slug helpers (pure)
	// ------------------------------------------------------------------

	/**
	 * Canonical form of a menu slug: entities decoded, query args sorted, and
	 * the Customizer's per-request `return` arg dropped, so the same entry
	 * compares equal across requests.
	 */
	public static function normalize( $slug ) {
		$slug = trim( html_entity_decode( (string) $slug, ENT_QUOTES, 'UTF-8' ) );
		$slug = preg_replace( '/[^A-Za-z0-9_\-\.\?=&%\/\[\]:+,~]/', '', $slug );

		if ( '' === $slug || strlen( $slug ) > 200 ) {
			return '';
		}

		if ( false === strpos( $slug, '?' ) ) {
			return $slug;
		}

		list( $file, $query ) = explode( '?', $slug, 2 );
		$args                 = [];
		parse_str( $query, $args );
		unset( $args['return'] );
		ksort( $args );

		return $args ? $file . '?' . http_build_query( $args ) : $file;
	}

	/**
	 * How a menu slug maps to a URL: a core/admin file (tools.php,
	 * edit.php?post_type=x) or a plugin page opened through ?page=slug.
	 *
	 * @return array{kind:string, file:string, page:string, args:array}
	 */
	public static function describe( $slug ) {
		$slug = self::normalize( $slug );
		$file = $slug;
		$args = [];

		if ( false !== strpos( $slug, '?' ) ) {
			list( $file, $query ) = explode( '?', $slug, 2 );
			parse_str( $query, $args );
		}

		// A slug without .php, or a path into a plugin folder, is a plugin page.
		if ( false === strpos( $file, '.php' ) || false !== strpos( $file, '/' ) ) {
			// WooCommerce-style "wc-admin&path=/customers": the page plus args.
			if ( false === strpos( $slug, '?' ) && false !== strpos( $slug, '&' ) ) {
				list( $page, $query ) = explode( '&', $slug, 2 );
				parse_str( $query, $args );
				return [ 'kind' => 'page', 'file' => '', 'page' => $page, 'args' => $args ];
			}
			return [ 'kind' => 'page', 'file' => '', 'page' => $slug, 'args' => [] ];
		}

		return [ 'kind' => 'file', 'file' => $file, 'page' => '', 'args' => $args ];
	}

	/**
	 * Does the request described by $ctx open the screen for $slug?
	 *
	 * @param string $slug Normalised menu slug.
	 * @param array  $ctx  { pagenow, get, post_type }.
	 */
	public static function request_matches( $slug, array $ctx ) {
		$d   = self::describe( $slug );
		$get = isset( $ctx['get'] ) && is_array( $ctx['get'] ) ? $ctx['get'] : [];

		if ( 'page' === $d['kind'] ) {
			if ( ! isset( $get['page'] ) || ! is_string( $get['page'] ) || self::normalize( $get['page'] ) !== $d['page'] ) {
				return false;
			}
			foreach ( $d['args'] as $key => $value ) {
				if ( ! isset( $get[ $key ] ) || ! is_scalar( $get[ $key ] ) || (string) $get[ $key ] !== (string) $value ) {
					return false;
				}
			}
			return true;
		}

		if ( $ctx['pagenow'] !== $d['file'] ) {
			return false;
		}

		// themes.php must not swallow themes.php?page=custom-header.
		if ( isset( $get['page'] ) && ! isset( $d['args']['page'] ) ) {
			return false;
		}

		$typed_files = [ 'edit.php', 'post-new.php' ];

		if ( in_array( $d['file'], $typed_files, true ) && ! isset( $d['args']['post_type'] ) ) {
			// Bare edit.php / post-new.php means the "post" type only.
			if ( 'post' !== $ctx['post_type'] ) {
				return false;
			}
		}

		foreach ( $d['args'] as $key => $value ) {
			if ( 'post_type' === $key ) {
				if ( (string) $ctx['post_type'] !== (string) $value ) {
					return false;
				}
				continue;
			}
			if ( ! isset( $get[ $key ] ) || ! is_scalar( $get[ $key ] ) || (string) $get[ $key ] !== (string) $value ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Post types a top-level entry owns, read from its own slug and its
	 * children's slugs.
	 */
	public static function owned_post_types( $parent, array $children ) {
		$types = [];

		foreach ( array_merge( [ $parent ], $children ) as $slug ) {
			$d = self::describe( $slug );
			if ( 'file' !== $d['kind'] ) {
				continue;
			}
			if ( in_array( $d['file'], [ 'edit.php', 'post-new.php' ], true ) ) {
				$types[] = isset( $d['args']['post_type'] ) ? (string) $d['args']['post_type'] : 'post';
			} elseif ( in_array( $d['file'], [ 'upload.php', 'media-new.php' ], true ) ) {
				$types[] = 'attachment';
			}
		}

		return array_values( array_unique( $types ) );
	}

	/**
	 * Core screens that belong to a top-level entry without being menu items.
	 */
	public static function related_files( $parent ) {
		$map = [
			'edit-comments.php' => [ 'comment.php' ],
			'users.php'         => [ 'user-edit.php', 'user-new.php' ],
			'themes.php'        => [ 'theme-install.php', 'theme-editor.php', 'customize.php', 'nav-menus.php', 'widgets.php', 'site-editor.php' ],
			'plugins.php'       => [ 'plugin-install.php', 'plugin-editor.php' ],
			'tools.php'         => [ 'import.php', 'export.php' ],
			'upload.php'        => [ 'media-new.php' ],
		];

		return isset( $map[ $parent ] ) ? $map[ $parent ] : [];
	}

	/**
	 * The hidden slug that blocks this request, or '' if the request is allowed.
	 *
	 * @param array $ctx      { pagenow, get, post_type, taxonomy_types[] }.
	 * @param array $settings Clean settings.
	 * @param array $tree     parent slug => child slugs, normalised, captured
	 *                        before anything was hidden.
	 */
	public static function blocking_slug( array $ctx, array $settings, array $tree ) {
		$get = isset( $ctx['get'] ) && is_array( $ctx['get'] ) ? $ctx['get'] : [];

		// A core file with ?page= renders that plugin page instead (core falls
		// back to the page's own hook), so index.php?page=x is only exempt
		// when x itself is exempt.
		if ( empty( $get['page'] ) && in_array( $ctx['pagenow'], self::protected_files(), true ) ) {
			return '';
		}
		if ( isset( $get['page'] ) && is_string( $get['page'] ) && in_array( self::normalize( $get['page'] ), self::protected_pages(), true ) ) {
			return '';
		}

		// Hidden top-level entries cascade to everything they own.
		foreach ( $settings['menu'] as $parent ) {
			$children = isset( $tree[ $parent ] ) ? $tree[ $parent ] : [];

			foreach ( array_merge( [ $parent ], $children ) as $slug ) {
				if ( self::request_matches( $slug, $ctx ) ) {
					return $parent;
				}
			}

			if ( in_array( $ctx['pagenow'], self::related_files( $parent ), true ) && empty( $get['page'] ) ) {
				return $parent;
			}

			$types = self::owned_post_types( $parent, $children );
			if ( $types ) {
				if ( in_array( $ctx['pagenow'], [ 'post.php', 'post-new.php', 'edit.php', 'revision.php' ], true )
					&& empty( $get['page'] )
					&& in_array( (string) $ctx['post_type'], $types, true ) ) {
					return $parent;
				}

				$tax_types = isset( $ctx['taxonomy_types'] ) ? (array) $ctx['taxonomy_types'] : [];
				if ( in_array( $ctx['pagenow'], [ 'edit-tags.php', 'term.php' ], true )
					&& $tax_types
					&& ! array_diff( $tax_types, $types ) ) {
					return $parent;
				}
			}
		}

		foreach ( $settings['submenu'] as $parent => $children ) {
			foreach ( $children as $slug ) {
				if ( self::request_matches( $slug, $ctx ) ) {
					return $slug;
				}
			}
		}

		return '';
	}

	/**
	 * Admin bar node ids to drop for the hidden set.
	 */
	public static function admin_bar_nodes( array $settings, array $tree ) {
		$types = [];
		$nodes = [];

		foreach ( $settings['menu'] as $parent ) {
			$children = isset( $tree[ $parent ] ) ? $tree[ $parent ] : [];
			$types    = array_merge( $types, self::owned_post_types( $parent, $children ) );

			if ( 'edit-comments.php' === $parent ) {
				$nodes[] = 'comments';
			}
			if ( 'users.php' === $parent ) {
				$nodes[] = 'new-user';
			}
		}

		foreach ( $settings['submenu'] as $children ) {
			foreach ( $children as $slug ) {
				$d = self::describe( $slug );
				if ( 'file' !== $d['kind'] ) {
					continue;
				}
				if ( 'post-new.php' === $d['file'] ) {
					$types[] = isset( $d['args']['post_type'] ) ? (string) $d['args']['post_type'] : 'post';
				} elseif ( 'media-new.php' === $d['file'] ) {
					$types[] = 'attachment';
				} elseif ( 'user-new.php' === $d['file'] ) {
					$nodes[] = 'new-user';
				}
			}
		}

		foreach ( array_unique( $types ) as $type ) {
			if ( 'attachment' === $type ) {
				$nodes[] = 'new-media';
			} else {
				$nodes[] = 'new-' . $type;
			}
		}

		return array_values( array_unique( $nodes ) );
	}

	/**
	 * Plain-text label for a menu title that may carry count bubbles.
	 */
	public static function clean_label( $title, $fallback = '' ) {
		$title = (string) $title;

		// Drop count bubbles and screen-reader copy with their contents,
		// innermost first, before stripping the remaining tags.
		$pattern = '#<(span|div)\b[^>]*class=(["\'])[^"\']*(?:update-plugins|awaiting-mod|count-\d+|pending-count|plugin-count|update-count|screen-reader-text|menu-counter|update-count)[^"\']*\2[^>]*>(?:(?!<\1\b).)*?</\1>#is';
		for ( $i = 0; $i < 5; $i++ ) {
			$next = preg_replace( $pattern, '', $title );
			if ( null === $next || $next === $title ) {
				break;
			}
			$title = $next;
		}

		$title = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $title ) ) );

		return '' !== $title ? $title : (string) $fallback;
	}

	// ------------------------------------------------------------------
	// Runtime hooks
	// ------------------------------------------------------------------

	public static function capture() {
		global $menu, $submenu;

		self::$captured = [
			'menu'    => is_array( $menu ) ? $menu : [],
			'submenu' => is_array( $submenu ) ? $submenu : [],
		];
	}

	/**
	 * @return array{menu:array, submenu:array}
	 */
	public static function captured() {
		return null === self::$captured ? [ 'menu' => [], 'submenu' => [] ] : self::$captured;
	}

	/**
	 * parent slug => child slugs, normalised, from the captured menu.
	 */
	public static function tree() {
		$tree = [];
		$cap  = self::captured();

		foreach ( $cap['menu'] as $item ) {
			if ( empty( $item[2] ) ) {
				continue;
			}
			$tree[ self::normalize( $item[2] ) ] = [];
		}

		foreach ( $cap['submenu'] as $parent => $items ) {
			$p = self::normalize( $parent );
			if ( ! isset( $tree[ $p ] ) ) {
				$tree[ $p ] = [];
			}
			foreach ( (array) $items as $item ) {
				if ( ! empty( $item[2] ) ) {
					$tree[ $p ][] = self::normalize( $item[2] );
				}
			}
		}

		return $tree;
	}

	private static function skip_request() {
		return wp_doing_ajax()
			|| wp_doing_cron()
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'WP_CLI' ) && WP_CLI )
			|| ( function_exists( 'is_network_admin' ) && is_network_admin() )
			|| ( function_exists( 'is_user_admin' ) && is_user_admin() );
	}

	public static function apply() {
		if ( self::skip_request() ) {
			return;
		}

		$settings = self::get_settings();
		if ( ! self::has_rules( $settings ) || ! self::applies_to_current_user( $settings ) ) {
			return;
		}

		global $menu, $submenu;

		$hidden_top = array_flip( $settings['menu'] );

		if ( is_array( $menu ) ) {
			foreach ( $menu as $i => $item ) {
				if ( ! empty( $item[2] ) && isset( $hidden_top[ self::normalize( $item[2] ) ] ) ) {
					unset( $menu[ $i ] );
				}
			}
		}

		if ( is_array( $submenu ) ) {
			foreach ( $submenu as $parent => $items ) {
				$p = self::normalize( $parent );
				if ( empty( $settings['submenu'][ $p ] ) ) {
					continue;
				}
				$hidden_children = array_flip( $settings['submenu'][ $p ] );
				foreach ( (array) $items as $i => $item ) {
					if ( ! empty( $item[2] ) && isset( $hidden_children[ self::normalize( $item[2] ) ] ) ) {
						unset( $submenu[ $parent ][ $i ] );
					}
				}
			}
		}
	}

	/**
	 * Request context for the matcher, read from the current admin request.
	 */
	public static function current_context() {
		// Core sets these in wp-admin/admin.php before the menu is built, from
		// the same input it routes on: $plugin_page is ?page= run through
		// plugin_basename() (so "slug/" and "slug" are one page), $typenow and
		// $taxnow come from $_REQUEST (a POSTed post_type selects the list).
		global $pagenow, $plugin_page, $typenow, $taxnow;

		// phpcs:disable WordPress.Security.NonceVerification -- read-only routing.
		$get = wp_unslash( $_GET );
		$get = is_array( $get ) ? $get : [];
		$now = (string) $pagenow;

		if ( isset( $plugin_page ) && is_string( $plugin_page ) && '' !== $plugin_page ) {
			$get['page'] = $plugin_page;
		}

		$request_type = is_string( $typenow ) && '' !== $typenow ? sanitize_key( $typenow ) : '';

		$post_type = '';
		if ( 'post.php' === $now ) {
			// Core prefers ?post= and refuses a mismatched POST post_ID.
			$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : ( isset( $_POST['post_ID'] ) ? (int) $_POST['post_ID'] : 0 );
			if ( ! $post_id && isset( $_REQUEST['post_ID'] ) ) {
				$post_id = (int) $_REQUEST['post_ID'];
			}
			if ( $post_id ) {
				$post_type = (string) get_post_type( $post_id );
			}
		} elseif ( 'revision.php' === $now ) {
			foreach ( [ 'revision', 'from', 'to' ] as $key ) {
				$rev_id = isset( $_REQUEST[ $key ] ) && is_scalar( $_REQUEST[ $key ] ) ? absint( $_REQUEST[ $key ] ) : 0;
				$parent = $rev_id ? wp_get_post_parent_id( $rev_id ) : 0;
				if ( $parent ) {
					$post_type = (string) get_post_type( $parent );
					break;
				}
			}
		} elseif ( 'edit.php' === $now ) {
			$post_type = '' !== $request_type ? $request_type : 'post';
		} elseif ( 'post-new.php' === $now ) {
			$post_type = ! empty( $get['post_type'] ) && is_string( $get['post_type'] ) ? sanitize_key( $get['post_type'] ) : 'post';
		} elseif ( in_array( $now, [ 'upload.php', 'media-new.php' ], true ) ) {
			$post_type = 'attachment';
		} elseif ( '' !== $request_type ) {
			$post_type = $request_type;
		}

		// request_matches() compares ?post_type= through $get; keep it in
		// step with the type core will actually load.
		if ( '' !== $post_type && isset( $get['post_type'] ) ) {
			$get['post_type'] = $post_type;
		}

		$taxonomy_types = [];
		$tax_name       = is_string( $taxnow ) && '' !== $taxnow ? $taxnow : ( isset( $get['taxonomy'] ) && is_string( $get['taxonomy'] ) ? $get['taxonomy'] : '' );
		if ( in_array( $now, [ 'edit-tags.php', 'term.php' ], true ) && '' !== $tax_name ) {
			$tax = get_taxonomy( sanitize_key( $tax_name ) );
			if ( $tax ) {
				$taxonomy_types = array_values( (array) $tax->object_type );
			}
			$get['taxonomy'] = sanitize_key( $tax_name );
		}
		// phpcs:enable

		return [
			'pagenow'        => $now,
			'get'            => $get,
			'post_type'      => $post_type,
			'taxonomy_types' => $taxonomy_types,
		];
	}

	public static function guard() {
		if ( self::skip_request() ) {
			return;
		}

		$settings = self::get_settings();
		if ( ! self::has_rules( $settings ) || ! self::applies_to_current_user( $settings ) ) {
			return;
		}

		$blocked = self::blocking_slug( self::current_context(), $settings, self::tree() );
		if ( '' === $blocked ) {
			return;
		}

		wp_die(
			esc_html__( 'This page has been turned off by the site administrator.', 'wp-admin-speedboost' ),
			esc_html__( 'Page unavailable', 'wp-admin-speedboost' ),
			[
				'response'  => 403,
				'link_url'  => esc_url( admin_url() ),
				'link_text' => esc_html__( 'Back to Dashboard', 'wp-admin-speedboost' ),
			]
		);
	}

	/**
	 * @param WP_Admin_Bar $bar
	 */
	public static function prune_admin_bar( $bar ) {
		if ( ! is_object( $bar ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		$settings = self::get_settings();
		if ( ! self::has_rules( $settings ) || ! self::applies_to_current_user( $settings ) ) {
			return;
		}

		// On the front end admin_menu never ran, so the tree is empty; the
		// top-level slugs and hidden children still name every post type.
		foreach ( self::admin_bar_nodes( $settings, self::tree() ) as $id ) {
			$bar->remove_node( $id );
		}

		// "+ New" links to its first action. If that one is gone, point it at
		// the first survivor, or drop the whole menu when nothing is left.
		$parent = $bar->get_node( 'new-content' );
		if ( ! $parent ) {
			return;
		}

		$first = null;
		foreach ( (array) $bar->get_nodes() as $node ) {
			if ( isset( $node->parent ) && 'new-content' === $node->parent ) {
				$first = $node;
				break;
			}
		}

		if ( null === $first ) {
			$bar->remove_node( 'new-content' );
			return;
		}

		if ( ! empty( $first->href ) && $first->href !== $parent->href ) {
			$bar->add_node(
				[
					'id'   => 'new-content',
					'href' => $first->href,
				]
			);
		}
	}

	/**
	 * Slugs present in the live menu, stored so the save handler can
	 * whitelist what the editor form posts back.
	 *
	 * @return array{menu:string[], submenu:array<string,string[]>}
	 */
	public static function snapshot_from_capture() {
		$tree = self::tree();

		return [
			'menu'    => array_keys( $tree ),
			'submenu' => array_filter( $tree ),
		];
	}

	public static function store_snapshot() {
		$snap = self::snapshot_from_capture();
		if ( get_option( self::OPT_SNAPSHOT ) !== $snap ) {
			update_option( self::OPT_SNAPSHOT, $snap, false );
		}
		return $snap;
	}
}

/**
 * Module init callback.
 */
function wpasb_admin_menu_init() {
	WPASB_Admin_Menu::init();

	if ( is_admin() && class_exists( 'WPASB_Admin_Menu_Page' ) ) {
		WPASB_Admin_Menu_Page::instance();
	}
}
