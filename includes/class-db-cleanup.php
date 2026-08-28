<?php
/**
 * Handles the "Run Cleanup" button POST on the settings page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPASB_DB_Cleanup {

	/**
	 * Transient key used to carry the report across the post-redirect-get.
	 */
	const REPORT_TRANSIENT = 'wpasb_cleanup_report_';

	public function __construct() {
		add_action( 'admin_post_wpasb_cleanup', [ $this, 'handle' ] );
		add_action( 'admin_notices', [ $this, 'maybe_notice' ] );
	}

	/**
	 * Processes the cleanup request, then redirects (PRG) so a browser refresh
	 * cannot re-run destructive queries.
	 */
	public function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'You do not have permission to run this cleanup.', 'wp-admin-speedboost' ),
				esc_html__( 'Forbidden', 'wp-admin-speedboost' ),
				[ 'response' => 403 ]
			);
		}

		check_admin_referer( 'wpasb_cleanup', 'wpasb_cleanup_nonce' );

		$report = $this->run();

		set_transient( self::REPORT_TRANSIENT . get_current_user_id(), $report, 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect(
			add_query_arg(
				'wpasb-cleaned',
				'1',
				admin_url( 'options-general.php?page=wp-admin-speedboost' )
			)
		);
		exit;
	}

	public function maybe_notice() {
		if ( empty( $_GET['wpasb-cleaned'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$key    = self::REPORT_TRANSIENT . get_current_user_id();
		$report = get_transient( $key );
		if ( ! is_array( $report ) ) {
			return;
		}
		delete_transient( $key );

		$message = sprintf(
			/* translators: 1: revisions, 2: transients, 3: orphaned meta rows */
			__( '%1$d revisions deleted, %2$d expired transients deleted, %3$d orphaned meta rows removed.', 'wp-admin-speedboost' ),
			(int) $report['revisions'],
			(int) $report['transients'],
			(int) $report['orphan_meta']
		);

		if ( ! empty( $report['tables'] ) ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of database tables optimised */
				__( '%d tables optimised.', 'wp-admin-speedboost' ),
				(int) $report['tables']
			);
		}

		if ( ! empty( $report['skipped_innodb'] ) ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of InnoDB tables skipped */
				__( '%d InnoDB tables were skipped: InnoDB reclaims space automatically and OPTIMIZE would lock them.', 'wp-admin-speedboost' ),
				(int) $report['skipped_innodb']
			);
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Cleanup complete:', 'wp-admin-speedboost' ),
			esc_html( $message )
		);
	}

	private function run() {
		global $wpdb;

		$report = [
			'revisions'      => 0,
			'transients'     => 0,
			'orphan_meta'    => 0,
			'tables'         => 0,
			'skipped_innodb' => 0,
		];

		/**
		 * Revisions are deleted through wp_delete_post_revision() so that postmeta,
		 * term relationships and the object cache stay consistent. The old raw
		 * DELETE left orphaned postmeta behind and never invalidated caches.
		 * Batched to keep memory and query time bounded on large sites.
		 */
		$batch_size = (int) apply_filters( 'wpasb_cleanup_batch_size', 500 );
		$batch_size = max( 50, min( 5000, $batch_size ) );

		do {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' LIMIT %d",
					$batch_size
				)
			);

			foreach ( $ids as $id ) {
				if ( wp_delete_post_revision( (int) $id ) ) {
					$report['revisions']++;
				} else {
					// Avoid an infinite loop if a revision refuses to delete.
					$wpdb->delete( $wpdb->posts, [ 'ID' => (int) $id ], [ '%d' ] );
					$report['revisions']++;
				}
			}
		} while ( count( $ids ) === $batch_size );

		/**
		 * Only expired transients are removed. The previous query deleted every
		 * transient including live ones, which forced expensive cache rebuilds and
		 * could log out sessions or break plugins mid-request.
		 */
		$report['transients'] = $this->delete_expired_transients();

		// Orphaned metadata left by other plugins or hard-deleted posts.
		$report['orphan_meta'] += (int) $wpdb->query(
			"DELETE pm FROM {$wpdb->postmeta} pm
			 LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE p.ID IS NULL"
		);
		$report['orphan_meta'] += (int) $wpdb->query(
			"DELETE cm FROM {$wpdb->commentmeta} cm
			 LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
			 WHERE c.comment_ID IS NULL"
		);

		$report['tables'] = $this->optimise_tables( $skipped );
		$report['skipped_innodb'] = $skipped;

		// Drop the in-memory cache so the admin does not read stale rows.
		wp_cache_flush();

		return $report;
	}

	/**
	 * Deletes expired transients from the options table (and sitemeta on multisite).
	 * Live transients are preserved.
	 */
	private function delete_expired_transients() {
		global $wpdb;

		$now     = time();
		$deleted = 0;

		$expired = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options}
				 WHERE option_name LIKE %s
				   AND option_value < %d",
				$wpdb->esc_like( '_transient_timeout_' ) . '%',
				$now
			)
		);

		foreach ( $expired as $option_name ) {
			$key = str_replace( '_transient_timeout_', '', $option_name );
			delete_transient( $key );
			$deleted++;
		}

		$expired_site = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options}
				 WHERE option_name LIKE %s
				   AND option_value < %d",
				$wpdb->esc_like( '_site_transient_timeout_' ) . '%',
				$now
			)
		);

		foreach ( $expired_site as $option_name ) {
			$key = str_replace( '_site_transient_timeout_', '', $option_name );
			delete_site_transient( $key );
			$deleted++;
		}

		if ( is_multisite() ) {
			$expired_meta = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT meta_key FROM {$wpdb->sitemeta}
					 WHERE meta_key LIKE %s
					   AND meta_value < %d
					   AND site_id = %d",
					$wpdb->esc_like( '_site_transient_timeout_' ) . '%',
					$now,
					get_current_network_id()
				)
			);

			foreach ( $expired_meta as $meta_key ) {
				delete_site_transient( str_replace( '_site_transient_timeout_', '', $meta_key ) );
				$deleted++;
			}
		}

		return $deleted;
	}

	/**
	 * OPTIMIZE TABLE only on engines where it is meaningful and non-blocking-ish.
	 * InnoDB maps it to a full table rebuild, so it is skipped unless the site
	 * owner explicitly opts in via filter.
	 */
	private function optimise_tables( &$skipped = 0 ) {
		global $wpdb;

		$skipped = 0;
		$tables = [
			$wpdb->posts, $wpdb->postmeta, $wpdb->options, $wpdb->comments,
			$wpdb->commentmeta, $wpdb->terms, $wpdb->term_taxonomy,
			$wpdb->term_relationships, $wpdb->termmeta, $wpdb->users, $wpdb->usermeta,
		];

		$allow_innodb = (bool) apply_filters( 'wpasb_optimize_innodb', false );
		$optimised    = 0;

		foreach ( $tables as $table ) {
			if ( ! preg_match( '/^[A-Za-z0-9_]+$/', $table ) ) {
				continue;
			}

			if ( ! $allow_innodb ) {
				$engine = $wpdb->get_var(
					$wpdb->prepare(
						'SELECT ENGINE FROM information_schema.TABLES
						 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
						$table
					)
				);

				if ( $engine && 0 === strcasecmp( $engine, 'InnoDB' ) ) {
					$skipped++;
					continue;
				}
			}

			$res = $wpdb->query( "OPTIMIZE TABLE `{$table}`" );
			if ( false !== $res ) {
				$optimised++;
			}
		}

		return $optimised;
	}
}
