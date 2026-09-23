=== WP Admin Speedboost ===
Contributors: fidodesign
Tags: performance, admin, optimization, speed, duplicate post
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Modular wp-admin performance booster. 17 toggle-able optimisations plus one-click DB cleanup. Make wp-admin fast.

== Description ==

WP Admin Speedboost packages 17 individually toggle-able admin optimisations into a single settings page.

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
* Hide Login URL - off by default. Moves wp-login.php to a slug you choose and sends logged-out visitors who hit wp-admin or wp-login.php to a 404.
* Enable Classic Editor - on by default. Restores the TinyMCE editor for posts, pages and widgets.
* Disable XML-RPC - on by default. Blocks xmlrpc.php, pingbacks and the RSD link.
* Duplicate Page & Post - on by default. Adds a Duplicate row action and a "Copy to a new draft" editor button.

= Duplicate Page & Post =

With the module on, every post, page and custom post type list gets a **Duplicate** link next to Edit and Trash, and the editor gains a **Copy to a new draft** button in the Publish box.

The copy carries over content, excerpt, taxonomies, custom fields, page template, menu order, parent and comment status. It is always created as a **draft** owned by the current user, so nothing goes live by accident.

Deliberately not copied:

* the slug, so the copy never collides with the original's canonical URL
* `_edit_lock` and `_edit_last`, which would make the copy look locked by another user
* trash and old-slug meta

Only users who can already create that post type see the link, and the action is nonce-protected. Attachments, revisions, reusable blocks, navigation, ACF field groups and order-type post types are excluded, because copying those produces broken records rather than useful drafts.

The module switches itself off and shows a notice if Yoast Duplicate Post, Duplicate Page, Post Duplicator or Duplicate Page and Post is active.

= Hide Login URL =

Set the slug under Settings > Admin Speedboost > Login URL. With the module on:

* `example.com/wp-login.php` returns a 404
* `example.com/wp-admin/` redirects logged-out visitors to the redirect slug (default `404`)
* `example.com/your-slug/` serves the normal WordPress login form
* Logged-in users who visit the slug are sent to the dashboard
* Password-protected posts, `admin-ajax.php`, `admin-post.php`, cron and WP-CLI keep working

**Bookmark the new login URL before you save.** Reserved slugs and anything containing `wp-login` are rejected and the previous value is kept.

The module switches itself off and shows a notice if WPS Hide Login or Rename wp-login.php is active, so the two cannot fight over the same routes.

This is a URL obfuscation measure, not authentication. Keep using strong passwords and two-factor.

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
* `wpasb_logged_in_redirect` - where an already logged-in visitor to the login slug is sent
* `wpasb_hide_login_signup_enable` - return true to allow wp-signup.php and wp-activate.php
* `wpasb_duplicate_post_types` - post types that get a Duplicate action
* `wpasb_duplicate_skipped_meta` - meta keys not carried into the copy
* `wpasb_duplicate_title_suffix` - the suffix appended to a copy's title, default `(Copy)`
* `wpasb_post_duplicated` - action fired after a copy is created, receives the new ID and the source post

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

= What if I forget my hidden login URL? =

Add `define( 'WPASB_DISABLE_HIDE_LOGIN', true );` to wp-config.php, or rename the plugin folder over FTP. Either restores wp-login.php immediately. You can also read the slug from the `wpasb_login_slug` row in the options table.

= Why does cleanup report that InnoDB tables were skipped? =

OPTIMIZE TABLE on InnoDB triggers a full table rebuild and locks the table, which is risky on a live site and reclaims little space. InnoDB manages its own free space. Set the `wpasb_optimize_innodb` filter to true to force it.

== Changelog ==

= 1.7.4 =
* Fixed: The "Bookmark your new login URL" warning sat flush against the "Current login URL" line above it. Same cascade problem as 1.7.3 — the rule was scoped to match the page-wide paragraph reset.
* Added: A footer credit linking to the plugin author at https://fidodesign.net/.

= 1.7.3 =
* Fixed: Text spacing throughout the settings screen was being silently discarded. A page-wide paragraph reset outranked every single-class rule that tried to add margin back, so headings ran into their descriptions and the cleanup notice sat flush against the line above it. Fifteen rules are now scoped to match that reset, and tighten the intended spacing on the header subtitle, summary line, section and panel descriptions, field help and URL preview, module settings hint, search-empty message, runtime footnote and the cleanup description, warning, note, running and result lines.⟪HERMES-CONTEXT-COMPRESSION: 1,086 of 1,286 chars omitted here by Hermes's context compressor. This is NOT part of the original tool call and must never be reproduced in new output — always write full, untruncated content.⟫
* Fixed: The "Login path" and "Redirect path" inputs were squeezed to a few unusable pixels on narrower admin columns. A grid item's automatic minimum size let the site-URL prefix hold its full width and starve the input, so the login slug was clipped to something like "sec" while the redirect value overflowed the field. The columns now collapse to one per row below 1100px, the prefix can shrink and ellipsise, and the input keeps a usable minimum width.
* Fixed: The dark "Database cleanup" band sat lower than every other section on the page. Absolutely positioned status regions were not actually being hidden, so an empty 1px paragraph pushed the band down and broke its alignment with the content column.
* Fixed: A phantom gap of roughly 120px between the module list and "Database cleanup" when the form was clean. The save bar was hidden with `visibility` alone, so it still reserved its full height and margin while invisible.
* Fixed: The cleanup description and the "Permanently deletes data" warning were touching, with no space between them.

= 1.7.1 =
* Changed: The module list is now a single compact list — one row per module, name and short description on the same line. The separate cards and their headed group boxes are gone, which removes most of the page's scroll length.
* Added: An "Enable all" / "Disable all" button beside the module search. It always acts on all 17 modules, not just the rows search is showing.
* Changed: Module descriptions shortened to one line each. The longer explanations were dropped rather than hidden behind a disclosure.
* Fixed: The "Database cleanup" heading rendered dark-on-dark, because the page-level heading colour outranked the panel's own rule.
* Fixed: Panels toggled by JavaScript could stay visible: a layout `display` on the class beat the browser's own `[hidden]` rule, so the cleanup spinner leaked on load.
* Fixed: The search icon overlapped the placeholder text. The icon is now a flex child instead of an overlay, so WordPress's own input padding cannot slide the text under it.
* Fixed: Horizontal scrolling at phone widths, caused by the server recommendation grid demanding a 380px track on a 360px screen.

= 1.7.0 =
* Changed: Redesigned the settings screen as a monochrome control panel. The oversized promotional header and the repeated per-module cards are gone; the page now opens with a compact identity header that reports how many of the 17 modules are enabled, followed by the version and update strip, the modules, database cleanup and server recommendations.
* Added: Modules are grouped by what they actually do — Performance, Less admin noise, Security & access, and Editing & login — instead of one undifferentiated list, with a per-group count of how many are selected.
* Added: Module search. Filtering narrows the visible modules and reports how many of the total are showing.
* Added: Unsaved-change tracking. Toggling a module marks the page dirty, the header switches to "X of 17 selected / Unsaved changes", each changed card states whether it will enable or disable when saved, and a save bar appears with Save and Discard.
* Changed: Database cleanup now runs over AJAX. Reviewing, confirming and running a cleanup no longer reloads the page; the result appears in place with the number of revisions, expired transients and orphaned meta rows removed, tables optimised, and InnoDB tables deliberately skipped.
* Added: A site-wide lock prevents two tabs from running destructive cleanup queries at the same time, and a cleanup whose connection is lost reports that it may still be running instead of inviting an immediate retry.
* Changed: Long module descriptions collapse into a Details disclosure so cards no longer vary wildly in height. No description text was rewritten or dropped.
* Changed: The update strip reports the installed version and the real check status. A failed check now says so and offers Try again rather than displaying "Up to date".
* Fixed: Cleanup without JavaScript still works. The confirmed form post, its nonce and its admin notice are unchanged.

= 1.6.0 =
* Added: Automatic updates from GitHub releases. The plugin is not on wordpress.org, so WordPress had no update source for it and the Plugins screen never reported new versions. It now asks the GitHub Releases API for the newest tagged release, and an available update appears on the Plugins and Updates screens exactly like any hosted plugin, one-click install included.
* Added: "Check for updates" link in the plugin's row on the Plugins screen, which clears the cache and re-checks immediately instead of waiting for the twice-daily cron.
* Added: Update status panel on the settings page showing the installed version, the newest release, and the reason if a check failed.
* Added: Support for private repositories via an optional GitHub token, filterable with `wpasb_github_token`. The token is only ever sent to this plugin's own repository.
* Changed: Release checks are cached for six hours, so the Plugins screen does not hit the GitHub API on every load.

= 1.5.0 =
* Added: Custom Login Page module. Off by default. Restyles wp-login.php into a split-screen layout with the login form and your site logo on the left and a splash image on the right, with Laravel-style form fields.
* Added: Splash image is chosen from the Media Library on the settings page. A bundled image is used until you pick one. The site logo is taken automatically from the theme custom logo or site icon.
* Added: Media picker script and splash preview on the settings page.
* Added: Login screen shows a heading naming the current task ("Welcome back", "Reset your password", "Choose a new password"), plus a small plugin credit in the bottom-right corner, filterable via `wpasb_login_credit`.
* Changed: The WordPress logo is replaced by a brand lockup, a framed site logo with rounded corners and a soft shadow next to the site title and tagline, so a plain square logo still reads as deliberate.
* Changed: Split is 35/65, form column to splash image, meeting the splash with no gap. The centred language switcher under the form is hidden.
* Changed: Uninstall now also removes the `wpasb_login_splash_id` option.

= 1.4.0 =
* Added: Duplicate Page & Post module. On by default. Adds a Duplicate row action to every post, page and custom post type list, plus a "Copy to a new draft" button in the editor.
* Added: Copies carry content, excerpt, taxonomies, custom fields, template, menu order, parent and comment status, and are always created as drafts owned by the current user.
* Added: Conflict detection. The module stays off and shows a notice when Yoast Duplicate Post, Duplicate Page, Post Duplicator or Duplicate Page and Post is active.
* Added: Filters `wpasb_duplicate_post_types`, `wpasb_duplicate_skipped_meta`, `wpasb_duplicate_title_suffix`, and the `wpasb_post_duplicated` action.
* Changed: Uninstall now also removes the `_wpasb_duplicate_of` provenance meta.

= 1.3.0 =
* Added: Enable Classic Editor module. On by default. Forces the classic editor for all post types and the classic widgets screen. Stands down if the Classic Editor plugin is active.
* Added: Disable XML-RPC module. On by default. Disables xmlrpc_enabled, empties the method list, returns 403 on any XML-RPC call, removes the RSD and WLW links, strips the X-Pingback header and closes pings.

= 1.2.0 =
* Added: Hide Login URL module. Moves wp-login.php to a custom slug and sends logged-out wp-admin and wp-login.php requests to a redirect slug. Off by default.
* Added: Login slug and redirect slug fields on the settings page, saved with the existing Save button.
* Added: Conflict detection. The module stays off and shows a notice when WPS Hide Login or Rename wp-login.php is active.
* Added: Login slug validation rejects WordPress query vars and anything containing `wp-login`, keeping the previous value instead of locking you out.
* Changed: Uninstall now also removes the two login options.

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
