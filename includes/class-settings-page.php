<?php
/**
 * Uber-styled admin settings page at Settings > Admin Speedboost.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPASB_Settings_Page {
	private $loader;
	private $hook_suffix = '';

	public function __construct( WPASB_Module_Loader $loader ) {
		$this->loader = $loader;
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function add_menu() {
		$this->hook_suffix = add_options_page(
			'Admin Speedboost',
			'Admin Speedboost',
			'manage_options',
			'wp-admin-speedboost',
			[ $this, 'render' ]
		);
	}

	public function register_settings() {
		register_setting(
			'wpasb_settings',
			WPASB_OPTION,
			[
				'sanitize_callback' => [ $this, 'sanitize' ],
				'default'           => $this->loader->get_defaults(),
			]
		);
	}

	public function sanitize( $input ) {
		$clean = [];
		foreach ( $this->loader->get_modules() as $slug => $module ) {
			$clean[ $slug ] = ! empty( $input[ $slug ] );
		}
		return $clean;
	}

	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}
		wp_enqueue_style(
			'wpasb-inter',
			'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;700&display=swap',
			[],
			null
		);
		wp_enqueue_style(
			'wpasb-admin',
			WPASB_URL . 'assets/admin.css',
			[ 'wpasb-inter' ],
			WPASB_VERSION
		);
	}

	public function render() {
		$modules = $this->loader->get_modules();
		$enabled = get_option( WPASB_OPTION, $this->loader->get_defaults() );
		?>
		<div class="wpasb-page">

			<div class="wpasb-hero">
				<div class="wpasb-hero-inner">
					<h1 class="wpasb-hero-title">ADMIN SPEEDBOOST</h1>
					<p class="wpasb-hero-tagline">Make wp-admin fast.</p>
				</div>
			</div>

			<?php settings_errors(); ?>

			<form method="post" action="options.php" class="wpasb-form">
				<?php settings_fields( 'wpasb_settings' ); ?>

				<h2 class="wpasb-section-title">Modules</h2>
				<p class="wpasb-section-desc">Toggle individual optimisations. Changes take effect on save.</p>

				<div class="wpasb-grid">
					<?php foreach ( $modules as $slug => $module ) : ?>
						<div class="wpasb-card">
							<div class="wpasb-card-header">
								<h3 class="wpasb-card-title"><?php echo esc_html( $module['name'] ); ?></h3>
								<label class="wpasb-toggle" for="wpasb-<?php echo esc_attr( $slug ); ?>">
									<input
										type="checkbox"
										id="wpasb-<?php echo esc_attr( $slug ); ?>"
										name="<?php echo esc_attr( WPASB_OPTION ); ?>[<?php echo esc_attr( $slug ); ?>]"
										value="1"
										<?php checked( ! empty( $enabled[ $slug ] ) ); ?>
									>
									<span class="wpasb-toggle-slider"></span>
								</label>
							</div>
							<p class="wpasb-card-desc"><?php echo esc_html( $module['description'] ); ?></p>
						</div>
					<?php endforeach; ?>
				</div>

				<div class="wpasb-actions">
					<button type="submit" class="wpasb-btn-primary">SAVE CHANGES</button>
				</div>
			</form>

			<div class="wpasb-dark-section">
				<div class="wpasb-dark-inner">
					<div class="wpasb-dark-text">
						<h2 class="wpasb-dark-title">DATABASE CLEANUP</h2>
						<p class="wpasb-dark-desc">Purge post revisions, expired transients, and optimise core tables. Safe to run monthly.</p>
					</div>
					<form method="post" class="wpasb-cleanup-form">
						<?php wp_nonce_field( 'wpasb_cleanup', 'wpasb_cleanup_nonce' ); ?>
						<button type="submit" name="wpasb_action" value="cleanup" class="wpasb-btn-light">RUN CLEANUP</button>
					</form>
				</div>
			</div>

			<div class="wpasb-info-section">
				<h2 class="wpasb-section-title">Server-Level Recommendations</h2>
				<p class="wpasb-section-desc">A plugin cannot set these — add them yourself.</p>

				<div class="wpasb-card">
					<h3 class="wpasb-card-title">wp-config.php constants</h3>
					<p class="wpasb-card-desc">Add above the <code>/* That's all, stop editing! */</code> line:</p>
					<pre class="wpasb-code">define( 'WP_POST_REVISIONS', 5 );
define( 'EMPTY_TRASH_DAYS', 7 );
define( 'AUTOSAVE_INTERVAL', 120 );
define( 'WP_CRON_LOCK_TIMEOUT', 60 );
define( 'DISALLOW_FILE_EDIT', true );
define( 'CONCATENATE_SCRIPTS', true );
define( 'COMPRESS_SCRIPTS', true );
define( 'COMPRESS_CSS', true );</pre>
				</div>

				<?php $this->render_opcache_card(); ?>
			</div>

		</div>
		<?php
	}

	private function render_opcache_card() {
		$status      = function_exists( 'opcache_get_status' ) ? @opcache_get_status( false ) : false;
		$opc_on      = $status && ! empty( $status['opcache_enabled'] );
		$jit_on      = $opc_on && ! empty( $status['jit']['enabled'] );
		$num_cached  = $opc_on ? (int) ( $status['opcache_statistics']['num_cached_scripts'] ?? 0 ) : 0;
		?>
		<div class="wpasb-card">
			<h3 class="wpasb-card-title">OPcache &amp; JIT (server-level)</h3>
			<p class="wpasb-card-desc">
				OPcache: <strong><?php echo $opc_on ? 'ACTIVE' : 'OFF'; ?></strong>
				<?php if ( $opc_on ) : ?>
					&nbsp;&middot;&nbsp; Cached scripts: <strong><?php echo (int) $num_cached; ?></strong>
				<?php endif; ?>
				&nbsp;&middot;&nbsp; JIT: <strong><?php echo $jit_on ? 'ACTIVE' : 'OFF'; ?></strong>
			</p>
			<?php if ( ! $opc_on || ! $jit_on ) : ?>
				<p class="wpasb-card-desc">Ask your host to add to <code>php.ini</code>:</p>
				<pre class="wpasb-code">zend_extension=opcache
opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=1
opcache.jit=tracing
opcache.jit_buffer_size=128M</pre>
			<?php endif; ?>
		</div>
		<?php
	}
}
