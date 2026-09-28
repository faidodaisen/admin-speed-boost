<?php
/**
 * Admin Menu Editor screen: Speedboost -> Admin Menu.
 *
 * Registered only while the admin-menu-editor module is on. Saves through
 * options.php + register_setting like the Modules screen, so there is no AJAX
 * endpoint and the no-JS path still works.
 *
 * @package WP_Admin_Speedboost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPASB_Admin_Menu_Page {

	const GROUP = 'wpasb_admin_menu_group';
	const SEP   = '>';

	private static $instance = null;

	private $hook_suffix = '';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// After the top-level Speedboost menu (priority 10) exists.
		add_action( 'admin_menu', [ $this, 'add_page' ], 11 );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	public function add_page() {
		$this->hook_suffix = add_submenu_page(
			'wp-admin-speedboost',
			__( 'Admin Menu Editor', 'wp-admin-speedboost' ),
			__( 'Admin Menu', 'wp-admin-speedboost' ),
			'manage_options',
			WPASB_Admin_Menu::PAGE_SLUG,
			[ $this, 'render' ]
		);

		if ( $this->hook_suffix ) {
			add_action( 'load-' . $this->hook_suffix, [ 'WPASB_Admin_Menu', 'store_snapshot' ] );
		}
	}

	public function register_settings() {
		register_setting(
			self::GROUP,
			WPASB_Admin_Menu::OPT,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize' ],
				'default'           => [ 'menu' => [], 'submenu' => [], 'include_admin' => false ],
				'show_in_rest'      => false,
			]
		);
	}

	public static function encode_child( $parent, $child ) {
		return $parent . self::SEP . $child;
	}

	/**
	 * The form posts every rendered row in `rows[]` and every row switched
	 * to "shown" in `shown[]`; hidden = rows - shown. Only slugs that are in
	 * the stored snapshot, or already hidden, survive, and protected slugs
	 * never do.
	 */
	public function sanitize( $input ) {
		$current = WPASB_Admin_Menu::get_settings();

		if ( ! current_user_can( 'manage_options' ) ) {
			return $current;
		}

		// WordPress runs this callback a second time on the first save (when
		// the option does not exist yet), with the already clean value.
		if ( is_array( $input ) && ! isset( $input['rows'] ) && ( isset( $input['menu'] ) || isset( $input['submenu'] ) ) ) {
			return WPASB_Admin_Menu::clean_settings( $input );
		}

		return self::sanitize_input( $input, $current, get_option( WPASB_Admin_Menu::OPT_SNAPSHOT, [] ) );
	}

	/**
	 * Pure half of sanitize(), testable without WordPress.
	 */
	public static function sanitize_input( $input, array $current, $snapshot ) {
		$input    = is_array( $input ) ? $input : [];
		$snapshot = is_array( $snapshot ) ? $snapshot : [];

		$allowed_top = array_flip( isset( $snapshot['menu'] ) ? (array) $snapshot['menu'] : [] );
		foreach ( $current['menu'] as $slug ) {
			$allowed_top[ $slug ] = true;
		}

		$allowed_sub = [];
		if ( ! empty( $snapshot['submenu'] ) && is_array( $snapshot['submenu'] ) ) {
			foreach ( $snapshot['submenu'] as $parent => $children ) {
				foreach ( (array) $children as $child ) {
					$allowed_sub[ self::encode_child( $parent, $child ) ] = true;
				}
			}
		}
		foreach ( $current['submenu'] as $parent => $children ) {
			foreach ( $children as $child ) {
				$allowed_sub[ self::encode_child( $parent, $child ) ] = true;
			}
		}

		$rows  = isset( $input['rows'] ) && is_array( $input['rows'] ) ? $input['rows'] : [];
		$shown = isset( $input['shown'] ) && is_array( $input['shown'] ) ? array_flip( array_map( 'strval', $input['shown'] ) ) : [];

		$clean = [ 'menu' => [], 'submenu' => [], 'include_admin' => ! empty( $input['include_admin'] ) ];

		foreach ( $rows as $row ) {
			if ( ! is_string( $row ) ) {
				continue;
			}
			if ( isset( $shown[ $row ] ) ) {
				continue;
			}

			if ( 0 === strpos( $row, 'm:' ) ) {
				$slug = WPASB_Admin_Menu::normalize( substr( $row, 2 ) );
				if ( isset( $allowed_top[ $slug ] ) ) {
					$clean['menu'][] = $slug;
				}
			} elseif ( 0 === strpos( $row, 's:' ) ) {
				$pair = substr( $row, 2 );
				if ( false === strpos( $pair, self::SEP ) ) {
					continue;
				}
				list( $parent, $child ) = explode( self::SEP, $pair, 2 );
				$parent = WPASB_Admin_Menu::normalize( $parent );
				$child  = WPASB_Admin_Menu::normalize( $child );
				if ( isset( $allowed_sub[ self::encode_child( $parent, $child ) ] ) ) {
					$clean['submenu'][ $parent ][] = $child;
				}
			}
		}

		// clean_settings() also drops protected slugs.
		return WPASB_Admin_Menu::clean_settings( $clean );
	}

	public function enqueue_assets( $hook ) {
		if ( ! $this->hook_suffix || $hook !== $this->hook_suffix ) {
			return;
		}

		// Shared tokens, header, toggle and save bar come from the main sheet.
		wp_enqueue_style( 'wpasb-admin', WPASB_URL . 'assets/admin.css', [], WPASB_VERSION );
		wp_enqueue_style( 'wpasb-admin-menu', WPASB_URL . 'assets/admin-menu.css', [ 'wpasb-admin', 'dashicons' ], WPASB_VERSION );
		wp_enqueue_script( 'wpasb-admin-menu', WPASB_URL . 'assets/admin-menu.js', [], WPASB_VERSION, true );
		wp_localize_script(
			'wpasb-admin-menu',
			'wpasbMenuData',
			[
				'i18n' => [
					/* translators: %d: number of hidden menu items */
					'hiddenCount'   => __( '%d hidden', 'wp-admin-speedboost' ),
					'saved'         => __( 'Saved configuration', 'wp-admin-speedboost' ),
					'unsaved'       => __( 'Unsaved changes', 'wp-admin-speedboost' ),
					/* translators: %1$d: matching items, %2$d: total items */
					'searchStatus'  => __( 'Showing %1$d of %2$d menu items.', 'wp-admin-speedboost' ),
					'saving'        => __( 'Saving…', 'wp-admin-speedboost' ),
					'showSubmenu'   => __( 'Show submenu', 'wp-admin-speedboost' ),
					'hideSubmenu'   => __( 'Hide submenu', 'wp-admin-speedboost' ),
				],
			]
		);
	}

	/**
	 * Rows for the editor, in real sidebar order, from the menu captured
	 * before anything was hidden. Hidden slugs that are no longer registered
	 * are appended with `missing` so they can still be un-hidden.
	 *
	 * @return array<int, array>
	 */
	public static function build_rows( array $captured, array $settings ) {
		$menu    = isset( $captured['menu'] ) ? (array) $captured['menu'] : [];
		$submenu = isset( $captured['submenu'] ) ? (array) $captured['submenu'] : [];

		ksort( $menu, SORT_NUMERIC );

		$hidden_top = array_flip( $settings['menu'] );
		$rows       = [];
		$seen       = [];

		foreach ( $menu as $item ) {
			if ( empty( $item[2] ) ) {
				continue;
			}
			$slug = WPASB_Admin_Menu::normalize( $item[2] );
			$cls  = isset( $item[4] ) ? (string) $item[4] : '';

			if ( '' === $slug || isset( $seen[ $slug ] ) || false !== strpos( $cls, 'wp-menu-separator' ) ) {
				continue;
			}
			$seen[ $slug ] = true;

			$children     = [];
			$seen_child   = [];
			$hidden_child = isset( $settings['submenu'][ $slug ] ) ? array_flip( $settings['submenu'][ $slug ] ) : [];
			$raw_children = isset( $submenu[ $item[2] ] ) ? (array) $submenu[ $item[2] ] : [];
			ksort( $raw_children, SORT_NUMERIC );

			foreach ( $raw_children as $child ) {
				if ( empty( $child[2] ) ) {
					continue;
				}
				$cslug = WPASB_Admin_Menu::normalize( $child[2] );
				if ( '' === $cslug || isset( $seen_child[ $cslug ] ) ) {
					continue;
				}
				$seen_child[ $cslug ] = true;

				$children[] = [
					'slug'      => $cslug,
					'label'     => WPASB_Admin_Menu::clean_label( isset( $child[0] ) ? $child[0] : '', $cslug ),
					'hidden'    => isset( $hidden_child[ $cslug ] ),
					'protected' => WPASB_Admin_Menu::is_protected_slug( $cslug ),
					'missing'   => false,
				];
			}

			foreach ( array_keys( $hidden_child ) as $cslug ) {
				if ( ! isset( $seen_child[ $cslug ] ) ) {
					$children[] = [
						'slug'      => $cslug,
						'label'     => $cslug,
						'hidden'    => true,
						'protected' => false,
						'missing'   => true,
					];
				}
			}

			$rows[] = [
				'slug'      => $slug,
				'label'     => WPASB_Admin_Menu::clean_label( isset( $item[0] ) ? $item[0] : '', $slug ),
				'icon'      => isset( $item[6] ) ? (string) $item[6] : '',
				'hidden'    => isset( $hidden_top[ $slug ] ),
				'protected' => WPASB_Admin_Menu::is_protected_slug( $slug ),
				'missing'   => false,
				'children'  => $children,
			];
		}

		foreach ( $settings['menu'] as $slug ) {
			if ( ! isset( $seen[ $slug ] ) ) {
				$rows[] = [
					'slug'      => $slug,
					'label'     => $slug,
					'icon'      => '',
					'hidden'    => true,
					'protected' => false,
					'missing'   => true,
					'children'  => [],
				];
				$seen[ $slug ] = true;
			}
		}

		// Hidden children whose parent is gone entirely.
		foreach ( $settings['submenu'] as $parent => $children ) {
			if ( isset( $seen[ $parent ] ) ) {
				continue;
			}
			$kids = [];
			foreach ( $children as $cslug ) {
				$kids[] = [
					'slug'      => $cslug,
					'label'     => $cslug,
					'hidden'    => true,
					'protected' => false,
					'missing'   => true,
				];
			}
			$rows[] = [
				'slug'      => $parent,
				'label'     => $parent,
				'icon'      => '',
				'hidden'    => false,
				'protected' => false,
				'missing'   => true,
				'orphan'    => true,
				'children'  => $kids,
			];
		}

		return $rows;
	}

	/**
	 * WordPress drops some top-level items only after admin_menu has run
	 * (Links while the link manager is off, parents left with no reachable
	 * child). Keep the captured items that survived into the final sidebar,
	 * plus the ones this module removed itself.
	 */
	public static function visible_capture( array $captured, array $settings ) {
		global $menu;

		if ( ! is_array( $menu ) || empty( $captured['menu'] ) ) {
			return $captured;
		}

		$keep = array_flip( $settings['menu'] );
		foreach ( $menu as $item ) {
			if ( ! empty( $item[2] ) ) {
				$keep[ WPASB_Admin_Menu::normalize( $item[2] ) ] = true;
			}
		}

		foreach ( $captured['menu'] as $i => $item ) {
			if ( ! empty( $item[2] ) && ! isset( $keep[ WPASB_Admin_Menu::normalize( $item[2] ) ] ) ) {
				unset( $captured['menu'][ $i ] );
			}
		}

		return $captured;
	}

	/**
	 * Menu icon markup: dashicon class, inline SVG data URI, or a neutral dot.
	 */
	private function icon_html( $icon ) {
		$icon = (string) $icon;

		if ( preg_match( '/^dashicons-[a-z0-9-]+$/', $icon ) ) {
			return '<span class="wpasb-menu-icon dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span>';
		}
		// Strict base64 only: the value lands inside a CSS url(), where an
		// entity-encoded quote would still close the string.
		if ( preg_match( '#^data:image/svg\+xml;base64,[A-Za-z0-9+/]+=*$#', $icon ) ) {
			return '<span class="wpasb-menu-icon wpasb-menu-icon--svg" aria-hidden="true" style="background-image:url(&quot;' . esc_attr( $icon ) . '&quot;)"></span>';
		}

		return '<span class="wpasb-menu-icon wpasb-menu-icon--none" aria-hidden="true"></span>';
	}

	private function toggle( $row_value, $label, $shown, $disabled, $extra_class = '' ) {
		$id = 'wpasb-mi-' . substr( md5( $row_value ), 0, 12 );
		?>
		<label class="wpasb-toggle<?php echo $extra_class ? ' ' . esc_attr( $extra_class ) : ''; ?>" for="<?php echo esc_attr( $id ); ?>">
			<span class="screen-reader-text">
				<?php
				printf(
					/* translators: %s: menu item label */
					esc_html__( 'Show %s', 'wp-admin-speedboost' ),
					esc_html( $label )
				);
				?>
			</span>
			<?php if ( ! $disabled ) : ?>
				<input type="hidden" name="<?php echo esc_attr( WPASB_Admin_Menu::OPT ); ?>[rows][]" value="<?php echo esc_attr( $row_value ); ?>">
			<?php endif; ?>
			<input
				type="checkbox"
				id="<?php echo esc_attr( $id ); ?>"
				class="wpasb-module-input wpasb-menu-input"
				name="<?php echo esc_attr( WPASB_Admin_Menu::OPT ); ?>[shown][]"
				value="<?php echo esc_attr( $row_value ); ?>"
				data-initial="<?php echo $shown ? '1' : '0'; ?>"
				<?php checked( $shown ); ?>
				<?php disabled( $disabled ); ?>
			>
			<span class="wpasb-toggle-slider" aria-hidden="true"></span>
		</label>
		<?php
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-admin-speedboost' ) );
		}

		settings_errors();

		$settings = WPASB_Admin_Menu::get_settings();
		$captured = self::visible_capture( WPASB_Admin_Menu::captured(), $settings );
		$rows     = self::build_rows( $captured, $settings );

		$hidden_count = count( $settings['menu'] );
		foreach ( $settings['submenu'] as $children ) {
			$hidden_count += count( $children );
		}
		$top_count = count( $rows );
		?>
		<div class="wpasb-page wpasb-menu-page">
			<div class="wpasb-shell">

				<header class="wpasb-header">
					<div class="wpasb-header-identity">
						<h1 class="wpasb-header-title"><?php esc_html_e( 'Admin Menu', 'wp-admin-speedboost' ); ?></h1>
						<p class="wpasb-header-subtitle"><?php esc_html_e( 'Hide admin menu items and block the pages behind them.', 'wp-admin-speedboost' ); ?></p>
					</div>
					<div class="wpasb-header-summary">
						<p class="wpasb-summary-count" id="wpasb-menu-count">
							<?php
							printf(
								/* translators: %d: number of hidden menu items */
								esc_html__( '%d hidden', 'wp-admin-speedboost' ),
								(int) $hidden_count
							);
							?>
						</p>
						<p class="wpasb-summary-state" id="wpasb-menu-state"><?php esc_html_e( 'Saved configuration', 'wp-admin-speedboost' ); ?></p>
					</div>
				</header>

				<form method="post" action="options.php" class="wpasb-form" id="wpasb-menu-form">
					<?php settings_fields( self::GROUP ); ?>

					<div class="wpasb-module-toolbar">
						<div class="wpasb-module-toolbar-text">
							<h2 class="wpasb-section-title"><?php esc_html_e( 'Menu items', 'wp-admin-speedboost' ); ?></h2>
							<p class="wpasb-section-desc"><?php esc_html_e( 'Switch an item off to hide it and block its page. Hiding a top-level item also blocks everything under it.', 'wp-admin-speedboost' ); ?></p>
						</div>
						<div class="wpasb-module-tools">
							<div class="wpasb-search-control">
								<label class="screen-reader-text" for="wpasb-menu-search"><?php esc_html_e( 'Find a menu item', 'wp-admin-speedboost' ); ?></label>
								<svg class="wpasb-icon wpasb-icon--search" viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="7.25" cy="7.25" r="4.5"/><path d="M10.6 10.6 13.5 13.5"/></svg>
								<input type="search" id="wpasb-menu-search" class="wpasb-search-input" autocomplete="off"
									placeholder="<?php esc_attr_e( 'Find a menu item…', 'wp-admin-speedboost' ); ?>">
							</div>
						</div>
						<p class="wpasb-search-status screen-reader-text" id="wpasb-menu-search-status" role="status" aria-live="polite"></p>
					</div>

					<div class="wpasb-menu-scope">
						<label class="wpasb-menu-scope-label" for="wpasb-menu-include-admin">
							<input type="checkbox" id="wpasb-menu-include-admin"
								name="<?php echo esc_attr( WPASB_Admin_Menu::OPT ); ?>[include_admin]" value="1"
								data-initial="<?php echo $settings['include_admin'] ? '1' : '0'; ?>"
								<?php checked( $settings['include_admin'] ); ?>>
							<span><?php esc_html_e( 'Apply to administrators too', 'wp-admin-speedboost' ); ?></span>
						</label>
						<p class="wpasb-menu-scope-help">
							<?php esc_html_e( 'Leave this off and administrators keep the full menu. Speedboost, Dashboard and Profile are never blocked.', 'wp-admin-speedboost' ); ?>
						</p>
					</div>

					<p class="wpasb-search-empty" id="wpasb-menu-search-empty" hidden>
						<?php esc_html_e( 'No menu items match your search.', 'wp-admin-speedboost' ); ?>
					</p>

					<?php if ( ! $rows ) : ?>
						<p class="wpasb-menu-empty"><?php esc_html_e( 'The admin menu could not be read on this request. Reload the page.', 'wp-admin-speedboost' ); ?></p>
					<?php endif; ?>

					<ul class="wpasb-menu-list" id="wpasb-menu-list" data-total="<?php echo esc_attr( $top_count ); ?>">
						<?php foreach ( $rows as $i => $row ) : ?>
							<?php
							$has_children = ! empty( $row['children'] );
							$sub_id       = 'wpasb-menu-sub-' . $i;
							$orphan       = ! empty( $row['orphan'] );
							?>
							<li class="wpasb-menu-item<?php echo $row['hidden'] ? ' is-off' : ''; ?><?php echo $row['missing'] ? ' is-missing' : ''; ?>"
								data-slug="<?php echo esc_attr( $row['slug'] ); ?>">
								<div class="wpasb-menu-row">
									<?php
									if ( $orphan ) {
										echo '<span class="wpasb-toggle wpasb-toggle--placeholder" aria-hidden="true"></span>';
									} else {
										$this->toggle( 'm:' . $row['slug'], $row['label'], ! $row['hidden'], $row['protected'] );
									}
									echo $this->icon_html( $row['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
									?>
									<span class="wpasb-menu-text">
										<span class="wpasb-menu-label"><?php echo esc_html( $row['label'] ); ?></span>
										<code class="wpasb-menu-slug"><?php echo esc_html( $row['slug'] ); ?></code>
										<?php if ( $row['protected'] ) : ?>
											<span class="wpasb-menu-badge" title="<?php esc_attr_e( 'Speedboost, Dashboard and Profile can never be hidden: they are how you get back in.', 'wp-admin-speedboost' ); ?>"><?php esc_html_e( 'Always shown', 'wp-admin-speedboost' ); ?></span>
										<?php endif; ?>
										<?php if ( $row['missing'] ) : ?>
											<span class="wpasb-menu-badge"><?php esc_html_e( 'Not registered any more', 'wp-admin-speedboost' ); ?></span>
										<?php endif; ?>
										<span class="wpasb-menu-warning" hidden><?php esc_html_e( 'Every submenu item is hidden. Hide the parent too?', 'wp-admin-speedboost' ); ?></span>
									</span>
									<?php if ( $has_children ) : ?>
										<button type="button" class="wpasb-menu-expand" aria-expanded="false" aria-controls="<?php echo esc_attr( $sub_id ); ?>">
											<span class="wpasb-menu-expand-count"><?php echo (int) count( $row['children'] ); ?></span>
											<svg class="wpasb-icon" viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 4l4 4-4 4"/></svg>
											<span class="screen-reader-text">
												<?php
												printf(
													/* translators: %s: menu item label */
													esc_html__( 'Submenu of %s', 'wp-admin-speedboost' ),
													esc_html( $row['label'] )
												);
												?>
											</span>
										</button>
									<?php endif; ?>
								</div>

								<?php if ( $has_children ) : ?>
									<ul class="wpasb-menu-sub" id="<?php echo esc_attr( $sub_id ); ?>" hidden>
										<?php foreach ( $row['children'] as $child ) : ?>
											<li class="wpasb-menu-child<?php echo $child['hidden'] ? ' is-off' : ''; ?><?php echo $child['missing'] ? ' is-missing' : ''; ?>"
												data-slug="<?php echo esc_attr( $child['slug'] ); ?>">
												<div class="wpasb-menu-row">
													<?php $this->toggle( 's:' . self::encode_child( $row['slug'], $child['slug'] ), $child['label'], ! $child['hidden'], $child['protected'] ); ?>
													<span class="wpasb-menu-text">
														<span class="wpasb-menu-label"><?php echo esc_html( $child['label'] ); ?></span>
														<code class="wpasb-menu-slug"><?php echo esc_html( $child['slug'] ); ?></code>
														<?php if ( $child['protected'] ) : ?>
															<span class="wpasb-menu-badge"><?php esc_html_e( 'Always shown', 'wp-admin-speedboost' ); ?></span>
														<?php endif; ?>
														<?php if ( $child['missing'] ) : ?>
															<span class="wpasb-menu-badge"><?php esc_html_e( 'Not registered any more', 'wp-admin-speedboost' ); ?></span>
														<?php endif; ?>
														<span class="wpasb-menu-follows"><?php esc_html_e( 'Hidden with parent', 'wp-admin-speedboost' ); ?></span>
													</span>
												</div>
											</li>
										<?php endforeach; ?>
									</ul>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>

					<p class="wpasb-menu-note">
						<?php
						printf(
							/* translators: %s: PHP constant definition */
							esc_html__( 'This controls what the menu shows and which pages open. It is not a permission system: users keep their capabilities, so REST and AJAX requests still work. Locked yourself out? Add %s to wp-config.php.', 'wp-admin-speedboost' ),
							'<code>' . esc_html( "define( 'WPASB_DISABLE_MENU_EDITOR', true );" ) . '</code>'
						);
						?>
					</p>

					<div class="wpasb-savebar" id="wpasb-menu-savebar" aria-hidden="true">
						<p class="wpasb-savebar-status"><?php esc_html_e( 'Unsaved changes', 'wp-admin-speedboost' ); ?></p>
						<div class="wpasb-savebar-actions">
							<button type="button" class="wpasb-btn wpasb-btn--ghost" id="wpasb-menu-discard"><?php esc_html_e( 'Discard changes', 'wp-admin-speedboost' ); ?></button>
							<button type="submit" class="wpasb-btn wpasb-btn--primary" id="wpasb-menu-save"><?php esc_html_e( 'Save changes', 'wp-admin-speedboost' ); ?></button>
						</div>
					</div>

					<noscript>
						<p><button type="submit" class="wpasb-btn wpasb-btn--primary"><?php esc_html_e( 'Save changes', 'wp-admin-speedboost' ); ?></button></p>
					</noscript>
				</form>

				<footer class="wpasb-footer">
					<p class="wpasb-footer-credit">
						<?php
						printf(
							/* translators: %s: link to the plugin author's website */
							esc_html__( 'WP Admin Speedboost by %s', 'wp-admin-speedboost' ),
							'<a href="https://fidodesign.net/" target="_blank" rel="noopener noreferrer">FidoDesign</a>'
						);
						?>
					</p>
				</footer>
			</div>
		</div>
		<?php
	}
}
