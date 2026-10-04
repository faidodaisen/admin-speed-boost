<?php
/**
 * Database cleanup: scan first, delete only what the user has seen and approved.
 *
 * Everything here is admin-ajax, behind manage_options and the wpasb_cleanup
 * nonce:
 *
 *   scan   - read only. Counts, sizes and a preview of the records per category.
 *   list   - read only. The next page of records for one category.
 *   start  - takes the site-wide lock and returns a session token.
 *   step   - deletes ONE batch for ONE category and reports what is left.
 *   finish - flushes the object cache and releases the lock.
 *
 * The browser loops `step` until `remaining` reaches 0, so the progress it
 * shows is real work done, not a timer.
 *
 * What was scanned is what gets deleted. Each category carries a cutoff taken
 * at scan time (highest revision ID, highest meta_id, the scan timestamp), and
 * every step only touches rows at or below it. A revision saved or a transient
 * that expires between the scan and the click is therefore left alone.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPASB_DB_Cleanup {

	/**
	 * Site-scoped lock so two browser tabs cannot run destructive queries at the
	 * same time. Holds the session token of whoever started the run.
	 */
	const LOCK_TRANSIENT = 'wpasb_cleanup_running';

	/**
	 * Seconds the lock survives without a step. Every step renews it, so only a
	 * run whose browser vanished (closed tab, lost connection) ever expires.
	 */
	const LOCK_TTL = 300;

	/** Records shown per category in the scan, and per "Show more" page. */
	const PREVIEW_ROWS = 8;
	const PAGE_ROWS    = 50;

	public function __construct() {
		foreach ( [ 'scan', 'list', 'start', 'step', 'finish' ] as $op ) {
			add_action( 'wp_ajax_wpasb_cleanup_' . $op, [ $this, 'ajax_' . $op ] );
		}
	}

	/* ------------------------------------------------------------------
	 * AJAX endpoints
	 * ---------------------------------------------------------------- */

	public function ajax_scan() {
		$this->guard();

		try {
			$scan = $this->scan();
		} catch ( Throwable $e ) {
			$this->fail( __( 'The scan could not finish. Nothing was deleted.', 'wp-admin-speedboost' ) );
		}

		wp_send_json_success( $scan );
	}

	public function ajax_list() {
		$this->guard();

		$key = $this->requested_key();

		try {
			$cutoff = isset( $_POST['cutoff'] ) ? absint( wp_unslash( $_POST['cutoff'] ) ) : 0;
			$offset = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;
			$rows   = $this->rows( $key, $cutoff, $offset, self::PAGE_ROWS + 1 );
		} catch ( Throwable $e ) {
			$this->fail( __( 'The records could not be loaded.', 'wp-admin-speedboost' ) );
		}

		wp_send_json_success(
			[
				'rows'     => array_slice( $rows, 0, self::PAGE_ROWS ),
				'has_more' => count( $rows ) > self::PAGE_ROWS,
			]
		);
	}

	public function ajax_start() {
		$this->guard();

		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			$this->fail(
				__( 'A cleanup is already running on this site. Wait for it to finish before starting another. If the other window was closed, this clears by itself within five minutes.', 'wp-admin-speedboost' ),
				409
			);
		}

		$token = wp_generate_password( 20, false );
		set_transient( self::LOCK_TRANSIENT, $token, self::LOCK_TTL );

		wp_send_json_success( [ 'token' => $token ] );
	}

	public function ajax_step() {
		$this->guard();
		$this->require_lock();

		$key    = $this->requested_key();
		$cutoff = isset( $_POST['cutoff'] ) ? absint( wp_unslash( $_POST['cutoff'] ) ) : 0;
		$index  = isset( $_POST['index'] ) ? absint( wp_unslash( $_POST['index'] ) ) : 0;

		try {
			$result = $this->run_step( $key, $cutoff, $index );
		} catch ( Throwable $e ) {
			$this->fail( __( 'This step stopped before it finished. Steps that already finished are not rolled back.', 'wp-admin-speedboost' ) );
		}

		wp_send_json_success( $result );
	}

	public function ajax_finish() {
		$this->guard();
		$this->require_lock();

		// Drop the in-memory cache so the admin does not read stale rows.
		wp_cache_flush();
		delete_transient( self::LOCK_TRANSIENT );

		wp_send_json_success( [ 'state' => 'success' ] );
	}

	private function guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			$this->fail( __( 'You need administrator permission to run this cleanup.', 'wp-admin-speedboost' ), 403 );
		}

		if ( ! check_ajax_referer( 'wpasb_cleanup', 'nonce', false ) ) {
			$this->fail( __( 'This page has been open too long. Save any unsaved settings, then reload the page and try again.', 'wp-admin-speedboost' ), 403 );
		}
	}

	private function fail( $message, $status = 500 ) {
		wp_send_json_error(
			[
				'state'   => 'error',
				'message' => $message,
			],
			$status
		);
	}

	/**
	 * Steps only run inside a session that start() opened. The token proves the
	 * request belongs to the run that holds the lock, and each step renews it.
	 */
	private function require_lock() {
		$sent   = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$stored = get_transient( self::LOCK_TRANSIENT );

		if ( ! is_string( $stored ) || '' === $sent || ! hash_equals( $stored, $sent ) ) {
			$this->fail( __( 'The cleanup session ended. Scan again to start a new one.', 'wp-admin-speedboost' ), 409 );
		}

		set_transient( self::LOCK_TRANSIENT, $stored, self::LOCK_TTL );
	}

	private function requested_key() {
		$key = isset( $_POST['category'] ) ? sanitize_key( wp_unslash( $_POST['category'] ) ) : '';

		if ( ! array_key_exists( $key, $this->categories() ) ) {
			$this->fail( __( 'Unknown cleanup category.', 'wp-admin-speedboost' ), 400 );
		}

		return $key;
	}

	/* ------------------------------------------------------------------
	 * Categories
	 * ---------------------------------------------------------------- */

	private function categories() {
		return [
			'revisions'          => [
				'label'       => __( 'Post revisions', 'wp-admin-speedboost' ),
				'description' => __( 'Older saved versions of posts and pages. The live content of each post is not touched.', 'wp-admin-speedboost' ),
			],
			'transients'         => [
				'label'       => __( 'Expired transients', 'wp-admin-speedboost' ),
				'description' => __( 'Temporary cached values that have already expired. Transients that are still valid are kept.', 'wp-admin-speedboost' ),
			],
			'orphan_postmeta'    => [
				'label'       => __( 'Orphaned post meta', 'wp-admin-speedboost' ),
				'description' => __( 'Custom-field rows left behind by posts that no longer exist.', 'wp-admin-speedboost' ),
			],
			'orphan_commentmeta' => [
				'label'       => __( 'Orphaned comment meta', 'wp-admin-speedboost' ),
				'description' => __( 'Metadata rows left behind by comments that no longer exist.', 'wp-admin-speedboost' ),
			],
			'tables'             => [
				'label'       => __( 'Table optimisation', 'wp-admin-speedboost' ),
				'description' => __( 'Reclaims unused space in MyISAM tables.', 'wp-admin-speedboost' ),
			],
		];
	}

	/* ------------------------------------------------------------------
	 * Scan (read only)
	 * ---------------------------------------------------------------- */

	public function scan() {
		$categories = [];
		$records    = 0;
		$bytes      = 0;
		$tables     = 0;

		foreach ( $this->categories() as $key => $meta ) {
			// The table list is short, so it is always shown in full.
			$limit   = 'tables' === $key ? 100 : self::PREVIEW_ROWS;
			$cutoff  = $this->cutoff_for( $key );
			$measure = $this->measure( $key, $cutoff );
			$preview = $this->rows( $key, $cutoff, 0, $limit + 1 );

			$categories[] = [
				'key'         => $key,
				'label'       => $meta['label'],
				'description' => $meta['description'],
				'count'       => $measure['count'],
				'bytes'       => $measure['bytes'],
				'size'        => $this->size_label( $measure['bytes'] ),
				'cutoff'      => $cutoff,
				'rows'        => array_slice( $preview, 0, $limit ),
				'has_more'    => count( $preview ) > $limit,
			];

			if ( 'tables' === $key ) {
				$tables += $measure['count'];
			} else {
				$records += $measure['count'];
			}
			$bytes += (int) $measure['bytes'];
		}

		$skipped = count(
			array_filter(
				$this->table_status(),
				static function ( $t ) {
					return $t['skipped'];
				}
			)
		);

		return [
			'categories'     => $categories,
			'records'        => $records,
			'tables'         => $tables,
			'bytes'          => $bytes,
			'size'           => $this->size_label( $bytes ),
			'skipped_innodb' => $skipped,
			'note'           => $skipped
				? __( 'InnoDB tables were skipped on purpose: InnoDB reclaims space by itself and OPTIMIZE would rebuild and lock them.', 'wp-admin-speedboost' )
				: '',
		];
	}

	/**
	 * The snapshot boundary stored with each category. Steps never reach past it.
	 */
	private function cutoff_for( $key ) {
		global $wpdb;

		switch ( $key ) {
			case 'revisions':
				return (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type = 'revision'" );
			case 'transients':
				return time();
			case 'orphan_postmeta':
				return (int) $wpdb->get_var( "SELECT MAX(meta_id) FROM {$wpdb->postmeta}" );
			case 'orphan_commentmeta':
				return (int) $wpdb->get_var( "SELECT MAX(meta_id) FROM {$wpdb->commentmeta}" );
		}

		return 0;
	}

	/**
	 * Row count and, where it is cheap to know, the bytes those rows occupy.
	 * bytes is null when it cannot be measured honestly (transients).
	 */
	private function measure( $key, $cutoff ) {
		global $wpdb;

		$count = 0;
		$bytes = null;

		switch ( $key ) {
			case 'revisions':
				$row   = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT COUNT(*) AS n,
						        SUM( IFNULL( LENGTH( post_content ), 0 ) + IFNULL( LENGTH( post_title ), 0 ) + IFNULL( LENGTH( post_excerpt ), 0 ) ) AS b
						 FROM {$wpdb->posts}
						 WHERE post_type = 'revision' AND ID <= %d",
						$cutoff
					)
				);
				$count = $row ? (int) $row->n : 0;
				$bytes = $row ? (int) $row->b : 0;
				break;

			case 'transients':
				$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM (' . $this->expired_transients_sql( $cutoff ) . ') AS t' );
				break;

			case 'orphan_postmeta':
				$row   = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT COUNT(*) AS n,
						        SUM( IFNULL( LENGTH( pm.meta_key ), 0 ) + IFNULL( LENGTH( pm.meta_value ), 0 ) ) AS b
						 FROM {$wpdb->postmeta} pm
						 LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
						 WHERE p.ID IS NULL AND pm.meta_id <= %d",
						$cutoff
					)
				);
				$count = $row ? (int) $row->n : 0;
				$bytes = $row ? (int) $row->b : 0;
				break;

			case 'orphan_commentmeta':
				$row   = $wpdb->get_row(
					$wpdb->prepare(
						"SELECT COUNT(*) AS n,
						        SUM( IFNULL( LENGTH( cm.meta_key ), 0 ) + IFNULL( LENGTH( cm.meta_value ), 0 ) ) AS b
						 FROM {$wpdb->commentmeta} cm
						 LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
						 WHERE c.comment_ID IS NULL AND cm.meta_id <= %d",
						$cutoff
					)
				);
				$count = $row ? (int) $row->n : 0;
				$bytes = $row ? (int) $row->b : 0;
				break;

			case 'tables':
				$eligible = array_filter(
					$this->table_status(),
					static function ( $t ) {
						return ! $t['skipped'];
					}
				);
				$count    = count( $eligible );
				$bytes    = array_sum( wp_list_pluck( $eligible, 'free' ) );
				break;
		}

		return [
			'count' => $count,
			'bytes' => $bytes,
		];
	}

	private function size_label( $bytes ) {
		if ( null === $bytes || (int) $bytes <= 0 ) {
			return '';
		}

		return (string) size_format( (int) $bytes, 1 );
	}

	/**
	 * The records behind one category, as display rows. Plain strings only: the
	 * browser writes them with textContent, so a post title containing markup is
	 * shown as text and never parsed.
	 */
	private function rows( $key, $cutoff, $offset, $limit ) {
		global $wpdb;

		$offset = (int) $offset;
		$limit  = max( 1, (int) $limit );
		$rows   = [];

		switch ( $key ) {
			case 'revisions':
				$found = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT r.ID, r.post_date, r.post_parent, p.post_title AS parent_title,
						        ( IFNULL( LENGTH( r.post_content ), 0 ) + IFNULL( LENGTH( r.post_title ), 0 ) + IFNULL( LENGTH( r.post_excerpt ), 0 ) ) AS bytes
						 FROM {$wpdb->posts} r
						 LEFT JOIN {$wpdb->posts} p ON p.ID = r.post_parent
						 WHERE r.post_type = 'revision' AND r.ID <= %d
						 ORDER BY r.ID DESC
						 LIMIT %d OFFSET %d",
						$cutoff,
						$limit,
						$offset
					)
				);

				foreach ( (array) $found as $r ) {
					if ( null === $r->parent_title ) {
						$title = __( '(parent post no longer exists)', 'wp-admin-speedboost' );
					} elseif ( '' === trim( $r->parent_title ) ) {
						$title = __( '(no title)', 'wp-admin-speedboost' );
					} else {
						$title = wp_strip_all_tags( $r->parent_title );
					}

					$rows[] = [
						'title'  => $title,
						/* translators: 1: revision ID, 2: date and time */
						'detail' => sprintf( __( 'Revision #%1$d · %2$s', 'wp-admin-speedboost' ), (int) $r->ID, substr( (string) $r->post_date, 0, 16 ) ),
						'extra'  => $this->size_label( (int) $r->bytes ),
						'tag'    => '',
					];
				}
				break;

			case 'transients':
				$found = $wpdb->get_results(
					'SELECT name, expires, origin FROM (' . $this->expired_transients_sql( $cutoff ) . ") AS t
					 ORDER BY CAST( expires AS UNSIGNED ) ASC, name ASC
					 LIMIT {$limit} OFFSET {$offset}"
				);

				foreach ( (array) $found as $r ) {
					$rows[] = [
						'title'  => $this->transient_key( $r->name ),
						/* translators: %s: how long ago the transient expired, e.g. "3 days" */
						'detail' => sprintf( __( 'Expired %s ago', 'wp-admin-speedboost' ), human_time_diff( (int) $r->expires, time() ) ),
						'extra'  => '',
						'tag'    => $this->is_site_transient( $r->name ) ? __( 'Network-wide', 'wp-admin-speedboost' ) : '',
					];
				}
				break;

			case 'orphan_postmeta':
				$found = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT pm.meta_id, pm.post_id, pm.meta_key,
						        ( IFNULL( LENGTH( pm.meta_key ), 0 ) + IFNULL( LENGTH( pm.meta_value ), 0 ) ) AS bytes
						 FROM {$wpdb->postmeta} pm
						 LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
						 WHERE p.ID IS NULL AND pm.meta_id <= %d
						 ORDER BY pm.meta_id DESC
						 LIMIT %d OFFSET %d",
						$cutoff,
						$limit,
						$offset
					)
				);

				foreach ( (array) $found as $r ) {
					$rows[] = [
						'title'  => (string) $r->meta_key,
						/* translators: 1: meta row ID, 2: ID of the post that is gone */
						'detail' => sprintf( __( 'Meta #%1$d · belonged to post #%2$d, which no longer exists', 'wp-admin-speedboost' ), (int) $r->meta_id, (int) $r->post_id ),
						'extra'  => $this->size_label( (int) $r->bytes ),
						'tag'    => '',
					];
				}
				break;

			case 'orphan_commentmeta':
				$found = $wpdb->get_results(
					$wpdb->prepare(
						"SELECT cm.meta_id, cm.comment_id, cm.meta_key,
						        ( IFNULL( LENGTH( cm.meta_key ), 0 ) + IFNULL( LENGTH( cm.meta_value ), 0 ) ) AS bytes
						 FROM {$wpdb->commentmeta} cm
						 LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
						 WHERE c.comment_ID IS NULL AND cm.meta_id <= %d
						 ORDER BY cm.meta_id DESC
						 LIMIT %d OFFSET %d",
						$cutoff,
						$limit,
						$offset
					)
				);

				foreach ( (array) $found as $r ) {
					$rows[] = [
						'title'  => (string) $r->meta_key,
						/* translators: 1: meta row ID, 2: ID of the comment that is gone */
						'detail' => sprintf( __( 'Meta #%1$d · belonged to comment #%2$d, which no longer exists', 'wp-admin-speedboost' ), (int) $r->meta_id, (int) $r->comment_id ),
						'extra'  => $this->size_label( (int) $r->bytes ),
						'tag'    => '',
					];
				}
				break;

			case 'tables':
				// A handful of tables, always listed in full with the skipped ones
				// included, so "why was wp_posts not touched?" is answered on screen.
				foreach ( $this->table_status() as $t ) {
					$engine = '' !== $t['engine'] ? $t['engine'] : __( 'unknown engine', 'wp-admin-speedboost' );

					$rows[] = [
						'title'  => $t['name'],
						'detail' => $t['skipped']
							/* translators: %s: storage engine, e.g. InnoDB */
							? sprintf( __( '%s · skipped, reclaims space by itself', 'wp-admin-speedboost' ), $engine )
							/* translators: %s: storage engine, e.g. MyISAM */
							: sprintf( __( '%s · will be optimised', 'wp-admin-speedboost' ), $engine ),
						'extra'  => $t['skipped'] ? '' : $this->size_label( $t['free'] ),
						'tag'    => $t['skipped'] ? __( 'Skipped', 'wp-admin-speedboost' ) : '',
					];
				}
				$rows = array_slice( $rows, $offset, $limit );
				break;
		}

		return $rows;
	}

	/* ------------------------------------------------------------------
	 * Transients
	 * ---------------------------------------------------------------- */

	/**
	 * One SELECT (name, expires, origin) over every expired transient timeout
	 * row: the options table, plus the network sitemeta table on multisite.
	 * Only rows that expired before $cutoff are included. Live ones never are.
	 */
	private function expired_transients_sql( $cutoff ) {
		global $wpdb;

		$sql = $wpdb->prepare(
			"SELECT option_name AS name, option_value AS expires, 'options' AS origin
			 FROM {$wpdb->options}
			 WHERE ( option_name LIKE %s OR option_name LIKE %s )
			   AND option_value < %d",
			$wpdb->esc_like( '_transient_timeout_' ) . '%',
			$wpdb->esc_like( '_site_transient_timeout_' ) . '%',
			(int) $cutoff
		);

		if ( is_multisite() ) {
			$sql .= $wpdb->prepare(
				" UNION ALL
				 SELECT meta_key AS name, meta_value AS expires, 'network' AS origin
				 FROM {$wpdb->sitemeta}
				 WHERE meta_key LIKE %s
				   AND meta_value < %d
				   AND site_id = %d",
				$wpdb->esc_like( '_site_transient_timeout_' ) . '%',
				(int) $cutoff,
				get_current_network_id()
			);
		}

		return $sql;
	}

	private function is_site_transient( $option_name ) {
		return 0 === strpos( $option_name, '_site_transient_timeout_' );
	}

	private function transient_key( $option_name ) {
		foreach ( [ '_site_transient_timeout_', '_transient_timeout_' ] as $prefix ) {
			if ( 0 === strpos( $option_name, $prefix ) ) {
				return substr( $option_name, strlen( $prefix ) );
			}
		}

		return (string) $option_name;
	}

	/* ------------------------------------------------------------------
	 * Tables
	 * ---------------------------------------------------------------- */

	/**
	 * The core tables this cleanup looks at, with engine, reclaimable space and
	 * whether OPTIMIZE will skip them. InnoDB is skipped unless the site owner
	 * opts in through the wpasb_optimize_innodb filter.
	 *
	 * @return array[] name, engine, free (bytes), skipped.
	 */
	private function table_status() {
		global $wpdb;

		$names = array_values(
			array_filter(
				[
					$wpdb->posts, $wpdb->postmeta, $wpdb->options, $wpdb->comments,
					$wpdb->commentmeta, $wpdb->terms, $wpdb->term_taxonomy,
					$wpdb->term_relationships, $wpdb->termmeta, $wpdb->users, $wpdb->usermeta,
				],
				static function ( $table ) {
					return (bool) preg_match( '/^[A-Za-z0-9_]+$/', $table );
				}
			)
		);

		$allow_innodb = (bool) apply_filters( 'wpasb_optimize_innodb', false );

		$found = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME AS name, ENGINE AS engine, DATA_FREE AS free
				 FROM information_schema.TABLES
				 WHERE TABLE_SCHEMA = DATABASE()
				   AND TABLE_NAME IN (' . implode( ',', array_fill( 0, count( $names ), '%s' ) ) . ')',
				$names
			),
			OBJECT_K
		);

		$status = [];

		foreach ( $names as $name ) {
			$info   = isset( $found[ $name ] ) ? $found[ $name ] : null;
			$engine = $info && $info->engine ? (string) $info->engine : '';

			$status[] = [
				'name'    => $name,
				'engine'  => $engine,
				'free'    => $info ? (int) $info->free : 0,
				'skipped' => ! $allow_innodb && 0 === strcasecmp( $engine, 'InnoDB' ),
			];
		}

		return $status;
	}

	/* ------------------------------------------------------------------
	 * Steps (these delete)
	 * ---------------------------------------------------------------- */

	/**
	 * Runs one batch for one category.
	 *
	 * @return array processed (this batch), remaining (still to do), stalled
	 *               (true when nothing could be removed but rows remain, so the
	 *               browser stops instead of looping forever).
	 */
	private function run_step( $key, $cutoff, $index ) {
		if ( 'tables' === $key ) {
			return $this->step_tables( $index );
		}

		// No cutoff means nothing was approved for this category.
		if ( $cutoff <= 0 ) {
			return [
				'processed' => 0,
				'remaining' => 0,
				'stalled'   => false,
			];
		}

		$batch = (int) apply_filters( 'wpasb_cleanup_batch_size', 200 );
		$batch = max( 25, min( 5000, $batch ) );

		switch ( $key ) {
			case 'revisions':
				$processed = $this->delete_revisions( $cutoff, $batch );
				break;
			case 'transients':
				$processed = $this->delete_transients( $cutoff, $batch );
				break;
			case 'orphan_postmeta':
				$processed = $this->delete_orphan_meta( 'post', $cutoff, $batch );
				break;
			default:
				$processed = $this->delete_orphan_meta( 'comment', $cutoff, $batch );
		}

		$measure   = $this->measure( $key, $cutoff );
		$remaining = (int) $measure['count'];

		return [
			'processed' => $processed,
			'remaining' => $remaining,
			'stalled'   => 0 === $processed && $remaining > 0,
		];
	}

	/**
	 * Revisions go through wp_delete_post_revision() so postmeta, term
	 * relationships and the object cache stay consistent.
	 */
	private function delete_revisions( $cutoff, $batch ) {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' AND ID <= %d ORDER BY ID ASC LIMIT %d",
				$cutoff,
				$batch
			)
		);

		$done = 0;

		foreach ( $ids as $id ) {
			if ( wp_delete_post_revision( (int) $id ) ) {
				$done++;
			} elseif ( $wpdb->delete( $wpdb->posts, [ 'ID' => (int) $id ], [ '%d' ] ) ) {
				// A revision that refuses to delete would otherwise be picked up
				// again on every batch.
				$done++;
			}
		}

		return $done;
	}

	/**
	 * delete_transient() only removes the timeout row when the value row was
	 * there to delete, so a timeout whose value is already gone would survive
	 * and be counted again on every scan. The explicit delete below closes that.
	 */
	private function delete_transients( $cutoff, $batch ) {
		global $wpdb;

		$found = $wpdb->get_results(
			'SELECT name, origin FROM (' . $this->expired_transients_sql( $cutoff ) . ') AS t ORDER BY name ASC LIMIT ' . (int) $batch
		);

		$done = 0;

		foreach ( (array) $found as $r ) {
			$key = $this->transient_key( $r->name );

			if ( 'network' === $r->origin ) {
				delete_site_transient( $key );
				delete_site_option( $r->name );
			} elseif ( $this->is_site_transient( $r->name ) ) {
				delete_site_transient( $key );
				delete_option( $r->name );
			} else {
				delete_transient( $key );
				delete_option( $r->name );
			}

			$done++;
		}

		return $done;
	}

	/**
	 * Orphaned meta rows, found by LEFT JOIN. A multi-table DELETE cannot take a
	 * LIMIT, so each batch picks its meta_ids first and deletes exactly those.
	 */
	private function delete_orphan_meta( $type, $cutoff, $batch ) {
		global $wpdb;

		if ( 'post' === $type ) {
			$ids   = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT pm.meta_id FROM {$wpdb->postmeta} pm
					 LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					 WHERE p.ID IS NULL AND pm.meta_id <= %d
					 ORDER BY pm.meta_id ASC LIMIT %d",
					$cutoff,
					$batch
				)
			);
			$table = $wpdb->postmeta;
		} else {
			$ids   = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT cm.meta_id FROM {$wpdb->commentmeta} cm
					 LEFT JOIN {$wpdb->comments} c ON c.comment_ID = cm.comment_id
					 WHERE c.comment_ID IS NULL AND cm.meta_id <= %d
					 ORDER BY cm.meta_id ASC LIMIT %d",
					$cutoff,
					$batch
				)
			);
			$table = $wpdb->commentmeta;
		}

		$ids = array_map( 'intval', $ids );

		if ( ! $ids ) {
			return 0;
		}

		$deleted = $wpdb->query( "DELETE FROM {$table} WHERE meta_id IN (" . implode( ',', $ids ) . ')' );

		return false === $deleted ? 0 : (int) $deleted;
	}

	/**
	 * One table per request, so the progress moves one table at a time.
	 */
	private function step_tables( $index ) {
		global $wpdb;

		$eligible = array_values(
			array_filter(
				$this->table_status(),
				static function ( $t ) {
					return ! $t['skipped'];
				}
			)
		);

		if ( ! isset( $eligible[ $index ] ) ) {
			return [
				'processed' => 0,
				'remaining' => 0,
				'stalled'   => false,
			];
		}

		$table = $eligible[ $index ]['name'];
		$res   = $wpdb->query( "OPTIMIZE TABLE `{$table}`" );

		return [
			'processed' => false === $res ? 0 : 1,
			'remaining' => max( 0, count( $eligible ) - $index - 1 ),
			'stalled'   => false,
			'label'     => $table,
		];
	}
}
