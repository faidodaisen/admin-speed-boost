<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return [
	'slug'        => 'lazy-gravatars',
	'name'        => 'Lazy-Load Gravatars',
	'description' => 'Add loading="lazy" and decoding="async" to Gravatar img tags that do not already declare them. Defers external image fetches.',
	'default'     => true,
	'init'        => function () {
		// WP core already adds loading/decoding attributes to avatars since 5.5/6.1.
		// Only fill in what is missing so we never emit a duplicate attribute.
		add_filter( 'get_avatar', function ( $avatar ) {
			if ( ! is_string( $avatar ) || false === strpos( $avatar, '<img ' ) ) {
				return $avatar;
			}

			$add = '';
			if ( false === stripos( $avatar, ' loading=' ) ) {
				$add .= 'loading="lazy" ';
			}
			if ( false === stripos( $avatar, ' decoding=' ) ) {
				$add .= 'decoding="async" ';
			}
			if ( '' === $add ) {
				return $avatar;
			}

			return preg_replace( '/<img\s/', '<img ' . $add, $avatar, 1 );
		}, 10, 1 );
	},
];
