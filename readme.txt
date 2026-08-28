=== WP Admin Speedboost ===
Contributors: fidodesign
Tags: performance, admin, optimization, speed, comments
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Modular wp-admin performance booster. 12 toggle-able optimisations plus one-click DB cleanup. Make wp-admin fast.

== Description ==

WP Admin Speedboost packages 12 individually toggle-able admin optimisations into a single settings page.

Every module is off-switchable, nothing is hidden, and no external service is contacted.

= Modules =

* Heartbeat Throttle - raise the heartbeat interval to 60s and drop it on screens that do not need it. The post editor and post list keep heartbeat, so autosave and post locking are unaffected.
* Dashboard Widget Cleanup - remove WP news, Site Health and known vendor widgets that fire HTTP probes on dashboard load
* Disable Emoji & oEmbed - strip the emoji loader, emoji stylesheet and front-end oEmbed discovery
* REST API User Lockdown - block /wp-json/wp/v2/users for unauthenticated requests only
* Disable jQuery Migrate (Frontend) - drop the legacy compatibility script on the public site
* Disable Comments Site-Wide - off by default, opt-in for institutional sites
* Site Health Probe Prune - remove async HTTP probes; direct security and PHP tests are kept
* Admin Bar Cleanup - remove WP logo, Comments and Updates icons
* Lazy-Load Gravatars - add loading and decoding attributes where they are missing
* Disable Application Passwords - off by default, since turning it on breaks REST integrations
* Hide Vendor Promo Notices - suppress promo banners while keeping errors, the Updates screen and Site Health untouched
* Silence Imagick Site Health Nag - hidden only when GD or Imagick is actually available

= Database Cleanup =

One-click cleanup that:

* deletes post revisions through the WordPress API, so postmeta and caches stay consistent
* deletes expired transients only, leaving live ones in place
* removes orphaned post and comment meta rows
* optimises non-InnoDB core tables

The action is nonce-protected, capability-checked, confirmed by a browser dialog, and uses post-redirect-get so a page refresh cannot run it twice.

**This permanently deletes data. Back up your database before running it.**

= Server-Level Recommendations =

The settings page also displays recommended wp-config.php constants and live OPcache/JIT status with suggested php.ini values.

= Developer Filters =

* `wpasb_heartbeat_keep_screens` - screens that must keep heartbeat
* `wpasb_dashboard_widgets_removed` - dashboard widget IDs to remove
* `wpasb_site_health_async_removed` - async Site Health tests to remove
* `wpasb_rest_user_routes_allowlist` - user routes to allow while logged out
* `wpasb_hidden_notice_css` - CSS used to hide vendor notices
* `wpasb_cleanup_batch_size` - revision deletion batch size
* `wpasb_optimize_innodb` - set true to OPTIMIZE InnoDB tables as well

== Installation ==

1. Upload the plugin zip via Plugins > Add New > Upload
2. Activate the plugin
3. Go to Settings > Admin Speedboost
4. Toggle modules on or off, save changes

== Frequently Asked Questions ==

= Does the Heartbeat module break autosave? =

No. Heartbeat is preserved on the post editor and the post list table, so autosave and post locking continue to work. It is only removed on screens that do not use it.

= Will REST API User Lockdown break my headless site or integrations? =

No. The check runs after authentication, so any logged-in user or authenticated API client still receives the full response. Only anonymous requests are refused.

= Why is Disable Application Passwords off by default? =

Turning it on breaks Jetpack, mobile apps and any REST client that authenticates with an application password. It is opt-in so nothing breaks silently.

= Why does cleanup report that InnoDB tables were skipped? =

OPTIMIZE TABLE on InnoDB triggers a full table rebuild and locks the table, which is risky on a live site and reclaims little space. InnoDB manages its own free space. Set the `wpasb_optimize_innodb` filter to true to force it.

== Changelog ==

= 1.1.0 =
* Fixed: Heartbeat module ran on `init`, where `get_current_screen()` is always null. Heartbeat was therefore removed on every admin screen including the editor, breaking autosave and post locking.
* Fixed: Removing heartbeat left `wp-auth-check` with an unregistered dependency, logging a PHP notice on every admin page load.
* Fixed: oEmbed discovery links are registered at two priorities in core; only one was removed, so discovery links kept rendering.
* Fixed: The emoji stylesheet is enqueued since WP 6.4 and was still loading.
* Fixed: Lazy-Load Gravatars produced duplicate `loading` attributes on avatars that already had one.
* Fixed: `wp-embed` was deregistered on `init` for admin too, which can break embed blocks in the block editor. Now front-end only.
* Fixed: REST user lockdown removed routes at registration time, before authentication resolved, which could 404 legitimate authenticated clients. It now refuses at dispatch.
* Fixed: Disable Comments removed `feed_links_extra`, which also killed category, tag, author and post type archive feeds.
* Fixed: Modules added in a plugin update never activated on existing installs, because stored settings were not merged with defaults.
* Fixed: Uninstall only cleaned the current site, leaving orphaned rows on multisite.
* Security: Cleanup now runs through `admin-post.php` with `check_admin_referer`, a capability check and post-redirect-get, so a refresh cannot re-run it.
* Security: Settings sanitiser rejects unknown keys and re-checks capabilities.
* Changed: Cleanup deletes only expired transients instead of all of them, and deletes revisions through the WordPress API so postmeta and caches stay consistent.
* Changed: Cleanup skips InnoDB tables by default and reports it.
* Changed: Disable Application Passwords now defaults to off, since it silently breaks REST integrations.
* Changed: Imagick nag is only hidden when an image backend is actually present.
* Changed: Vendor notice hiding no longer applies on the Updates, Plugins, Themes and Site Health screens.
* Changed: Removed the remote Google Fonts request from the admin page.
* Added: Full internationalisation, developer filters, and a confirmation dialog before cleanup.

= 1.0.0 =
* Initial release
