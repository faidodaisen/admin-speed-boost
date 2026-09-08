<?php
/**
 * Duplicate Post/Page engine.
 *
 * Adds a "Duplicate" row action to every public post type list table and a
 * "Copy to a new draft" button in the editor. The copy is always created as a
 * draft owned by the current user, so nothing goes live by accident.
 *
 * Helpers live here rather than in the module file because a module file can be
 * included more than once (each Module_Loader instance re-includes it) and
 * redeclaring a function would be fatal.
 *
 * @package WP_Admin_Speedboost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPASB_Duplicate_Post {

	const ACTION = 'wpasb_duplicate_post';
	const NONCE  = 'wpasb_duplicate_post_';

	/**
	 * Meta keys that must never be carried over. Copying these produces a post
	 * that WordPress thinks is locked, or that redirects to the original URL.
	 */
	private static $skipped_meta = [
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_old_date',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
		'_wpasb_duplicate_of',
	];

	public function __construct() {
		add_filter( 'post_row_actions', [ $this, 'row_action' ], 10, 2 );
		add_filter( 'page_row_actions', [ $this, 'row_action' ], 10, 2 );

		// admin-post.php only fires admin_post_{action}. Registering admin_action_
		// as well would risk running the handler twice on screens routed through
		// wp-admin/admin.php.
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );

		add_action( 'post_submitbox_misc_actions', [ $this, 'editor_button' ] );
		add_action( 'admin_notices', [ $this, 'notice' ] );
	}

	/**
	 * Post types that carry their own identity beyond the post row, so a blind
	 * copy produces something broken rather than a useful draft. ACF field
	 * groups key off unique field keys, reusable blocks and navigation are
	 * referenced by ID, and form submissions are records, not content.
	 *
	 * @var string[]
	 */
	private static $excluded_types = [
		'attachment',
		'revision',
		'nav_menu_item',
		'wp_navigation',
		'wp_block',
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_font_family',
		'wp_font_face',
		'acf-field',
		'acf-field-group',
		'acf-post-type',
		'acf-taxonomy',
		'acf-ui-options-page',
		'breakdance_form_res',
		'oembed_cache',
		'user_request',
		'custom_css',
		'customize_changeset',
		'scheduled-action',
		'shop_order',
		'shop_order_refund',
		'shop_subscription',
	];

	/**
	 * Post types a user may duplicate into. Non-public types (revisions,
	 * attachments, ACF field groups and friends) are deliberately excluded.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$types = get_post_types( [ 'show_ui' => true ], 'names' );

		$types = array_values( array_diff( $types, self::$excluded_types ) );

		/**
		 * Filter the post types that get a Duplicate action.
		 *
		 * @param string[] $types Post type names.
		 */
		return (array) apply_filters( 'wpasb_duplicate_post_types', $types );
	}

	/**
	 * @param WP_Post|int $post
	 * @return bool
	 */
	public static function can_duplicate( $post ) {
		$post = get_post( $post );

		if ( ! $post instanceof WP_Post ) {
			return false;
		}

		if ( ! in_array( $post->post_type, self::post_types(), true ) ) {
			return false;
		}

		$type = get_post_type_object( $post->post_type );

		if ( ! $type ) {
			return false;
		}

		// Needs to be able to read this one and create a new one of the same type.
		return current_user_can( 'read_post', $post->ID )
			&& current_user_can( $type->cap->create_posts );
	}

	/**
	 * @param int $post_id
	 * @return string
	 */
	public static function link( $post_id ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION . '&post=' . (int) $post_id ),
			self::NONCE . $post_id
		);
	}

	/**
	 * @param array   $actions
	 * @param WP_Post $post
	 * @return array
	 */
	public function row_action( $actions, $post ) {
		if ( ! is_array( $actions ) || ! self::can_duplicate( $post ) ) {
			return $actions;
		}

		$actions['wpasb_duplicate'] = sprintf(
			'<a href="%s" aria-label="%s">%s</a>',
			esc_url( self::link( $post->ID ) ),
			/* translators: %s: post title */
			esc_attr( sprintf( __( 'Duplicate &#8220;%s&#8221;', 'wp-admin-speedboost' ), get_the_title( $post ) ) ),
			esc_html__( 'Duplicate', 'wp-admin-speedboost' )
		);

		return $actions;
	}

	/**
	 * @param WP_Post $post
	 */
	public function editor_button( $post ) {
		if ( ! self::can_duplicate( $post ) ) {
			return;
		}

		printf(
			'<div class="misc-pub-section wpasb-duplicate-section"><a class="button" href="%s">%s</a></div>',
			esc_url( self::link( $post->ID ) ),
			esc_html__( 'Copy to a new draft', 'wp-admin-speedboost' )
		);
	}

	/**
	 * Creates the copy and redirects. Never renders output itself, so a refresh
	 * of the resulting screen cannot duplicate a second time.
	 */
	public function handle() {
		$post_id = isset( $_REQUEST['post'] ) ? absint( $_REQUEST['post'] ) : 0;

		if ( ! $post_id ) {
			wp_die(
				esc_html__( 'No post to duplicate.', 'wp-admin-speedboost' ),
				'',
				[ 'response' => 400 ]
			);
		}

		check_admin_referer( self::NONCE . $post_id );

		$post = get_post( $post_id );

		if ( ! $post instanceof WP_Post || ! self::can_duplicate( $post ) ) {
			wp_die(
				esc_html__( 'You are not allowed to duplicate this item.', 'wp-admin-speedboost' ),
				'',
				[ 'response' => 403 ]
			);
		}

		$new_id = self::duplicate( $post );

		if ( is_wp_error( $new_id ) ) {
			wp_die( esc_html( $new_id->get_error_message() ) );
		}

		/**
		 * Fires after a post has been duplicated.
		 *
		 * @param int     $new_id   New draft ID.
		 * @param WP_Post $post     Source post.
		 */
		do_action( 'wpasb_post_duplicated', $new_id, $post );

		// Land the user in the editor of the copy. Falls back to the list table
		// when the copy cannot be edited for some reason.
		if ( current_user_can( 'edit_post', $new_id ) ) {
			wp_safe_redirect( add_query_arg( 'wpasb_duplicated', 1, get_edit_post_link( $new_id, 'raw' ) ) );
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				[
					'post_type'         => $post->post_type,
					'wpasb_duplicated'  => 1,
				],
				admin_url( 'edit.php' )
			)
		);
		exit;
	}

	/**
	 * @param WP_Post $post
	 * @return int|WP_Error New post ID.
	 */
	public static function duplicate( $post ) {
		$data = [
			'post_author'    => get_current_user_id(),
			'post_content'   => $post->post_content,
			'post_excerpt'   => $post->post_excerpt,
			'post_title'     => self::copy_title( $post ),
			'post_status'    => 'draft',
			'post_type'      => $post->post_type,
			'post_parent'    => $post->post_parent,
			'menu_order'     => $post->menu_order,
			'comment_status' => $post->comment_status,
			'ping_status'    => $post->ping_status,
			'post_password'  => $post->post_password,
			'to_ping'        => $post->to_ping,
		];

		// post_name is left empty on purpose. Reusing the source slug would make
		// WordPress append -2 anyway, and copying it risks colliding with the
		// original's canonical redirects.
		$new_id = wp_insert_post( wp_slash( $data ), true );

		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}

		self::copy_taxonomies( $post->ID, $new_id, $post->post_type );
		self::copy_meta( $post->ID, $new_id );

		update_post_meta( $new_id, '_wpasb_duplicate_of', $post->ID );

		return $new_id;
	}

	/**
	 * @param WP_Post $post
	 * @return string
	 */
	private static function copy_title( $post ) {
		$suffix = apply_filters( 'wpasb_duplicate_title_suffix', __( '(Copy)', 'wp-admin-speedboost' ), $post );
		$title  = trim( $post->post_title );

		if ( '' === $title ) {
			return trim( (string) $suffix );
		}

		return trim( $title . ' ' . $suffix );
	}

	/**
	 * @param int    $from
	 * @param int    $to
	 * @param string $post_type
	 */
	private static function copy_taxonomies( $from, $to, $post_type ) {
		$taxonomies = get_object_taxonomies( $post_type );

		foreach ( $taxonomies as $taxonomy ) {
			$terms = wp_get_object_terms( $from, $taxonomy, [ 'fields' => 'ids' ] );

			if ( is_wp_error( $terms ) || ! $terms ) {
				continue;
			}

			wp_set_object_terms( $to, array_map( 'intval', $terms ), $taxonomy, false );
		}
	}

	/**
	 * @param int $from
	 * @param int $to
	 */
	private static function copy_meta( $from, $to ) {
		$meta = get_post_custom( $from );

		if ( ! is_array( $meta ) ) {
			return;
		}

		/**
		 * Filter meta keys that are not carried into the copy.
		 *
		 * @param string[] $skipped Meta keys.
		 */
		$skipped = (array) apply_filters( 'wpasb_duplicate_skipped_meta', self::$skipped_meta );

		foreach ( $meta as $key => $values ) {
			if ( in_array( $key, $skipped, true ) ) {
				continue;
			}

			foreach ( (array) $values as $value ) {
				// get_post_custom returns raw, still-serialized strings.
				add_post_meta( $to, $key, wp_slash( maybe_unserialize( $value ) ) );
			}
		}
	}

	public function notice() {
		if ( empty( $_GET['wpasb_duplicated'] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'Duplicate created as a draft.', 'wp-admin-speedboost' )
		);
	}
}

/**
 * Boots duplication unless a dedicated duplicate plugin already owns it.
 */
function wpasb_duplicate_post_init() {
	if ( ! is_admin() ) {
		return;
	}

	if ( wpasb_duplicate_post_conflict() ) {
		add_action( 'admin_notices', 'wpasb_duplicate_post_conflict_notice' );

		return;
	}

	new WPASB_Duplicate_Post();
}

/**
 * @return string Conflicting plugin name, empty when none.
 */
function wpasb_duplicate_post_conflict() {
	$known = [
		'duplicate-post/duplicate-post.php'         => 'Yoast Duplicate Post',
		'duplicate-page/duplicatepage.php'          => 'Duplicate Page',
		'post-duplicator/m4c-postduplicator.php'    => 'Post Duplicator',
		'duplicate-page-and-post/dpap.php'          => 'Duplicate Page and Post',
	];

	$active = (array) get_option( 'active_plugins', [] );

	if ( is_multisite() ) {
		$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
	}

	foreach ( $known as $basename => $label ) {
		if ( in_array( $basename, $active, true ) ) {
			return $label;
		}
	}

	return '';
}

function wpasb_duplicate_post_conflict_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-warning"><p>%s</p></div>',
		sprintf(
			/* translators: %s: conflicting plugin name */
			esc_html__( 'Admin Speedboost: the Duplicate Page & Post module is off because %s is active. Deactivate that plugin to use the built-in module.', 'wp-admin-speedboost' ),
			'<strong>' . esc_html( wpasb_duplicate_post_conflict() ) . '</strong>'
		)
	);
}
