/**
 * Admin scripts for WP Admin Speedboost.
 *
 * Media Library picker for the Custom Login splash image. Stores the chosen
 * attachment ID in a hidden field and swaps the preview thumbnail.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var cfg     = window.wpasbLogin || {};
		var frame   = null;
		var $id     = $( '#wpasb-splash-id' );
		var $img    = $( '#wpasb-splash-preview-img' );
		var $note   = $( '#wpasb-splash-default-note' );
		var $reset  = $( '#wpasb-splash-reset' );
		var $choose = $( '#wpasb-splash-choose' );

		if ( ! $choose.length ) {
			return;
		}

		$choose.on( 'click', function ( e ) {
			e.preventDefault();

			if ( ! window.wp || ! window.wp.media ) {
				return;
			}

			if ( frame ) {
				frame.open();
				return;
			}

			frame = window.wp.media( {
				title: cfg.frameTitle || 'Select image',
				button: { text: cfg.frameButton || 'Use this image' },
				library: { type: 'image' },
				multiple: false
			} );

			frame.on( 'select', function () {
				var att = frame.state().get( 'selection' ).first().toJSON();
				if ( ! att || ! att.id ) {
					return;
				}

				var url = att.url;
				if ( att.sizes && att.sizes.medium ) {
					url = att.sizes.medium.url;
				}

				$id.val( att.id );
				$img.attr( 'src', url );
				$note.hide();
				$reset.show();
			} );

			frame.open();
		} );

		$reset.on( 'click', function ( e ) {
			e.preventDefault();
			$id.val( 0 );
			if ( cfg.defaultUrl ) {
				$img.attr( 'src', cfg.defaultUrl );
			}
			$note.show();
			$reset.hide();
		} );
	} );
} )( jQuery );
