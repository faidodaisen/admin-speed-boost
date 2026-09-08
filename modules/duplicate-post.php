<?php
/**
 * Duplicate Page & Post module.
 *
 * The engine lives in includes/class-duplicate-post.php because a module file
 * can be included more than once (each Module_Loader instance re-includes it),
 * and redeclaring a class or function there would be fatal.
 *
 * @package WP_Admin_Speedboost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'slug'        => 'duplicate-post',
	'name'        => 'Duplicate Page & Post',
	'description' => 'Adds a Duplicate link to every post, page and custom post type list, plus a "Copy to a new draft" button in the editor. The copy carries content, taxonomies and custom fields, and is always saved as a draft.',
	'default'     => true,
	'init'        => 'wpasb_duplicate_post_init',
];
