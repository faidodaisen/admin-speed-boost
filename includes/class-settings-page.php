<?php
/**
 * Admin settings page at Settings > Admin Speedboost.
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
			__( 'Admin Speedboost', 'wp-admin-speedboost' ),
			__( 'Admin Speedboost', 'wp-admin-speedboost' ),
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
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize' ],
				'default'           => $this->loader->get_defaults(),
				'show_in_rest'      => false,
			]
		);

		register_setting(
			'wpasb_settings',
			WPASB_Hide_Login::OPT_SLUG,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_login_slug' ],
				'default'           => WPASB_Hide_Login::default_slug(),
				'show_in_rest'      => false,
			]
		);

		register_setting(
			'wpasb_settings',
			WPASB_Hide_Login::OPT_REDIRECT,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_redirect_slug' ],
				'default'           => WPASB_Hide_Login::default_redirect(),
				'show_in_rest'      => false,
			]
		);

		register_setting(
			'wpasb_settings',
			WPASB_Custom_Login::OPT_SPLASH,
			[
				'type'              => 'integer',
				'sanitize_callback' => [ $this, 'sanitize_splash_id' ],
				'default'           => 0,
				'show_in_rest'      => false,
			]
		);
	}

	/**
	 * The splash image is stored as a Media Library attachment ID. Reject
	 * anything that is not a real image attachment and fall back to 0 (bundled
	 * default).
	 */
	public function sanitize_splash_id( $input ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return (int) get_option( WPASB_Custom_Login::OPT_SPLASH, 0 );
		}

		$id = (int) $input;

		if ( $id <= 0 ) {
			return 0;
		}

		if ( 'attachment' !== get_post_type( $id ) || false === strpos( (string) get_post_mime_type( $id ), 'image/' ) ) {
			return 0;
		}

		return $id;
	}

	/**
	 * A bad login slug locks everyone out, so reject collisions and keep the old value.
	 */
	public function sanitize_login_slug( $input ) {
		$current = get_option( WPASB_Hide_Login::OPT_SLUG, WPASB_Hide_Login::default_slug() );

		if ( ! current_user_can( 'manage_options' ) ) {
			return $current;
		}

		$slug = sanitize_title_with_dashes( (string) $input );

		if ( '' === $slug ) {
			$slug = WPASB_Hide_Login::default_slug();
		}

		if ( false !== strpos( $slug, 'wp-login' ) || in_array( $slug, WPASB_Hide_Login::forbidden_slugs(), true ) ) {
			add_settings_error(
				WPASB_Hide_Login::OPT_SLUG,
				'wpasb_login_slug_invalid',
				__( 'That login slug is reserved by WordPress. The previous value was kept.', 'wp-admin-speedboost' )
			);

			return $current;
		}

		if ( $slug !== $current && ! $this->has_settings_error( 'wpasb_login_slug_updated' ) ) {
			add_settings_error(
				WPASB_Hide_Login::OPT_SLUG,
				'wpasb_login_slug_updated',
				sprintf(
					/* translators: %s: the new login URL */
					__( 'Your login page is now at %s. Bookmark it.', 'wp-admin-speedboost' ),
					esc_url( home_url( '/' ) . $slug )
				),
				'success'
			);
		}

		return $slug;
	}

	/**
	 * WordPress runs a setting's sanitize_callback more than once per request
	 * (register_setting + the options.php update), so the same notice can be
	 * queued twice. Bail if this code is already registered.
	 */
	private function has_settings_error( $code ) {
		global $wp_settings_errors;

		if ( empty( $wp_settings_errors ) || ! is_array( $wp_settings_errors ) ) {
			return false;
		}

		foreach ( $wp_settings_errors as $error ) {
			if ( isset( $error['code'] ) && $error['code'] === $code ) {
				return true;
			}
		}

		return false;
	}

	public function sanitize_redirect_slug( $input ) {
		$current = get_option( WPASB_Hide_Login::OPT_REDIRECT, WPASB_Hide_Login::default_redirect() );

		if ( ! current_user_can( 'manage_options' ) ) {
			return $current;
		}

		$slug = sanitize_title_with_dashes( (string) $input );

		return '' !== $slug ? $slug : WPASB_Hide_Login::default_redirect();
	}

	/**
	 * Whitelist sanitiser: only known module slugs survive, always as booleans.
	 */
	public function sanitize( $input ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->loader->get_settings();
		}

		if ( ! is_array( $input ) ) {
			$input = [];
		}

		$clean = [];
		foreach ( $this->loader->get_modules() as $slug => $module ) {
			$clean[ $slug ] = ! empty( $input[ $slug ] );
		}

		return $clean;
	}

	public function enqueue_assets( $hook ) {
		if ( ! $this->hook_suffix || $hook !== $this->hook_suffix ) {
			return;
		}

		// No remote font. Loading Google Fonts from admin violates the plugin
		// directory guideline on external services and leaks admin IPs.
		wp_enqueue_style(
			'wpasb-admin',
			WPASB_URL . 'assets/admin.css',
			[],
			WPASB_VERSION
		);

		// Media picker for the Custom Login splash image.
		wp_enqueue_media();
		wp_enqueue_script(
			'wpasb-admin',
			WPASB_URL . 'assets/admin.js',
			[ 'jquery' ],
			WPASB_VERSION,
			true
		);
		wp_localize_script(
			'wpasb-admin',
			'wpasbLogin',
			[
				'frameTitle'  => __( 'Select login splash image', 'wp-admin-speedboost' ),
				'frameButton' => __( 'Use this image', 'wp-admin-speedboost' ),
				'defaultUrl'  => esc_url_raw( WPASB_Custom_Login::default_splash_url() ),
			]
		);
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-admin-speedboost' ) );
		}

		$modules = $this->loader->get_modules();
		$enabled = $this->loader->get_settings();
		?>
		<div class="wpasb-page">

			<div class="wpasb-hero">
				<div class="wpasb-hero-inner">
					<h1 class="wpasb-hero-title"><?php esc_html_e( 'ADMIN SPEEDBOOST', 'wp-admin-speedboost' ); ?></h1>
					<p class="wpasb-hero-tagline"><?php esc_html_e( 'Make wp-admin fast.', 'wp-admin-speedboost' ); ?></p>
				</div>
			</div>

			<?php
			// WordPress already prints settings errors automatically on pages
			// under Settings, so calling settings_errors() here would duplicate
			// every notice.
			?>

			<?php WPASB_Updater::render_status_panel(); ?>

			<form method="post" action="options.php" class="wpasb-form">
				<?php settings_fields( 'wpasb_settings' ); ?>

				<h2 class="wpasb-section-title"><?php esc_html_e( 'Modules', 'wp-admin-speedboost' ); ?></h2>
				<p class="wpasb-section-desc"><?php esc_html_e( 'Toggle individual optimisations. Changes take effect on save.', 'wp-admin-speedboost' ); ?></p>

				<div class="wpasb-grid">
					<?php foreach ( $modules as $slug => $module ) : ?>
						<div class="wpasb-card">
							<div class="wpasb-card-header">
								<h3 class="wpasb-card-title"><?php echo esc_html( $module['name'] ); ?></h3>
								<label class="wpasb-toggle" for="wpasb-<?php echo esc_attr( $slug ); ?>">
									<span class="screen-reader-text"><?php echo esc_html( $module['name'] ); ?></span>
									<input
										type="checkbox"
										id="wpasb-<?php echo esc_attr( $slug ); ?>"
										name="<?php echo esc_attr( WPASB_OPTION ); ?>[<?php echo esc_attr( $slug ); ?>]"
										value="1"
										<?php checked( ! empty( $enabled[ $slug ] ) ); ?>
									>
									<span class="wpasb-toggle-slider" aria-hidden="true"></span>
								</label>
							</div>
							<p class="wpasb-card-desc"><?php echo esc_html( $module['description'] ); ?></p>
						</div>
					<?php endforeach; ?>
				</div>

				<?php $this->render_login_fields( $enabled ); ?>

				<?php $this->render_custom_login_fields( $enabled ); ?>

				<div class="wpasb-actions">
					<button type="submit" class="wpasb-btn-primary"><?php esc_html_e( 'SAVE CHANGES', 'wp-admin-speedboost' ); ?></button>
				</div>
			</form>

			<div class="wpasb-dark-section">
				<div class="wpasb-dark-inner">
					<div class="wpasb-dark-text">
						<h2 class="wpasb-dark-title"><?php esc_html_e( 'DATABASE CLEANUP', 'wp-admin-speedboost' ); ?></h2>
						<p class="wpasb-dark-desc"><?php esc_html_e( 'Permanently deletes all post revisions and expired transients, removes orphaned meta rows, and optimises non-InnoDB core tables. This cannot be undone. Back up your database first.', 'wp-admin-speedboost' ); ?></p>
					</div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpasb-cleanup-form">
						<?php wp_nonce_field( 'wpasb_cleanup', 'wpasb_cleanup_nonce' ); ?>
						<input type="hidden" name="action" value="wpasb_cleanup">
						<button
							type="submit"
							class="wpasb-btn-light"
							onclick="return confirm('<?php echo esc_js( __( 'This permanently deletes all post revisions and expired transients. Continue?', 'wp-admin-speedboost' ) ); ?>');"
						><?php esc_html_e( 'RUN CLEANUP', 'wp-admin-speedboost' ); ?></button>
					</form>
				</div>
			</div>

			<div class="wpasb-info-section">
				<h2 class="wpasb-section-title"><?php esc_html_e( 'Server-Level Recommendations', 'wp-admin-speedboost' ); ?></h2>
				<p class="wpasb-section-desc"><?php esc_html_e( 'A plugin cannot set these. Add them yourself.', 'wp-admin-speedboost' ); ?></p>

				<div class="wpasb-card">
					<h3 class="wpasb-card-title"><?php esc_html_e( 'wp-config.php constants', 'wp-admin-speedboost' ); ?></h3>
					<p class="wpasb-card-desc">
						<?php
						printf(
							/* translators: %s: the wp-config.php marker comment */
							esc_html__( 'Add above the %s line:', 'wp-admin-speedboost' ),
							'<code>' . esc_html( "/* That's all, stop editing! */" ) . '</code>'
						);
						?>
					</p>
					<pre class="wpasb-code"><?php echo esc_html(
						"define( 'WP_POST_REVISIONS', 5 );\n"
						. "define( 'EMPTY_TRASH_DAYS', 7 );\n"
						. "define( 'AUTOSAVE_INTERVAL', 120 );\n"
						. "define( 'WP_CRON_LOCK_TIMEOUT', 60 );\n"
						. "define( 'DISALLOW_FILE_EDIT', true );\n"
						. "define( 'CONCATENATE_SCRIPTS', true );\n"
						. "define( 'COMPRESS_SCRIPTS', true );\n"
						. "define( 'COMPRESS_CSS', true );"
					); ?></pre>
				</div>

				<?php $this->render_opcache_card(); ?>
			</div>

		</div>
		<?php
	}

	/**
	 * Login slug fields. Rendered inside the module form so one Save covers both.
	 */
	private function render_login_fields( array $enabled ) {
		$home      = trailingslashit( home_url() );
		$permalink = (bool) get_option( 'permalink_structure' );
		$slug      = get_option( WPASB_Hide_Login::OPT_SLUG, WPASB_Hide_Login::default_slug() );
		$redirect  = get_option( WPASB_Hide_Login::OPT_REDIRECT, WPASB_Hide_Login::default_redirect() );
		$active    = ! empty( $enabled['hide-login'] );
		?>
		<div class="wpasb-card wpasb-login-card">
			<h3 class="wpasb-card-title"><?php esc_html_e( 'Login URL', 'wp-admin-speedboost' ); ?></h3>
			<p class="wpasb-card-desc">
				<?php if ( $active ) : ?>
					<?php
					printf(
						/* translators: %s: current login URL */
						esc_html__( 'Active. Your login page is %s. Bookmark it before changing anything.', 'wp-admin-speedboost' ),
						'<strong><code>' . esc_html( $home . ( $permalink ? '' : '?' ) . $slug ) . '</code></strong>'
					);
					?>
				<?php else : ?>
					<?php esc_html_e( 'These take effect once the Hide Login URL module above is switched on.', 'wp-admin-speedboost' ); ?>
				<?php endif; ?>
			</p>

			<p class="wpasb-field">
				<label for="wpasb-login-slug"><?php esc_html_e( 'Login slug', 'wp-admin-speedboost' ); ?></label><br>
				<code><?php echo esc_html( $home . ( $permalink ? '' : '?' ) ); ?></code>
				<input
					type="text"
					id="wpasb-login-slug"
					name="<?php echo esc_attr( WPASB_Hide_Login::OPT_SLUG ); ?>"
					value="<?php echo esc_attr( $slug ); ?>"
					class="regular-text"
				>
			</p>

			<p class="wpasb-field">
				<label for="wpasb-login-redirect"><?php esc_html_e( 'Redirect slug', 'wp-admin-speedboost' ); ?></label><br>
				<code><?php echo esc_html( $home . ( $permalink ? '' : '?' ) ); ?></code>
				<input
					type="text"
					id="wpasb-login-redirect"
					name="<?php echo esc_attr( WPASB_Hide_Login::OPT_REDIRECT ); ?>"
					value="<?php echo esc_attr( $redirect ); ?>"
					class="regular-text"
				>
			</p>
			<p class="wpasb-card-desc"><?php esc_html_e( 'Where logged-out visitors land when they hit wp-login.php or wp-admin. Leave as 404 unless you have a real page for it.', 'wp-admin-speedboost' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Splash image picker for the Custom Login Page module. Rendered inside the
	 * module form so one Save covers it too.
	 */
	private function render_custom_login_fields( array $enabled ) {
		$splash_id = (int) get_option( WPASB_Custom_Login::OPT_SPLASH, 0 );
		$active    = ! empty( $enabled['custom-login'] );

		$preview_url = '';
		if ( $splash_id > 0 ) {
			$preview_url = wp_get_attachment_image_url( $splash_id, 'medium' );
		}

		// The stored attachment may have been deleted from the Media Library.
		// Drop back to 0 so the hidden field and preview both reflect reality
		// and a Save does not re-persist a dead ID.
		if ( $splash_id > 0 && ! $preview_url ) {
			$splash_id = 0;
		}

		$is_default = ! $preview_url;
		if ( $is_default ) {
			$preview_url = WPASB_Custom_Login::default_splash_url();
		}
		?>
		<div class="wpasb-card wpasb-login-card">
			<h3 class="wpasb-card-title"><?php esc_html_e( 'Login Splash Image', 'wp-admin-speedboost' ); ?></h3>
			<p class="wpasb-card-desc">
				<?php if ( $active ) : ?>
					<?php esc_html_e( 'Active. This image fills the right side of your login page. Pick any image from the Media Library, or leave it on the bundled default.', 'wp-admin-speedboost' ); ?>
				<?php else : ?>
					<?php esc_html_e( 'This takes effect once the Custom Login Page module above is switched on.', 'wp-admin-speedboost' ); ?>
				<?php endif; ?>
			</p>

			<div class="wpasb-splash-picker">
				<div class="wpasb-splash-preview">
					<img
						id="wpasb-splash-preview-img"
						src="<?php echo esc_url( $preview_url ); ?>"
						alt="<?php esc_attr_e( 'Login splash preview', 'wp-admin-speedboost' ); ?>"
					>
					<span
						id="wpasb-splash-default-note"
						class="wpasb-splash-note"
						<?php echo $is_default ? '' : 'style="display:none;"'; ?>
					><?php esc_html_e( 'Bundled default image', 'wp-admin-speedboost' ); ?></span>
				</div>

				<input
					type="hidden"
					id="wpasb-splash-id"
					name="<?php echo esc_attr( WPASB_Custom_Login::OPT_SPLASH ); ?>"
					value="<?php echo esc_attr( $splash_id ); ?>"
				>

				<div class="wpasb-splash-buttons">
					<button type="button" class="button" id="wpasb-splash-choose"><?php esc_html_e( 'Choose image', 'wp-admin-speedboost' ); ?></button>
					<button
						type="button"
						class="button-link wpasb-splash-reset"
						id="wpasb-splash-reset"
						<?php echo $splash_id > 0 ? '' : 'style="display:none;"'; ?>
					><?php esc_html_e( 'Reset to default', 'wp-admin-speedboost' ); ?></button>
				</div>
			</div>

			<p class="wpasb-card-desc"><?php esc_html_e( 'The site logo shown next to the form is taken automatically from your theme logo or site icon. Set it under Appearance > Customize.', 'wp-admin-speedboost' ); ?></p>
		</div>
		<?php
	}

	private function render_opcache_card() {
		$status = function_exists( 'opcache_get_status' ) ? @opcache_get_status( false ) : false;

		$opc_on     = is_array( $status ) && ! empty( $status['opcache_enabled'] );
		$jit_on     = $opc_on && ! empty( $status['jit']['enabled'] );
		$num_cached = $opc_on ? (int) ( $status['opcache_statistics']['num_cached_scripts'] ?? 0 ) : 0;

		$on  = __( 'ACTIVE', 'wp-admin-speedboost' );
		$off = __( 'OFF', 'wp-admin-speedboost' );
		?>
		<div class="wpasb-card">
			<h3 class="wpasb-card-title"><?php esc_html_e( 'OPcache & JIT (server-level)', 'wp-admin-speedboost' ); ?></h3>
			<p class="wpasb-card-desc">
				<?php esc_html_e( 'OPcache:', 'wp-admin-speedboost' ); ?>
				<strong><?php echo esc_html( $opc_on ? $on : $off ); ?></strong>
				<?php if ( $opc_on ) : ?>
					&nbsp;&middot;&nbsp;
					<?php esc_html_e( 'Cached scripts:', 'wp-admin-speedboost' ); ?>
					<strong><?php echo esc_html( number_format_i18n( $num_cached ) ); ?></strong>
				<?php endif; ?>
				&nbsp;&middot;&nbsp;
				<?php esc_html_e( 'JIT:', 'wp-admin-speedboost' ); ?>
				<strong><?php echo esc_html( $jit_on ? $on : $off ); ?></strong>
			</p>
			<?php if ( ! $opc_on || ! $jit_on ) : ?>
				<p class="wpasb-card-desc">
					<?php
					printf(
						/* translators: %s: php.ini filename */
						esc_html__( 'Ask your host to add to %s:', 'wp-admin-speedboost' ),
						'<code>php.ini</code>'
					);
					?>
				</p>
				<pre class="wpasb-code"><?php echo esc_html(
					"zend_extension=opcache\n"
					. "opcache.enable=1\n"
					. "opcache.memory_consumption=256\n"
					. "opcache.interned_strings_buffer=16\n"
					. "opcache.max_accelerated_files=20000\n"
					. "opcache.validate_timestamps=1\n"
					. "opcache.jit=tracing\n"
					. "opcache.jit_buffer_size=128M"
				); ?></pre>
			<?php endif; ?>
		</div>
		<?php
	}
}
