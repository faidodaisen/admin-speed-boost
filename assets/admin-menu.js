/**
 * Admin Menu Editor screen.
 *
 * Search, submenu disclosure, parent/child state, header count and the save
 * bar. The form still submits without this file; it only adds feedback.
 */
( function () {
	'use strict';

	var data = window.wpasbMenuData || {};
	var i18n = data.i18n || {};

	function t( key, fallback ) {
		return i18n[ key ] || fallback || '';
	}

	function sprintf( template ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		return String( template )
			.replace( /%(\d)\$d/g, function ( _, n ) {
				return args[ parseInt( n, 10 ) - 1 ];
			} )
			.replace( /%s|%d/g, function () {
				return args.shift();
			} );
	}

	function toArray( list ) {
		return Array.prototype.slice.call( list );
	}

	function init() {
		var form = document.getElementById( 'wpasb-menu-form' );
		if ( ! form ) {
			return;
		}

		var items     = toArray( form.querySelectorAll( '.wpasb-menu-item' ) );
		var inputs    = toArray( form.querySelectorAll( '.wpasb-menu-input' ) );
		var scope     = document.getElementById( 'wpasb-menu-include-admin' );
		var countEl   = document.getElementById( 'wpasb-menu-count' );
		var stateEl   = document.getElementById( 'wpasb-menu-state' );
		var savebar   = document.getElementById( 'wpasb-menu-savebar' );
		var discard   = document.getElementById( 'wpasb-menu-discard' );
		var saveBtn   = document.getElementById( 'wpasb-menu-save' );
		var submitting = false;

		function initialOf( input ) {
			return '1' === input.getAttribute( 'data-initial' );
		}

		function ownRow( input ) {
			return input.closest( '.wpasb-menu-child' ) || input.closest( '.wpasb-menu-item' );
		}

		function isDirty() {
			var changed = inputs.some( function ( input ) {
				return ! input.disabled && input.checked !== initialOf( input );
			} );
			return changed || ( scope && scope.checked !== initialOf( scope ) );
		}

		function sync() {
			var hidden = 0;

			inputs.forEach( function ( input ) {
				var row = ownRow( input );
				if ( ! row ) {
					return;
				}
				row.classList.toggle( 'is-off', ! input.checked );
				row.classList.toggle( 'is-changed', ! input.disabled && input.checked !== initialOf( input ) );
				if ( ! input.disabled && ! input.checked ) {
					hidden++;
				}
			} );

			items.forEach( function ( item ) {
				var own      = item.querySelector( ':scope > .wpasb-menu-row .wpasb-menu-input' );
				var parentOn = ! own || own.checked;
				var kids     = toArray( item.querySelectorAll( '.wpasb-menu-child .wpasb-menu-input' ) ).filter( function ( input ) {
					return ! input.disabled;
				} );
				var warning  = item.querySelector( ':scope > .wpasb-menu-row .wpasb-menu-warning' );

				kids.forEach( function ( input ) {
					// Children follow a hidden parent: keep their own value
					// but take them out of the tab order.
					input.tabIndex = parentOn ? 0 : -1;
				} );

				if ( warning ) {
					var allKidsOff = kids.length > 0 && kids.every( function ( input ) {
						return ! input.checked;
					} );
					warning.hidden = ! ( own && parentOn && allKidsOff );
				}
			} );

			var dirty = isDirty();

			if ( countEl ) {
				countEl.textContent = sprintf( t( 'hiddenCount', '%d hidden' ), hidden );
			}
			if ( stateEl ) {
				stateEl.textContent = dirty ? t( 'unsaved', 'Unsaved changes' ) : t( 'saved', 'Saved configuration' );
				stateEl.classList.toggle( 'is-unsaved', dirty );
			}
			if ( savebar ) {
				savebar.classList.toggle( 'is-visible', dirty );
				savebar.setAttribute( 'aria-hidden', dirty ? 'false' : 'true' );
			}
		}

		form.addEventListener( 'change', function ( event ) {
			if ( event.target.classList.contains( 'wpasb-menu-input' ) || event.target === scope ) {
				sync();
			}
		} );

		if ( discard ) {
			discard.addEventListener( 'click', function () {
				inputs.forEach( function ( input ) {
					input.checked = initialOf( input );
				} );
				if ( scope ) {
					scope.checked = initialOf( scope );
				}
				sync();
			} );
		}

		form.addEventListener( 'submit', function () {
			submitting = true;
			if ( saveBtn ) {
				saveBtn.setAttribute( 'aria-busy', 'true' );
				saveBtn.textContent = t( 'saving', 'Saving…' );
			}
		} );

		window.addEventListener( 'beforeunload', function ( event ) {
			if ( ! submitting && isDirty() ) {
				event.preventDefault();
				event.returnValue = '';
			}
		} );

		// Submenu disclosure.
		toArray( form.querySelectorAll( '.wpasb-menu-expand' ) ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var panel = document.getElementById( btn.getAttribute( 'aria-controls' ) );
				var open  = 'true' !== btn.getAttribute( 'aria-expanded' );
				btn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
				if ( panel ) {
					panel.hidden = ! open;
				}
			} );
		} );

		initSearch( items );
		sync();
	}

	function initSearch( items ) {
		var input  = document.getElementById( 'wpasb-menu-search' );
		var status = document.getElementById( 'wpasb-menu-search-status' );
		var empty  = document.getElementById( 'wpasb-menu-search-empty' );
		var timer  = null;

		if ( ! input ) {
			return;
		}

		function textOf( el ) {
			var label = el.querySelector( ':scope > .wpasb-menu-row .wpasb-menu-label' );
			var slug  = el.querySelector( ':scope > .wpasb-menu-row .wpasb-menu-slug' );
			return ( ( label ? label.textContent : '' ) + ' ' + ( slug ? slug.textContent : '' ) ).toLowerCase();
		}

		function apply() {
			var query = input.value.trim().toLowerCase();
			var shown = 0;

			items.forEach( function ( item ) {
				var selfMatch = '' === query || textOf( item ).indexOf( query ) !== -1;
				var kids      = toArray( item.querySelectorAll( '.wpasb-menu-child' ) );
				var kidMatch  = false;
				var sub       = item.querySelector( '.wpasb-menu-sub' );
				var btn       = item.querySelector( '.wpasb-menu-expand' );

				kids.forEach( function ( kid ) {
					var match = '' === query || selfMatch || textOf( kid ).indexOf( query ) !== -1;
					kid.classList.toggle( 'is-filtered-out', ! match );
					if ( '' !== query && ! selfMatch && match ) {
						kidMatch = true;
					}
				} );

				var visible = selfMatch || kidMatch;
				item.classList.toggle( 'is-filtered-out', ! visible );
				if ( visible ) {
					shown++;
				}

				// Open the submenu when the hit is a child, so it is visible.
				if ( kidMatch && sub && btn ) {
					sub.hidden = false;
					btn.setAttribute( 'aria-expanded', 'true' );
				}
			} );

			if ( empty ) {
				empty.hidden = 0 !== shown;
			}
			if ( status ) {
				status.textContent = sprintf( t( 'searchStatus', 'Showing %1$d of %2$d menu items.' ), shown, items.length );
			}
		}

		input.addEventListener( 'input', function () {
			window.clearTimeout( timer );
			timer = window.setTimeout( apply, 120 );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
