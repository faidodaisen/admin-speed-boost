<?php
/**
 * Handles the "Run Cleanup" button POST on the settings page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPASB_DB_Cleanup {
	public function __construct() {
		add_action( 'admin_init', [ $this, 'maybe_run' ] );
	}

	public function maybe_run() {
		if ( empty( $_POST['wpasb_action'] ) || $_POST['wpasb_action'] !== 'cleanup' ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! isset( $_POST['wpasb_cleanup_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['wpasb_cleanup_nonce'] ) ), 'wpasb_cleanup' ) ) {
			return;
		}

		$report = $this->run();

		add_action( 'admin_notices', function () use ( $report ) {
			?>
			<div class="notice notice-success is-dismissible">
				<p><strong>Cleanup complete:</strong>
					<?php echo (int) $report['revisions']; ?> revisions deleted,
					<?php echo (int) $report['transients']; ?> stale transients deleted,
					<?php echo (int) $report['tables']; ?> tables optimised.
				</p>
			</div>
			<?php
		} );
	}

	private function run() {
		global $wpdb;

		$revisions = (int) $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE post_type = 'revision'" );

		$transients = (int) $wpdb->query(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE '\\_transient\\_%'
			    OR option_name LIKE '\\_site\\_transient\\_%'"
		);

		$tables = [
			$wpdb->posts, $wpdb->postmeta, $wpdb->options, $wpdb->comments,
			$wpdb->commentmeta, $wpdb->terms, $wpdb->term_taxonomy,
			$wpdb->term_relationships, $wpdb->users, $wpdb->usermeta,
		];
		$optimised = 0;
		foreach ( $tables as $table ) {
			$res = $wpdb->query( "OPTIMIZE TABLE `{$table}`" );
			if ( $res !== false ) {
				$optimised++;
			}
		}

		return [
			'revisions'  => $revisions,
			'transients' => $transients,
			'tables'     => $optimised,
		];
	}
}
