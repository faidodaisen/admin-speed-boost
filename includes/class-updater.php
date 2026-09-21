<?php
/**
 * Self-hosted updates from GitHub releases.
 *
 * The plugin is not on wordpress.org, so WordPress has no update source for it.
 * These hooks supply one:
 *
 *   1. `update_plugins_github.com` — WP 5.8+ asks plugins declaring an
 *      `Update URI` on that host to resolve their own updates. Scoped to our
 *      host, so we can never answer for someone else's plugin.
 *   2. `site_transient_update_plugins` — the 5.8 hook alone does not populate
 *      the Plugins-screen row on every code path (notably a cached transient),
 *      so we also inject our entry when the transient is read.
 *   3. `plugins_api` — powers "View details", which would otherwise 404 against
 *      wordpress.org for a plugin that does not live there.
 *
 * Release selection: newest non-draft release whose tag parses as a version.
 * Pre-releases skipped unless opted in. Offered only when strictly newer than
 * the installed version, so re-tagging never produces a phantom update.
 *
 * Package selection: a `.zip` asset is preferred (built artefact, correct
 * folder name). GitHub's generated `zipball_url` is the fallback; its top
 * folder is `owner-repo-sha`, so fix_source_dir() renames it back.
 *
 * @package WP_Admin_Speedboost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPASB_Updater {

	/** Where releases are published. */
	const REPO = 'faidodaisen/admin-speed-boost';

	/** Filter/option override, for a fork or private mirror. */
	const OPTION = 'wpasb_update_source';

	const CACHE_KEY = 'wpasb_update_check';

	/** How long a successful lookup is reused. */
	const CACHE_TTL = 21600; // 6 hours.

	/** Shorter TTL for failures so an outage is not cached all day. */
	const ERROR_TTL = 1800; // 30 minutes.

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function init() {
		add_filter( 'update_plugins_github.com', [ $this, 'check_for_update' ], 10, 3 );
		add_filter( 'site_transient_update_plugins', [ $this, 'inject_update' ] );
		add_filter( 'plugins_api', [ $this, 'plugin_info' ], 20, 3 );
		add_filter( 'upgrader_source_selection', [ $this, 'fix_source_dir' ], 10, 4 );
		add_filter( 'http_request_args', [ $this, 'authorize_download' ], 10, 2 );

		// Otherwise the "new version" row lingers for hours after updating.
		add_action( 'upgrader_process_complete', [ $this, 'clear_cache' ], 10, 0 );

		// Manual re-check. The lookup is cached for six hours, so without this
		// the only way to see a fresh release is to wait for cron.
		add_filter( 'plugin_row_meta', [ $this, 'row_meta_link' ], 10, 2 );
		add_action( 'admin_post_wpasb_check_update', [ $this, 'handle_manual_check' ] );
		add_action( 'admin_notices', [ $this, 'manual_check_notice' ] );
	}

	/**
	 * Reports the outcome of a manual check after the redirect.
	 */
	public function manual_check_notice() {
		if ( ! isset( $_GET['wpasb_checked'] ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$status = sanitize_key( wp_unslash( $_GET['wpasb_checked'] ) );

		if ( 'available' === $status ) {
			$release = self::latest_release();
			$class   = 'notice-success';
			$message = null !== $release
				/* translators: %s: version number */
				? sprintf( __( 'WP Admin Speedboost %s is available.', 'wp-admin-speedboost' ), $release['version'] )
				: __( 'A new version of WP Admin Speedboost is available.', 'wp-admin-speedboost' );
		} elseif ( 'current' === $status ) {
			$class   = 'notice-success';
			$message = __( 'WP Admin Speedboost is up to date.', 'wp-admin-speedboost' );
		} elseif ( 'error' === $status ) {
			$error   = self::last_error();
			$class   = 'notice-error';
			$message = '' !== $error
				/* translators: %s: reason the check failed */
				? sprintf( __( 'Update check failed: %s', 'wp-admin-speedboost' ), $error )
				: __( 'Update check failed.', 'wp-admin-speedboost' );
		} else {
			return;
		}

		printf(
			'<div class="notice %s is-dismissible"><p>%s</p></div>',
			esc_attr( $class ),
			esc_html( $message )
		);
	}

	// ------------------------------------------------------------------
	// Manual check
	// ------------------------------------------------------------------

	/**
	 * Adds a "Check for updates" link to this plugin's row on the Plugins
	 * screen.
	 */
	public function row_meta_link( $links, $plugin_file ) {
		if ( plugin_basename( WPASB_FILE ) !== $plugin_file || ! current_user_can( 'update_plugins' ) ) {
			return $links;
		}

		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::check_url() ),
			esc_html__( 'Check for updates', 'wp-admin-speedboost' )
		);

		return $links;
	}

	/**
	 * Nonce-protected URL that forces a fresh lookup and returns to the
	 * screen it was triggered from.
	 */
	public static function check_url( $redirect = '' ) {
		if ( '' === $redirect ) {
			$redirect = admin_url( 'plugins.php' );
		}

		return wp_nonce_url(
			add_query_arg(
				[
					'action'   => 'wpasb_check_update',
					'redirect' => rawurlencode( $redirect ),
				],
				admin_url( 'admin-post.php' )
			),
			'wpasb_check_update'
		);
	}

	/**
	 * Drops the cache, re-queries GitHub, and bounces back with the outcome in
	 * a query arg so the destination screen can report it.
	 */
	public function handle_manual_check() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to check for updates.', 'wp-admin-speedboost' ) );
		}

		check_admin_referer( 'wpasb_check_update' );

		$this->clear_cache();
		$release = self::latest_release( true );

		// WordPress caches its own update transient separately; without this
		// the Plugins screen keeps showing the stale copy.
		delete_site_transient( 'update_plugins' );

		if ( null === $release ) {
			$status = 'error';
		} elseif ( self::is_newer( $release['version'] ) ) {
			$status = 'available';
		} else {
			$status = 'current';
		}

		$redirect = isset( $_GET['redirect'] ) ? rawurldecode( wp_unslash( $_GET['redirect'] ) ) : admin_url( 'plugins.php' );
		$redirect = wp_validate_redirect( $redirect, admin_url( 'plugins.php' ) );

		wp_safe_redirect( add_query_arg( 'wpasb_checked', $status, $redirect ) );
		exit;
	}

	/**
	 * Update status panel, rendered on the settings page.
	 */
	public static function render_status_panel() {
		$release = self::latest_release();
		$error   = self::last_error();

		if ( null === $release ) {
			$state = 'error';
			$line  = '' !== $error
				/* translators: %s: reason the check failed */
				? sprintf( __( 'Could not check for updates: %s', 'wp-admin-speedboost' ), $error )
				: __( 'Could not check for updates.', 'wp-admin-speedboost' );
		} elseif ( self::is_newer( $release['version'] ) ) {
			$state = 'available';
			/* translators: %s: version number */
			$line = sprintf( __( 'Version %s is available.', 'wp-admin-speedboost' ), $release['version'] );
		} else {
			$state = 'current';
			$line  = __( 'You are running the latest version.', 'wp-admin-speedboost' );
		}
		?>
		<div class="wpasb-update-strip wpasb-update-strip--<?php echo esc_attr( $state ); ?>">
			<p class="wpasb-update-state">
				<span class="wpasb-update-version">
					<?php
					/* translators: %s: installed version number */
					printf( esc_html__( 'Version %s', 'wp-admin-speedboost' ), esc_html( WPASB_VERSION ) );
					?>
				</span>
				<span aria-hidden="true">&middot;</span>
				<span class="wpasb-update-line"><?php echo esc_html( $line ); ?></span>
			</p>
			<p class="wpasb-update-actions">
				<?php if ( 'available' === $state ) : ?>
					<a class="wpasb-btn wpasb-btn--primary" href="<?php echo esc_url( admin_url( 'plugins.php' ) ); ?>">
						<?php esc_html_e( 'Go to Plugins to update', 'wp-admin-speedboost' ); ?>
					</a>
				<?php endif; ?>
				<a class="wpasb-btn" href="<?php echo esc_url( self::check_url( admin_url( 'options-general.php?page=wp-admin-speedboost' ) ) ); ?>">
					<?php echo 'error' === $state ? esc_html__( 'Try again', 'wp-admin-speedboost' ) : esc_html__( 'Check again', 'wp-admin-speedboost' ); ?>
				</a>
				<?php if ( null !== $release && ! empty( $release['url'] ) ) : ?>
					<a class="wpasb-link-button" href="<?php echo esc_url( $release['url'] ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Release notes', 'wp-admin-speedboost' ); ?>
					</a>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	// ------------------------------------------------------------------
	// Configuration
	// ------------------------------------------------------------------

	/**
	 * Where to look for releases, and how.
	 *
	 * Deliberately not a user-facing setting: which repo the plugin updates
	 * from is a property of the build, not a preference. Escape hatches for a
	 * fork are the `wpasb_update_source` option and the `wpasb_update_config`
	 * filter (wp-config / mu-plugin).
	 *
	 * @return array{repo:string,token:string,prereleases:bool,enabled:bool}
	 */
	public static function config() {
		$saved = get_option( self::OPTION, [] );
		$saved = is_array( $saved ) ? $saved : [];

		$config = [
			'repo'        => isset( $saved['repo'] ) ? (string) $saved['repo'] : self::REPO,
			'token'       => isset( $saved['token'] ) ? (string) $saved['token'] : '',
			'prereleases' => ! empty( $saved['prereleases'] ),
			'enabled'     => ! isset( $saved['enabled'] ) || ! empty( $saved['enabled'] ),
		];

		$filtered = apply_filters( 'wpasb_update_config', $config );

		// A malformed filter return must not break update checks entirely.
		if ( ! is_array( $filtered ) ) {
			return $config;
		}

		return [
			'repo'        => isset( $filtered['repo'] ) ? (string) $filtered['repo'] : $config['repo'],
			'token'       => isset( $filtered['token'] ) ? (string) $filtered['token'] : $config['token'],
			'prereleases' => ! empty( $filtered['prereleases'] ),
			'enabled'     => ! isset( $filtered['enabled'] ) || ! empty( $filtered['enabled'] ),
		];
	}

	public static function plugin_basename() {
		return plugin_basename( WPASB_FILE );
	}

	public static function plugin_slug() {
		return dirname( self::plugin_basename() );
	}

	// ------------------------------------------------------------------
	// Remote lookup
	// ------------------------------------------------------------------

	/**
	 * Newest eligible release, or null.
	 *
	 * @param bool $force Bypass the cache.
	 * @return array{version:string,package:string,url:string,notes:string,published:string,asset:bool}|null
	 */
	public static function latest_release( $force = false ) {
		$config = self::config();

		if ( ! $config['enabled'] || '' === $config['repo'] ) {
			return null;
		}

		if ( ! $force ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				// A cached failure is an explicit marker, so it is
				// distinguishable from "never checked".
				return empty( $cached['release'] ) ? null : $cached['release'];
			}
		}

		// `/releases/latest` hides pre-releases and 404s on a repo whose only
		// releases are drafts, which reads as "no update" when the truth is
		// "wrong endpoint". Ask for a handful instead.
		$url = sprintf( 'https://api.github.com/repos/%s/releases?per_page=10', $config['repo'] );

		$response = wp_remote_get(
			$url,
			[
				'timeout' => 15,
				'headers' => self::api_headers( $config['token'] ),
			]
		);

		if ( is_wp_error( $response ) ) {
			self::cache_failure( $response->get_error_message() );
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			self::cache_failure( self::http_error_message( $code, '' !== $config['token'] ) );
			return null;
		}

		$releases = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $releases ) ) {
			self::cache_failure( __( 'GitHub returned an unreadable response.', 'wp-admin-speedboost' ) );
			return null;
		}

		$best = null;

		foreach ( $releases as $release ) {
			if ( ! is_array( $release ) || ! empty( $release['draft'] ) ) {
				continue;
			}
			if ( ! empty( $release['prerelease'] ) && ! $config['prereleases'] ) {
				continue;
			}

			$version = self::normalize_version( isset( $release['tag_name'] ) ? (string) $release['tag_name'] : '' );
			if ( '' === $version ) {
				continue;
			}

			if ( null !== $best && version_compare( $version, $best['version'], '<=' ) ) {
				continue;
			}

			// Prefer the built artefact over GitHub's generated zipball.
			$package   = '';
			$has_asset = false;
			$assets    = isset( $release['assets'] ) && is_array( $release['assets'] ) ? $release['assets'] : [];

			foreach ( $assets as $asset ) {
				if ( ! is_array( $asset ) ) {
					continue;
				}
				$name = isset( $asset['name'] ) ? strtolower( (string) $asset['name'] ) : '';
				if ( '.zip' !== substr( $name, -4 ) ) {
					continue;
				}
				// For a private repo the browser URL is not downloadable with a
				// token; the API asset URL is, with the right Accept header.
				$package = '' !== $config['token']
					? ( isset( $asset['url'] ) ? (string) $asset['url'] : '' )
					: ( isset( $asset['browser_download_url'] ) ? (string) $asset['browser_download_url'] : '' );

				$has_asset = '' !== $package;
				break;
			}

			if ( '' === $package ) {
				$package = isset( $release['zipball_url'] ) ? (string) $release['zipball_url'] : '';
			}
			if ( '' === $package ) {
				continue;
			}

			$best = [
				'version'   => $version,
				'package'   => $package,
				'url'       => isset( $release['html_url'] ) ? (string) $release['html_url'] : '',
				'notes'     => isset( $release['body'] ) ? (string) $release['body'] : '',
				'published' => isset( $release['published_at'] ) ? (string) $release['published_at'] : '',
				'asset'     => $has_asset,
			];
		}

		set_transient( self::CACHE_KEY, [ 'release' => $best, 'error' => '' ], self::CACHE_TTL );

		return $best;
	}

	/**
	 * @return array<string,string>
	 */
	private static function api_headers( $token, $download = false ) {
		$headers = [
			// GitHub rejects API requests with no User-Agent outright.
			'User-Agent' => 'WPAdminSpeedboost/' . WPASB_VERSION . '; ' . home_url(),
			'Accept'     => $download ? 'application/octet-stream' : 'application/vnd.github+json',
		];

		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		return $headers;
	}

	private static function http_error_message( $code, $has_token ) {
		if ( 404 === $code ) {
			return $has_token
				? __( 'Repository not found, or the token cannot see it. Check the repo name and that the token has "Contents: read" access.', 'wp-admin-speedboost' )
				: __( 'Repository not found. If it is private, a personal access token is required.', 'wp-admin-speedboost' );
		}
		if ( 401 === $code || 403 === $code ) {
			return __( 'GitHub refused the request. The token may be invalid or expired, or the hourly rate limit was hit.', 'wp-admin-speedboost' );
		}

		/* translators: %d: HTTP status code */
		return sprintf( __( 'GitHub returned HTTP %d.', 'wp-admin-speedboost' ), $code );
	}

	private static function cache_failure( $message ) {
		set_transient( self::CACHE_KEY, [ 'release' => null, 'error' => $message ], self::ERROR_TTL );
	}

	/**
	 * Last error from a lookup, if the cached result was a failure.
	 */
	public static function last_error() {
		$cached = get_transient( self::CACHE_KEY );

		return is_array( $cached ) && isset( $cached['error'] ) ? (string) $cached['error'] : '';
	}

	public function clear_cache() {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Strip a leading `v` and anything that is not part of the version:
	 * `v1.6.0` and `release-1.6.0` both yield `1.6.0`.
	 */
	private static function normalize_version( $tag ) {
		if ( ! preg_match( '/(\d+(?:\.\d+)*(?:-[0-9A-Za-z.]+)?)/', $tag, $m ) ) {
			return '';
		}

		return $m[1];
	}

	public static function is_newer( $remote ) {
		return version_compare( $remote, WPASB_VERSION, '>' );
	}

	// ------------------------------------------------------------------
	// WordPress update plumbing
	// ------------------------------------------------------------------

	/**
	 * @param array<string,mixed> $release
	 * @return array<string,mixed>
	 */
	private function update_payload( array $release ) {
		$config = self::config();

		return [
			'id'            => 'github.com/' . $config['repo'],
			'slug'          => self::plugin_slug(),
			'plugin'        => self::plugin_basename(),
			'new_version'   => $release['version'],
			'url'           => $release['url'],
			'package'       => $release['package'],
			'icons'         => [],
			'banners'       => [],
			'banners_rtl'   => [],
			'tested'        => get_bloginfo( 'version' ),
			'requires_php'  => '7.4',
			'compatibility' => new stdClass(),
		];
	}

	/**
	 * WP 5.8+ `update_plugins_{$hostname}` handler.
	 *
	 * @param array|false          $update
	 * @param array<string,string> $plugin_data
	 * @param string               $plugin_file
	 * @return array|false
	 */
	public function check_for_update( $update, $plugin_data, $plugin_file ) {
		if ( $plugin_file !== self::plugin_basename() ) {
			return $update;
		}

		$release = self::latest_release();
		if ( null === $release || ! self::is_newer( $release['version'] ) ) {
			return $update;
		}

		return $this->update_payload( $release );
	}

	/**
	 * Inject our entry when the update transient is read.
	 *
	 * @param mixed $transient
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$basename = self::plugin_basename();
		$release  = self::latest_release();

		if ( null === $release || ! self::is_newer( $release['version'] ) ) {
			// Do not leave a stale entry advertising an update that is gone.
			unset( $transient->response[ $basename ] );
			return $transient;
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = [];
		}

		$transient->response[ $basename ] = (object) $this->update_payload( $release );
		unset( $transient->no_update[ $basename ] );

		return $transient;
	}

	/**
	 * Supply the "View details" modal content.
	 *
	 * @param mixed  $result
	 * @param string $action
	 * @param object $args
	 * @return mixed
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( ! isset( $args->slug ) || $args->slug !== self::plugin_slug() ) {
			return $result;
		}

		$release = self::latest_release();
		if ( null === $release ) {
			return $result;
		}

		$config = self::config();

		return (object) [
			'name'          => 'WP Admin Speedboost',
			'slug'          => self::plugin_slug(),
			'version'       => $release['version'],
			'author'        => '<a href="https://fidodesign.dev/">FidoDesign</a>',
			'homepage'      => 'https://github.com/' . $config['repo'],
			'requires'      => '5.6',
			'requires_php'  => '7.4',
			'tested'        => get_bloginfo( 'version' ),
			'last_updated'  => $release['published'],
			'download_link' => $release['package'],
			'trunk'         => $release['package'],
			'sections'      => [
				'description' => wpautop( esc_html__( 'Modular WordPress admin performance booster with toggle-able modules and one-click DB cleanup.', 'wp-admin-speedboost' ) ),
				'changelog'   => self::render_notes( $release['notes'], $release['url'] ),
			],
		];
	}

	/**
	 * Release notes are Markdown. Rather than pull in a parser, convert the few
	 * constructs a release body actually uses and escape everything else, so a
	 * malformed note can never inject markup into wp-admin.
	 */
	private static function render_notes( $body, $url ) {
		$body = trim( $body );

		if ( '' === $body ) {
			return '<p>' . sprintf(
				/* translators: %s: link to the release page */
				esc_html__( 'No release notes provided. See %s.', 'wp-admin-speedboost' ),
				'<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">GitHub</a>'
			) . '</p>';
		}

		$out  = '';
		$list = false;

		$lines = preg_split( '/\R/', $body );
		$lines = $lines ? $lines : [];

		foreach ( $lines as $line ) {
			$trimmed = trim( $line );

			if ( preg_match( '/^[-*]\s+(.*)$/', $trimmed, $m ) ) {
				if ( ! $list ) {
					$out .= '<ul>';
					$list = true;
				}
				$out .= '<li>' . self::inline_markdown( $m[1] ) . '</li>';
				continue;
			}

			if ( $list ) {
				$out .= '</ul>';
				$list = false;
			}

			if ( '' === $trimmed ) {
				continue;
			}

			if ( preg_match( '/^(#{1,6})\s+(.*)$/', $trimmed, $m ) ) {
				$level = min( 6, max( 3, strlen( $m[1] ) + 2 ) ); // Never h1/h2 inside the modal.
				$out  .= '<h' . $level . '>' . self::inline_markdown( $m[2] ) . '</h' . $level . '>';
				continue;
			}

			$out .= '<p>' . self::inline_markdown( $trimmed ) . '</p>';
		}

		if ( $list ) {
			$out .= '</ul>';
		}

		$out .= '<p><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">'
			. esc_html__( 'View this release on GitHub', 'wp-admin-speedboost' )
			. '</a></p>';

		return $out;
	}

	/**
	 * Escape first, then re-introduce only `code` and `strong`. In this order,
	 * raw HTML in a release note is displayed, not executed.
	 */
	private static function inline_markdown( $text ) {
		$text = esc_html( $text );
		$out  = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );
		$text = null === $out ? $text : $out;
		$out  = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text );

		return null === $out ? $text : $out;
	}

	// ------------------------------------------------------------------
	// Install-time fixes
	// ------------------------------------------------------------------

	/**
	 * Add the token (and octet-stream Accept header) to the package download so
	 * private-repo assets are fetched rather than 404ing.
	 *
	 * @param array<string,mixed> $args
	 * @param string              $url
	 * @return array<string,mixed>
	 */
	public function authorize_download( $args, $url ) {
		if ( ! is_array( $args ) ) {
			return $args;
		}

		$config = self::config();

		if ( '' === $config['token'] || false === strpos( $url, 'api.github.com' ) ) {
			return $args;
		}

		// Only decorate requests aimed at OUR repo — never leak the token into
		// another plugin's GitHub traffic.
		if ( false === strpos( $url, '/repos/' . $config['repo'] . '/' ) ) {
			return $args;
		}

		$existing = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : [];

		$args['headers'] = array_merge(
			$existing,
			self::api_headers( $config['token'], false !== strpos( $url, '/assets/' ) )
		);

		return $args;
	}

	/**
	 * GitHub's generated zipball unpacks to `owner-repo-<sha>/`. Left alone,
	 * WordPress installs the plugin into a folder of that name, orphaning the
	 * existing installation and deactivating it. Rename to the real slug.
	 *
	 * @param string|WP_Error     $source
	 * @param string              $remote_source
	 * @param mixed               $upgrader
	 * @param array<string,mixed> $args
	 * @return string|WP_Error
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader = null, $args = [] ) {
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		// Only touch OUR update. `$args['plugin']` is set for plugin upgrades.
		$plugin = is_array( $args ) && isset( $args['plugin'] ) ? $args['plugin'] : '';
		if ( $plugin !== self::plugin_basename() ) {
			return $source;
		}

		$slug    = self::plugin_slug();
		$current = basename( untrailingslashit( $source ) );

		if ( $current === $slug ) {
			return $source;
		}

		$target = trailingslashit( dirname( untrailingslashit( $source ) ) ) . $slug;

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			return $source;
		}

		// A leftover target from a failed run would make move() fail.
		if ( $wp_filesystem->exists( $target ) ) {
			$wp_filesystem->delete( $target, true );
		}

		if ( ! $wp_filesystem->move( untrailingslashit( $source ), $target ) ) {
			return new WP_Error(
				'wpasb_rename_failed',
				__( 'Could not rename the downloaded package folder. The update was not installed.', 'wp-admin-speedboost' )
			);
		}

		return trailingslashit( $target );
	}
}
