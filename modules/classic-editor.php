<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'slug'        => 'classic-editor',
	'name'        => 'Enable Classic Editor',
	'description' => 'Use the classic TinyMCE editor instead of the block editor for posts, pages and widgets. Turn this OFF if your content relies on Gutenberg blocks.',
	'default'     => true,
	'init'        => function () {
		// Do not fight a dedicated plugin that already owns this behaviour.
		if ( class_exists( 'Classic_Editor' ) ) {
			return;
		}

		add_filter( 'use_block_editor_for_post_type', '__return_false', 100 );
		add_filter( 'use_block_editor_for_post', '__return_false', 100 );
		// WP 4.9 - 5.9 fallback.
		add_filter( 'gutenberg_can_edit_post_type', '__return_false', 100 );

		// Classic widgets screen instead of the block-based one.
		add_filter( 'use_widgets_block_editor', '__return_false', 100 );

		// Front-end block CSS is deliberately left alone. Many themes and page
		// builders still render block markup on public pages, so dequeuing
		// wp-block-library here would break their styling.
	},
];
