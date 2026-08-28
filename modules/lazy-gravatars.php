<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'slug'        => 'lazy-gravatars',
	'name'        => 'Lazy-Load Gravatars',
	'description' => 'Add loading="lazy" and decoding="async" to all Gravatar img tags. Defers external image fetches.',
	'default'     => true,
	'init'        => function () {
		add_filter( 'get_avatar', function ( $avatar ) {
			return str_replace( '<img ', '<img loading="lazy" decoding="async" ', $avatar );
		} );
	},
];
