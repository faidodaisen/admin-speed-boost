=== WP Admin Speedboost ===
Contributors: fidodesign
Tags: performance, admin, optimization, speed, comments
Requires at least: 5.5
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Modular wp-admin performance booster. 12 toggle-able optimisations + one-click DB cleanup. Make wp-admin fast.

== Description ==

WP Admin Speedboost packages 12 individually toggle-able admin optimisations into a single settings page with a clean, focused UI.

= Modules =

* Heartbeat Throttle - reduce admin-ajax load 4x by raising heartbeat interval
* Dashboard Widget Cleanup - remove WP news, Site Health, vendor widgets that fire HTTP probes
* Disable Emoji & oEmbed - strip the emoji loader and oEmbed scripts
* REST API User Lockdown - block /wp-json/wp/v2/users for guests
* Disable jQuery Migrate (Frontend) - drop legacy compatibility script
* Disable Comments Site-Wide - off by default, opt-in for institutional sites
* Site Health Probe Prune - remove async HTTP probes that fire on dashboard load
* Admin Bar Cleanup - remove WP logo, Comments, Updates icons
* Lazy-Load Gravatars - defer external image fetches
* Disable Application Passwords - hide UI on user profiles
* Hide Vendor Promo Notices - suppress non-critical promo banners
* Silence Imagick Site Health Nag - remove "Imagick not installed" recommendation

= Database Cleanup =

One-click cleanup that purges post revisions, expired transients, and optimises core tables.

= Server-Level Recommendations =

The settings page also displays:
* Recommended wp-config.php constants
* OPcache + JIT live status with recommended php.ini config

== Installation ==

1. Upload the plugin zip via Plugins > Add New > Upload
2. Activate the plugin
3. Go to Settings > Admin Speedboost
4. Toggle modules on or off, save changes

== Changelog ==

= 1.0.0 =
* Initial release
