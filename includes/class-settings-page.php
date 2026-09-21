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

	/**
	 * Module groups. Not every module is a speed tweak — several are security,
	 * workflow or noise-reduction features — so the page groups them by what
	 * they actually do instead of presenting one undifferentiated wall.
	 *
	 * @return array<string, array{title:string, description:string, slugs:string[]}>
	 */
	private function module_groups() {
		return [
			'performance' => [
				'title'       => __( 'Performance', 'wp-admin-speedboost' ),
				'description' => __( 'Reduce background requests and unnecessary assets.', 'wp-admin-speedboost' ),
				'slugs'       => [ 'heartbeat', 'emoji-oembed', 'jquery-migrate', 'lazy-gravatars' ],
			],
			'noise'       => [
				'title'       => __( 'Less admin noise', 'wp-admin-speedboost' ),
				'description' => __( 'Keep dashboards, notices and admin tools focused.', 'wp-admin-speedboost' ),
				'slugs'       => [ 'dashboard-widgets', 'site-health-prune', 'admin-bar-prune', 'vendor-notices-hide', 'imagick-nag-silence' ],
			],
			'security'    => [
				'title'       => __( 'Security & access', 'wp-admin-speedboost' ),
				'description' => __( 'Control login access and exposed endpoints.', 'wp-admin-speedboost' ),
				'slugs'       => [ 'rest-lockdown', 'disable-app-passwords', 'disable-xmlrpc', 'hide-login' ],
			],
			'editing'     => [
				'title'       => __( 'Editing & login', 'wp-admin-speedboost' ),
				'description' => __( 'Choose how editing and the login screen work.', 'wp-admin-speedboost' ),
				'slugs'       => [ 'disable-comments', 'classic-editor', 'duplicate-post', 'custom-login' ],
			],
		];
	}

	/**
	 * Splits an existing module description into a lead sentence and the rest.
	 * The lead is always visible; the remainder moves into a Details disclosure
	 * so cards stop varying wildly in height. No copy is rewritten or dropped.
	 *
	 * @return array{0:string, 1:string}
	 */
	private function split_description( $description ) {
		$description = trim( (string) $description );

		if ( ! preg_match( '/^(.+?[.!?])(\s+)(\S.*)$/su', $description, $m ) ) {
			return [ $description, '' ];
		}

		return [ trim( $m[1] ), trim( $m[3] ) ];
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

		wp_localize_script(
			'wpasb-admin',
			'wpasbData',
			[
				'ajaxUrl' => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
				'nonce'   => wp_create_nonce( 'wpasb_cleanup' ),
				'homeUrl' => trailingslashit( home_url() ) . ( get_option( 'permalink_structure' ) ? '' : '?' ),
				'i18n'    => [
					'saved'          => __( 'Saved configuration', 'wp-admin-speedboost' ),
					'unsaved'        => __( 'Unsaved changes', 'wp-admin-speedboost' ),
					/* translators: %1$d: selected count, %2$d: total modules */
					'selectedCount'  => __( '%1$d of %2$d selected', 'wp-admin-speedboost' ),
					/* translators: %1$d: enabled count, %2$d: total modules */
					'enabledCount'   => __( '%1$d of %2$d modules enabled', 'wp-admin-speedboost' ),
					'willEnable'     => __( 'Will enable when saved', 'wp-admin-speedboost' ),
					'willDisable'    => __( 'Will disable when saved', 'wp-admin-speedboost' ),
					'enabled'        => __( 'Enabled', 'wp-admin-speedboost' ),
					'disabled'       => __( 'Disabled', 'wp-admin-speedboost' ),
					'settingsMoved'  => __( 'Settings changed', 'wp-admin-speedboost' ),
					/* translators: %1$d: matching modules, %2$d: total modules */
					'searchStatus'   => __( 'Showing %1$d of %2$d modules.', 'wp-admin-speedboost' ),
					'saving'         => __( 'Saving…', 'wp-admin-speedboost' ),
					'cleaning'       => __( 'Cleaning database…', 'wp-admin-speedboost' ),
					'cleanStarted'   => __( 'Database cleanup started.', 'wp-admin-speedboost' ),
					'cleanSlow'      => __( 'Still working. Larger databases can take longer.', 'wp-admin-speedboost' ),
					'cleanDone'      => __( 'Cleanup complete', 'wp-admin-speedboost' ),
					'cleanFailed'    => __( 'Cleanup could not finish', 'wp-admin-speedboost' ),
					'cleanUnknown'   => __( 'Cleanup status unknown', 'wp-admin-speedboost' ),
					'cleanLost'      => __( 'The connection ended before a result arrived. The cleanup may still be running. Reload the page in a minute before trying again.', 'wp-admin-speedboost' ),
					'cleanGeneric'   => __( 'The cleanup could not be completed. Nothing further was deleted.', 'wp-admin-speedboost' ),
					'notAvailable'   => __( 'Not available', 'wp-admin-speedboost' ),
					'copied'         => __( 'Copied', 'wp-admin-speedboost' ),
					'copyCode'       => __( 'Copy code', 'wp-admin-speedboost' ),
					'copyFailed'     => __( 'Could not copy automatically. Select and copy the code below.', 'wp-admin-speedboost' ),
					'mediaFailed'    => __( 'The media library could not open. Reload this page and try again.', 'wp-admin-speedboost' ),
					'customImage'    => __( 'Custom image', 'wp-admin-speedboost' ),
					'defaultImage'   => __( 'Bundled default image', 'wp-admin-speedboost' ),
					/* translators: %s: the login URL that will apply after saving */
					'loginPreviewNew' => __( 'Login URL after saving: %s', 'wp-admin-speedboost' ),
					/* translators: %s: the currently active login URL */
					'loginPreviewNow' => __( 'Current login URL: %s', 'wp-admin-speedboost' ),
				],
			]
		);
	}

	/**
	 * Inline monochrome icons. Kept here so no external asset is ever fetched.
	 */
	private function icon( $name ) {
		$paths = [
			'check'   => '<path d="M3.5 8.5 6.5 11.5 12.5 4.5"/>',
			'circle'  => '<circle cx="8" cy="8" r="4.75"/>',
			'warn'    => '<path d="M8 2.75 14.5 13.5H1.5L8 2.75Z"/><path d="M8 6.75v3"/><path d="M8 11.75h.01"/>',
			'search'  => '<circle cx="7.25" cy="7.25" r="4.5"/><path d="M10.6 10.6 13.5 13.5"/>',
			'clear'   => '<path d="M4 4 12 12"/><path d="M12 4 4 12"/>',
			'copy'    => '<rect x="5.5" y="5.5" width="8" height="8" rx="1.5"/><path d="M10.5 3.5h-6a1 1 0 0 0-1 1v6"/>',
			'trash'   => '<path d="M3 4.5h10"/><path d="M6.5 4.5V3h3v1.5"/><path d="M4.5 4.5 5 13.5h6l.5-9"/>',
			'refresh' => '<path d="M13 8a5 5 0 1 1-1.6-3.65"/><path d="M13.25 2v3h-3"/>',
		];

		if ( empty( $paths[ $name ] ) ) {
			return '';
		}

		return '<svg class="wpasb-icon wpasb-icon--' . esc_attr( $name ) . '" viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-admin-speedboost' ) );
		}

		$modules = $this->loader->get_modules();
		$enabled = $this->loader->get_settings();
		$groups  = $this->module_groups();

		$total      = count( $modules );
		$active     = 0;
		$grouped    = [];
		$claimed    = [];
		foreach ( $modules as $slug => $module ) {
			if ( ! empty( $enabled[ $slug ] ) ) {
				$active++;
			}
		}
		foreach ( $groups as $key => $group ) {
			foreach ( $group['slugs'] as $slug ) {
				if ( isset( $modules[ $slug ] ) ) {
					$grouped[ $key ][ $slug ] = $modules[ $slug ];
					$claimed[ $slug ]         = true;
				}
			}
		}
		// Any module added later without a group still has to appear somewhere.
		foreach ( $modules as $slug => $module ) {
			if ( empty( $claimed[ $slug ] ) ) {
				$grouped['performance'][ $slug ] = $module;
			}
		}
		?>
		<div class="wpasb-page">
			<div class="wpasb-shell">

				<header class="wpasb-header">
					<div class="wpasb-header-identity">
						<h1 class="wpasb-header-title"><?php esc_html_e( 'Admin Speedboost', 'wp-admin-speedboost' ); ?></h1>
						<p class="wpasb-header-subtitle"><?php esc_html_e( 'A faster, quieter WordPress admin.', 'wp-admin-speedboost' ); ?></p>
					</div>
					<div class="wpasb-header-summary" id="wpasb-summary">
						<p class="wpasb-summary-count" id="wpasb-summary-count"
							data-total="<?php echo esc_attr( $total ); ?>">
							<?php
							printf(
								/* translators: %1$d: enabled modules, %2$d: total modules */
								esc_html__( '%1$d of %2$d modules enabled', 'wp-admin-speedboost' ),
								(int) $active,
								(int) $total
							);
							?>
						</p>
						<p class="wpasb-summary-state" id="wpasb-summary-state"><?php esc_html_e( 'Saved configuration', 'wp-admin-speedboost' ); ?></p>
					</div>
				</header>

				<?php WPASB_Updater::render_status_panel(); ?>

				<form method="post" action="options.php" class="wpasb-form" id="wpasb-settings-form">
					<?php settings_fields( 'wpasb_settings' ); ?>

					<div class="wpasb-module-toolbar">
						<div class="wpasb-module-toolbar-text">
							<h2 class="wpasb-section-title"><?php esc_html_e( 'Modules', 'wp-admin-speedboost' ); ?></h2>
							<p class="wpasb-section-desc"><?php esc_html_e( 'Every switch below takes effect only after you save.', 'wp-admin-speedboost' ); ?></p>
						</div>
						<div class="wpasb-module-search">
							<label class="wpasb-search-label" for="wpasb-search"><?php esc_html_e( 'Find a module', 'wp-admin-speedboost' ); ?></label>
							<div class="wpasb-search-control">
								<?php echo $this->icon( 'search' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<input type="search" id="wpasb-search" class="wpasb-search-input" autocomplete="off"
									placeholder="<?php esc_attr_e( 'Search modules…', 'wp-admin-speedboost' ); ?>">
								<button type="button" class="wpasb-search-clear" id="wpasb-search-clear" hidden>
									<span class="screen-reader-text"><?php esc_html_e( 'Clear search', 'wp-admin-speedboost' ); ?></span>
									<?php echo $this->icon( 'clear' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								</button>
							</div>
							<p class="wpasb-search-status screen-reader-text" id="wpasb-search-status" role="status" aria-live="polite"></p>
						</div>
					</div>

					<p class="wpasb-search-empty" id="wpasb-search-empty" hidden>
						<?php esc_html_e( 'No modules match your search.', 'wp-admin-speedboost' ); ?>
						<button type="button" class="wpasb-link-button" data-wpasb-clear-search><?php esc_html_e( 'Clear search', 'wp-admin-speedboost' ); ?></button>
					</p>

					<?php foreach ( $groups as $key => $group ) : ?>
						<?php
						if ( empty( $grouped[ $key ] ) ) {
							continue;
						}
						$group_total  = count( $grouped[ $key ] );
						$group_active = 0;
						foreach ( $grouped[ $key ] as $slug => $module ) {
							if ( ! empty( $enabled[ $slug ] ) ) {
								$group_active++;
							}
						}
						?>
						<section class="wpasb-module-group" data-group="<?php echo esc_attr( $key ); ?>">
							<div class="wpasb-group-header">
								<h3 class="wpasb-group-title"><?php echo esc_html( $group['title'] ); ?></h3>
								<p class="wpasb-group-count" data-group-total="<?php echo esc_attr( $group_total ); ?>">
									<?php
									printf(
										/* translators: %1$d: selected modules, %2$d: modules in this group */
										esc_html__( '%1$d of %2$d selected', 'wp-admin-speedboost' ),
										(int) $group_active,
										(int) $group_total
									);
									?>
								</p>
								<p class="wpasb-group-description"><?php echo esc_html( $group['description'] ); ?></p>
							</div>

							<div class="wpasb-module-grid">
								<?php foreach ( $grouped[ $key ] as $slug => $module ) : ?>
									<?php
									list( $lead, $rest ) = $this->split_description( $module['description'] );
									$is_on = ! empty( $enabled[ $slug ] );
									$wide  = in_array( $slug, [ 'hide-login', 'custom-login' ], true );
									?>
									<div class="wpasb-module-card<?php echo $wide ? ' wpasb-module-card--wide' : ''; ?>"
										data-slug="<?php echo esc_attr( $slug ); ?>"
										data-initial="<?php echo $is_on ? '1' : '0'; ?>"
										data-group-name="<?php echo esc_attr( $group['title'] ); ?>">
										<div class="wpasb-module-heading">
											<h4 class="wpasb-module-title"><?php echo esc_html( $module['name'] ); ?></h4>
											<label class="wpasb-toggle" for="wpasb-<?php echo esc_attr( $slug ); ?>">
												<span class="screen-reader-text">
													<?php
													printf(
														/* translators: %s: module name */
														esc_html__( 'Enable %s', 'wp-admin-speedboost' ),
														esc_html( $module['name'] )
													);
													?>
												</span>
												<input
													type="checkbox"
													id="wpasb-<?php echo esc_attr( $slug ); ?>"
													class="wpasb-module-input"
													name="<?php echo esc_attr( WPASB_OPTION ); ?>[<?php echo esc_attr( $slug ); ?>]"
													value="1"
													<?php echo $wide ? 'aria-controls="wpasb-panel-' . esc_attr( $slug ) . '"' : ''; ?>
													<?php checked( $is_on ); ?>
												>
												<span class="wpasb-toggle-slider" aria-hidden="true"></span>
											</label>
										</div>

										<p class="wpasb-module-summary-text"><?php echo esc_html( $lead ); ?></p>

										<?php if ( '' !== $rest ) : ?>
											<details class="wpasb-module-details">
												<summary><?php esc_html_e( 'Details', 'wp-admin-speedboost' ); ?></summary>
												<p><?php echo esc_html( $rest ); ?></p>
											</details>
										<?php endif; ?>

										<?php if ( 'hide-login' === $slug ) : ?>
											<?php $this->render_login_fields( $is_on ); ?>
										<?php elseif ( 'custom-login' === $slug ) : ?>
											<?php $this->render_custom_login_fields( $is_on ); ?>
										<?php endif; ?>

										<p class="wpasb-module-state" data-state="<?php echo $is_on ? 'on' : 'off'; ?>">
											<?php echo $this->icon( $is_on ? 'check' : 'circle' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
											<span class="wpasb-module-state-text"><?php echo $is_on ? esc_html__( 'Enabled', 'wp-admin-speedboost' ) : esc_html__( 'Disabled', 'wp-admin-speedboost' ); ?></span>
										</p>
									</div>
								<?php endforeach; ?>
							</div>
						</section>
					<?php endforeach; ?>

					<div class="wpasb-savebar" id="wpasb-savebar">
						<p class="wpasb-savebar-status"><?php esc_html_e( 'Unsaved changes', 'wp-admin-speedboost' ); ?></p>
						<div class="wpasb-savebar-actions">
							<button type="button" class="wpasb-btn wpasb-btn--ghost" id="wpasb-discard"><?php esc_html_e( 'Discard changes', 'wp-admin-speedboost' ); ?></button>
							<button type="submit" class="wpasb-btn wpasb-btn--primary" id="wpasb-save"><?php esc_html_e( 'Save changes', 'wp-admin-speedboost' ); ?></button>
						</div>
					</div>
				</form>

				<?php $this->render_cleanup_section(); ?>

				<section class="wpasb-server">
					<h2 class="wpasb-section-title"><?php esc_html_e( 'Server recommendations', 'wp-admin-speedboost' ); ?></h2>
					<p class="wpasb-section-desc"><?php esc_html_e( 'These are managed on your server, not by the module switches above.', 'wp-admin-speedboost' ); ?></p>

					<div class="wpasb-server-grid">
						<div class="wpasb-panel wpasb-config-card">
							<h3 class="wpasb-panel-title"><?php esc_html_e( 'WordPress configuration', 'wp-admin-speedboost' ); ?></h3>
							<p class="wpasb-panel-desc">
								<?php
								printf(
									/* translators: %s: the wp-config.php marker comment */
									esc_html__( 'A recommendation to review, not something this plugin applies. Add it above the %s line.', 'wp-admin-speedboost' ),
									'<code>' . esc_html( "/* That's all, stop editing! */" ) . '</code>'
								);
								?>
							</p>
							<div class="wpasb-code-toolbar">
								<span class="wpasb-code-filename">wp-config.php</span>
								<button type="button" class="wpasb-btn wpasb-btn--quiet wpasb-copy" data-copy-target="wpasb-config-code">
									<?php echo $this->icon( 'copy' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
									<span class="wpasb-copy-label"><?php esc_html_e( 'Copy code', 'wp-admin-speedboost' ); ?></span>
								</button>
							</div>
							<pre class="wpasb-code" id="wpasb-config-code"><?php echo esc_html(
								"define( 'WP_POST_REVISIONS', 5 );\n"
								. "define( 'EMPTY_TRASH_DAYS', 7 );\n"
								. "define( 'AUTOSAVE_INTERVAL', 120 );\n"
								. "define( 'WP_CRON_LOCK_TIMEOUT', 60 );\n"
								. "define( 'DISALLOW_FILE_EDIT', true );\n"
								. "define( 'CONCATENATE_SCRIPTS', true );\n"
								. "define( 'COMPRESS_SCRIPTS', true );\n"
								. "define( 'COMPRESS_CSS', true );"
							); ?></pre>
							<p class="wpasb-copy-status screen-reader-text" role="status" aria-live="polite"></p>
						</div>

						<?php $this->render_opcache_card(); ?>
					</div>
				</section>

			</div>
		</div>
		<?php
	}

	/**
	 * Database cleanup. The whole flow lives outside the settings form so a
	 * cleanup can never submit or discard unsaved module edits.
	 */
	private function render_cleanup_section() {
		?>
		<section class="wpasb-cleanup" id="wpasb-cleanup">
			<div class="wpasb-cleanup-intro">
				<div class="wpasb-cleanup-text">
					<h2 class="wpasb-cleanup-title"><?php esc_html_e( 'Database cleanup', 'wp-admin-speedboost' ); ?></h2>
					<p class="wpasb-cleanup-desc"><?php esc_html_e( 'Delete post revisions and expired transients, remove orphaned metadata, then optimise eligible tables.', 'wp-admin-speedboost' ); ?></p>
					<p class="wpasb-cleanup-warning">
						<?php echo $this->icon( 'warn' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<span><?php esc_html_e( 'Cleanup permanently deletes data. Make a current backup before continuing.', 'wp-admin-speedboost' ); ?></span>
					</p>
				</div>
				<div class="wpasb-cleanup-launch">
					<button type="button" class="wpasb-btn wpasb-btn--light" id="wpasb-cleanup-review"><?php esc_html_e( 'Review cleanup', 'wp-admin-speedboost' ); ?></button>
				</div>
			</div>

			<div class="wpasb-cleanup-confirm" id="wpasb-cleanup-confirm" hidden>
				<h3 class="wpasb-cleanup-confirm-title" id="wpasb-cleanup-confirm-title" tabindex="-1"><?php esc_html_e( 'Run database cleanup?', 'wp-admin-speedboost' ); ?></h3>
				<ul class="wpasb-cleanup-list">
					<li><?php esc_html_e( 'Post revisions will be deleted.', 'wp-admin-speedboost' ); ?></li>
					<li><?php esc_html_e( 'Expired transients will be deleted.', 'wp-admin-speedboost' ); ?></li>
					<li><?php esc_html_e( 'Orphaned metadata will be removed.', 'wp-admin-speedboost' ); ?></li>
					<li><?php esc_html_e( 'Eligible tables will be optimised.', 'wp-admin-speedboost' ); ?></li>
				</ul>
				<p class="wpasb-cleanup-note"><?php esc_html_e( 'This does not save your module settings. Anything unsaved above stays unsaved.', 'wp-admin-speedboost' ); ?></p>
				<div class="wpasb-cleanup-actions" id="wpasb-cleanup-actions">
					<button type="button" class="wpasb-btn wpasb-btn--outline-dark" id="wpasb-cleanup-cancel"><?php esc_html_e( 'Cancel', 'wp-admin-speedboost' ); ?></button>
					<button type="button" class="wpasb-btn wpasb-btn--light" id="wpasb-cleanup-run"><?php esc_html_e( 'Run cleanup', 'wp-admin-speedboost' ); ?></button>
				</div>
				<p class="wpasb-cleanup-running" id="wpasb-cleanup-running" hidden>
					<span class="wpasb-spinner" aria-hidden="true"></span>
					<span class="wpasb-cleanup-running-text"><?php esc_html_e( 'Cleaning database…', 'wp-admin-speedboost' ); ?></span>
				</p>

				<noscript>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpasb-cleanup-fallback">
						<?php wp_nonce_field( 'wpasb_cleanup', 'wpasb_cleanup_nonce' ); ?>
						<input type="hidden" name="action" value="wpasb_cleanup">
						<button type="submit" class="wpasb-btn wpasb-btn--light"><?php esc_html_e( 'Run cleanup', 'wp-admin-speedboost' ); ?></button>
					</form>
				</noscript>
			</div>

			<noscript>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpasb-cleanup-fallback">
					<?php wp_nonce_field( 'wpasb_cleanup', 'wpasb_cleanup_nonce' ); ?>
					<input type="hidden" name="action" value="wpasb_cleanup">
					<button type="submit" class="wpasb-btn wpasb-btn--light"><?php esc_html_e( 'Run cleanup now', 'wp-admin-speedboost' ); ?></button>
				</form>
			</noscript>

			<section class="wpasb-cleanup-result" id="wpasb-cleanup-result" aria-labelledby="wpasb-cleanup-result-title" hidden>
				<div class="wpasb-cleanup-result-heading">
					<span class="wpasb-cleanup-result-icon" aria-hidden="true"></span>
					<h3 id="wpasb-cleanup-result-title" tabindex="-1"></h3>
				</div>
				<p class="wpasb-cleanup-result-message"></p>
				<dl class="wpasb-cleanup-result-grid">
					<div class="wpasb-cleanup-result-item" data-key="revisions">
						<dt class="wpasb-cleanup-result-label"><?php esc_html_e( 'Revisions deleted', 'wp-admin-speedboost' ); ?></dt>
						<dd class="wpasb-cleanup-result-value">—</dd>
					</div>
					<div class="wpasb-cleanup-result-item" data-key="transients">
						<dt class="wpasb-cleanup-result-label"><?php esc_html_e( 'Expired transients deleted', 'wp-admin-speedboost' ); ?></dt>
						<dd class="wpasb-cleanup-result-value">—</dd>
					</div>
					<div class="wpasb-cleanup-result-item" data-key="orphan_meta">
						<dt class="wpasb-cleanup-result-label"><?php esc_html_e( 'Orphaned meta rows removed', 'wp-admin-speedboost' ); ?></dt>
						<dd class="wpasb-cleanup-result-value">—</dd>
					</div>
					<div class="wpasb-cleanup-result-item" data-key="tables">
						<dt class="wpasb-cleanup-result-label"><?php esc_html_e( 'Tables optimised', 'wp-admin-speedboost' ); ?></dt>
						<dd class="wpasb-cleanup-result-value">—</dd>
					</div>
					<div class="wpasb-cleanup-result-item wpasb-cleanup-result-item--full" data-key="skipped_innodb">
						<dt class="wpasb-cleanup-result-label"><?php esc_html_e( 'InnoDB tables skipped', 'wp-admin-speedboost' ); ?></dt>
						<dd class="wpasb-cleanup-result-value">—</dd>
					</div>
				</dl>
				<p class="wpasb-cleanup-result-note"></p>
				<div class="wpasb-cleanup-result-actions">
					<button type="button" class="wpasb-btn wpasb-btn--outline-dark" id="wpasb-cleanup-again"><?php esc_html_e( 'Review another cleanup', 'wp-admin-speedboost' ); ?></button>
				</div>
			</section>

			<p class="wpasb-cleanup-announcement screen-reader-text" id="wpasb-cleanup-announcement" role="status" aria-live="polite" aria-atomic="true"></p>
		</section>
		<?php
	}

	/**
	 * Login slug fields, attached under the Hide Login URL module card.
	 */
	private function render_login_fields( $active ) {
		$home      = trailingslashit( home_url() );
		$permalink = (bool) get_option( 'permalink_structure' );
		$prefix    = $home . ( $permalink ? '' : '?' );
		$slug      = get_option( WPASB_Hide_Login::OPT_SLUG, WPASB_Hide_Login::default_slug() );
		$redirect  = get_option( WPASB_Hide_Login::OPT_REDIRECT, WPASB_Hide_Login::default_redirect() );
		?>
		<div class="wpasb-module-settings wpasb-login-settings" id="wpasb-panel-hide-login" <?php echo $active ? '' : 'hidden'; ?>>
			<div class="wpasb-field-row">
				<p class="wpasb-field">
					<label class="wpasb-field-label" for="wpasb-login-slug"><?php esc_html_e( 'Login path', 'wp-admin-speedboost' ); ?></label>
					<span class="wpasb-url-field">
						<span class="wpasb-url-prefix"><?php echo esc_html( $prefix ); ?></span>
						<input
							type="text"
							id="wpasb-login-slug"
							name="<?php echo esc_attr( WPASB_Hide_Login::OPT_SLUG ); ?>"
							value="<?php echo esc_attr( $slug ); ?>"
						>
					</span>
				</p>

				<p class="wpasb-field">
					<label class="wpasb-field-label" for="wpasb-login-redirect"><?php esc_html_e( 'Redirect path', 'wp-admin-speedboost' ); ?></label>
					<span class="wpasb-url-field">
						<span class="wpasb-url-prefix"><?php echo esc_html( $prefix ); ?></span>
						<input
							type="text"
							id="wpasb-login-redirect"
							name="<?php echo esc_attr( WPASB_Hide_Login::OPT_REDIRECT ); ?>"
							value="<?php echo esc_attr( $redirect ); ?>"
						>
					</span>
				</p>
			</div>

			<p class="wpasb-url-preview" id="wpasb-login-preview" data-initial-slug="<?php echo esc_attr( $slug ); ?>" data-active="<?php echo $active ? '1' : '0'; ?>">
				<?php
				if ( $active ) {
					printf(
						/* translators: %s: current login URL */
						esc_html__( 'Current login URL: %s', 'wp-admin-speedboost' ),
						'<strong>' . esc_html( $prefix . $slug ) . '</strong>'
					);
				} else {
					printf(
						/* translators: %s: login URL that will apply after saving */
						esc_html__( 'Login URL after saving: %s', 'wp-admin-speedboost' ),
						'<strong>' . esc_html( $prefix . $slug ) . '</strong>'
					);
				}
				?>
			</p>

			<p class="wpasb-field-warning">
				<?php echo $this->icon( 'warn' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<span><?php esc_html_e( 'Bookmark your new login URL before you sign out.', 'wp-admin-speedboost' ); ?></span>
			</p>

			<p class="wpasb-field-help"><?php esc_html_e( 'The redirect path is where logged-out visitors land when they hit wp-login.php or wp-admin. Leave it as 404 unless you have a real page for it.', 'wp-admin-speedboost' ); ?></p>
		</div>

		<p class="wpasb-module-settings-hint" data-hint-for="hide-login" <?php echo $active ? 'hidden' : ''; ?>>
			<?php esc_html_e( 'Enable this module to configure it.', 'wp-admin-speedboost' ); ?>
		</p>
		<?php
	}

	/**
	 * Splash image picker, attached under the Custom Login Page module card.
	 */
	private function render_custom_login_fields( $active ) {
		$splash_id = (int) get_option( WPASB_Custom_Login::OPT_SPLASH, 0 );

		$preview_url = '';
		if ( $splash_id > 0 ) {
			$preview_url = wp_get_attachment_image_url( $splash_id, 'medium' );
		}

		// The stored attachment may have been deleted from the Media Library.
		// Drop back to 0 so the hidden field and preview both reflect reality.
		if ( $splash_id > 0 && ! $preview_url ) {
			$splash_id = 0;
		}

		$is_default = ! $preview_url;
		if ( $is_default ) {
			$preview_url = WPASB_Custom_Login::default_splash_url();
		}
		?>
		<div class="wpasb-module-settings wpasb-splash-settings" id="wpasb-panel-custom-login" <?php echo $active ? '' : 'hidden'; ?>>
			<div class="wpasb-splash-picker">
				<div class="wpasb-splash-preview">
					<img
						id="wpasb-splash-preview-img"
						src="<?php echo esc_url( $preview_url ); ?>"
						alt="<?php esc_attr_e( 'Login splash preview', 'wp-admin-speedboost' ); ?>"
					>
				</div>

				<div class="wpasb-splash-controls">
					<p class="wpasb-splash-source" id="wpasb-splash-default-note" data-default-label="<?php esc_attr_e( 'Bundled default image', 'wp-admin-speedboost' ); ?>" data-custom-label="<?php esc_attr_e( 'Custom image', 'wp-admin-speedboost' ); ?>">
						<?php echo $is_default ? esc_html__( 'Bundled default image', 'wp-admin-speedboost' ) : esc_html__( 'Custom image', 'wp-admin-speedboost' ); ?>
					</p>

					<input
						type="hidden"
						id="wpasb-splash-id"
						name="<?php echo esc_attr( WPASB_Custom_Login::OPT_SPLASH ); ?>"
						value="<?php echo esc_attr( $splash_id ); ?>"
					>

					<div class="wpasb-splash-buttons">
						<button type="button" class="wpasb-btn wpasb-btn--outline" id="wpasb-splash-choose"><?php esc_html_e( 'Choose image', 'wp-admin-speedboost' ); ?></button>
						<button
							type="button"
							class="wpasb-link-button"
							id="wpasb-splash-reset"
							<?php echo $splash_id > 0 ? '' : 'hidden'; ?>
						><?php esc_html_e( 'Reset to default', 'wp-admin-speedboost' ); ?></button>
					</div>

					<p class="wpasb-field-help wpasb-splash-error" id="wpasb-splash-error" hidden></p>
					<p class="wpasb-field-help"><?php esc_html_e( 'The site logo beside the form comes from your theme logo or site icon. Set it under Appearance > Customize.', 'wp-admin-speedboost' ); ?></p>
				</div>
			</div>
		</div>

		<p class="wpasb-module-settings-hint" data-hint-for="custom-login" <?php echo $active ? 'hidden' : ''; ?>>
			<?php esc_html_e( 'Enable this module to configure it.', 'wp-admin-speedboost' ); ?>
		</p>
		<?php
	}

	private function render_opcache_card() {
		$status = function_exists( 'opcache_get_status' ) ? @opcache_get_status( false ) : false;

		$available  = is_array( $status );
		$opc_on     = $available && ! empty( $status['opcache_enabled'] );
		$jit_on     = $opc_on && ! empty( $status['jit']['enabled'] );
		$num_cached = $opc_on ? (int) ( $status['opcache_statistics']['num_cached_scripts'] ?? 0 ) : null;

		$rows = [
			[
				'label' => __( 'OPcache', 'wp-admin-speedboost' ),
				'state' => $available ? ( $opc_on ? 'on' : 'off' ) : 'unknown',
			],
			[
				'label' => __( 'Cached scripts', 'wp-admin-speedboost' ),
				'state' => null === $num_cached ? 'unknown' : 'value',
				'value' => null === $num_cached ? '' : number_format_i18n( $num_cached ),
			],
			[
				'label' => __( 'JIT', 'wp-admin-speedboost' ),
				'state' => $available ? ( $jit_on ? 'on' : 'off' ) : 'unknown',
			],
		];
		?>
		<div class="wpasb-panel wpasb-runtime-card">
			<h3 class="wpasb-panel-title"><?php esc_html_e( 'PHP runtime', 'wp-admin-speedboost' ); ?></h3>

			<dl class="wpasb-runtime-list">
				<?php foreach ( $rows as $row ) : ?>
					<div class="wpasb-runtime-row">
						<dt><?php echo esc_html( $row['label'] ); ?></dt>
						<dd class="wpasb-runtime-value wpasb-runtime-value--<?php echo esc_attr( $row['state'] ); ?>">
							<?php
							if ( 'value' === $row['state'] ) {
								echo esc_html( $row['value'] );
							} elseif ( 'on' === $row['state'] ) {
								echo $this->icon( 'check' ); // phpcs:ignore WordPress.Security.EscapeOutput
								esc_html_e( 'Active', 'wp-admin-speedboost' );
							} elseif ( 'off' === $row['state'] ) {
								echo $this->icon( 'circle' ); // phpcs:ignore WordPress.Security.EscapeOutput
								esc_html_e( 'Inactive', 'wp-admin-speedboost' );
							} else {
								esc_html_e( 'Unavailable', 'wp-admin-speedboost' );
							}
							?>
						</dd>
					</div>
				<?php endforeach; ?>
			</dl>

			<p class="wpasb-runtime-footnote"><?php esc_html_e( 'Reported by this server when the page loaded.', 'wp-admin-speedboost' ); ?></p>

			<?php if ( ! $opc_on || ! $jit_on ) : ?>
				<p class="wpasb-panel-desc">
					<?php
					printf(
						/* translators: %s: php.ini filename */
						esc_html__( 'Ask your host to add this to %s:', 'wp-admin-speedboost' ),
						'<code>php.ini</code>'
					);
					?>
				</p>
				<div class="wpasb-code-toolbar">
					<span class="wpasb-code-filename">php.ini</span>
					<button type="button" class="wpasb-btn wpasb-btn--quiet wpasb-copy" data-copy-target="wpasb-php-code">
						<?php echo $this->icon( 'copy' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
						<span class="wpasb-copy-label"><?php esc_html_e( 'Copy code', 'wp-admin-speedboost' ); ?></span>
					</button>
				</div>
				<pre class="wpasb-code" id="wpasb-php-code"><?php echo esc_html(
					"zend_extension=opcache\n"
					. "opcache.enable=1\n"
					. "opcache.memory_consumption=256\n"
					. "opcache.interned_strings_buffer=16\n"
					. "opcache.max_accelerated_files=20000\n"
					. "opcache.validate_timestamps=1\n"
					. "opcache.jit=tracing\n"
					. "opcache.jit_buffer_size=128M"
				); ?></pre>
				<p class="wpasb-copy-status screen-reader-text" role="status" aria-live="polite"></p>
			<?php endif; ?>
		</div>
		<?php
	}
}
