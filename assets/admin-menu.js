/**
 * Admin Menu Editor screen.
 *
 * Search, submenu disclosure, parent/child state, header count, the save bar,
 * label editing and drag-to-reorder. The form still submits without this
 * file (hide and rename work); reordering needs it.
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
			.replace( /%(\d)\$[sd]/g, function ( _, n ) {
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
		var list      = document.getElementById( 'wpasb-menu-list' );
		var modeEl    = document.getElementById( 'wpasb-menu-order-mode' );
		var resetBtn  = document.getElementById( 'wpasb-menu-reset-order' );
		var moveLive  = document.getElementById( 'wpasb-menu-move-status' );
		var labels    = toArray( form.querySelectorAll( '.wpasb-menu-label-input' ) );
		var submitting = false;

		// Every sortable list: the top level plus each submenu.
		var lists = list ? [ list ].concat( toArray( list.querySelectorAll( '.wpasb-menu-sub' ) ) ) : [];

		function rowsOf( ul ) {
			return toArray( ul.children ).filter( function ( li ) {
				return li.classList.contains( 'wpasb-menu-item' ) || li.classList.contains( 'wpasb-menu-child' );
			} );
		}

		var initialOrder = lists.map( rowsOf );
		var initialMode  = modeEl ? modeEl.value : 'keep';

		function orderChanged() {
			return lists.some( function ( ul, n ) {
				var now = rowsOf( ul );
				return now.some( function ( li, i ) {
					return li !== initialOrder[ n ][ i ];
				} );
			} );
		}

		function labelOf( li ) {
			var input = li.querySelector( ':scope > .wpasb-menu-row .wpasb-menu-label-input' );
			if ( input ) {
				return input.value.trim() || input.getAttribute( 'data-original' ) || '';
			}
			var span = li.querySelector( ':scope > .wpasb-menu-row .wpasb-menu-label' );
			return span ? span.textContent.trim() : '';
		}

		function syncLabel( input ) {
			var original = input.getAttribute( 'data-original' ) || '';
			var value    = input.value.trim();
			var was      = input.parentNode.querySelector( '.wpasb-menu-original' );
			if ( was ) {
				was.hidden = '' === value || value === original;
			}
			input.size = Math.max( 6, Math.min( 40, ( value || original ).length + 2 ) );
		}

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
			var renamed = labels.some( function ( input ) {
				return input.value.trim() !== ( input.getAttribute( 'data-initial' ) || '' );
			} );
			var reorder = modeEl && modeEl.value !== initialMode;
			return changed || renamed || reorder || ( scope && scope.checked !== initialOf( scope ) );
		}

		function sync() {
			var hidden = 0;

			form.querySelectorAll( '.is-changed' ).forEach( function ( el ) {
				el.classList.remove( 'is-changed' );
			} );

			inputs.forEach( function ( input ) {
				var row = ownRow( input );
				if ( ! row ) {
					return;
				}
				row.classList.toggle( 'is-off', ! input.checked );
				if ( ! input.disabled && input.checked !== initialOf( input ) ) {
					row.classList.add( 'is-changed' );
				}
				if ( ! input.disabled && ! input.checked ) {
					hidden++;
				}
			} );

			labels.forEach( function ( input ) {
				if ( input.value.trim() !== ( input.getAttribute( 'data-initial' ) || '' ) ) {
					var row = ownRow( input );
					if ( row ) {
						row.classList.add( 'is-changed' );
					}
				}
			} );

			if ( resetBtn ) {
				var natural = lists.every( function ( ul ) {
					return rowsOf( ul ).every( function ( li, i ) {
						return String( i ) === li.getAttribute( 'data-natural' );
					} );
				} );
				// Nothing to reset while every list is in its registered order.
				resetBtn.hidden = natural;
			}

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

		form.addEventListener( 'input', function ( event ) {
			if ( event.target.classList.contains( 'wpasb-menu-label-input' ) ) {
				syncLabel( event.target );
				sync();
			}
		} );

		form.addEventListener( 'keydown', function ( event ) {
			var target = event.target;
			if ( ! target.classList.contains( 'wpasb-menu-label-input' ) ) {
				return;
			}
			// Enter in a label must not submit half-way through editing.
			if ( 'Enter' === event.key ) {
				event.preventDefault();
				target.blur();
			} else if ( 'Escape' === event.key ) {
				target.value = target.getAttribute( 'data-initial' ) || '';
				syncLabel( target );
				sync();
				target.blur();
			}
		} );

		function restoreOrder( orders ) {
			lists.forEach( function ( ul, n ) {
				orders[ n ].forEach( function ( li ) {
					ul.appendChild( li );
				} );
			} );
		}

		function setMode( mode ) {
			if ( modeEl ) {
				modeEl.value = mode;
			}
		}

		function orderMoved() {
			setMode( orderChanged() ? 'save' : initialMode );
			sync();
		}

		if ( discard ) {
			discard.addEventListener( 'click', function () {
				inputs.forEach( function ( input ) {
					input.checked = initialOf( input );
				} );
				labels.forEach( function ( input ) {
					input.value = input.getAttribute( 'data-initial' ) || '';
					syncLabel( input );
				} );
				if ( scope ) {
					scope.checked = initialOf( scope );
				}
				restoreOrder( initialOrder );
				setMode( initialMode );
				sync();
			} );
		}

		if ( resetBtn ) {
			resetBtn.addEventListener( 'click', function () {
				lists.forEach( function ( ul ) {
					rowsOf( ul ).sort( function ( a, b ) {
						return parseInt( a.getAttribute( 'data-natural' ), 10 ) - parseInt( b.getAttribute( 'data-natural' ), 10 );
					} ).forEach( function ( li ) {
						ul.appendChild( li );
					} );
				} );
				setMode( 'reset' );
				sync();
				if ( moveLive ) {
					moveLive.textContent = t( 'orderReset', 'Original order restored. Save to keep it.' );
				}
			} );
		}

		initSort( lists, rowsOf, labelOf, orderMoved, moveLive );

		var layoutEl = document.getElementById( 'wpasb-menu-layout' );

		// Pack the rename/order fields into one JSON field so the form posts
		// about as many fields as before (PHP max_input_vars).
		function packLayout() {
			if ( ! layoutEl ) {
				return;
			}
			var items = toArray( form.querySelectorAll( 'input.wpasb-menu-item-field[data-key]' ) ).map( function ( keyEl ) {
				var text  = keyEl.parentNode;
				var input = text.querySelector( ':scope > .wpasb-menu-label-input' );
				var item  = { k: keyEl.getAttribute( 'data-key' ) };
				if ( input ) {
					item.d = input.getAttribute( 'data-original' ) || '';
					item.l = input.value;
				}
				return item;
			} );
			layoutEl.value    = JSON.stringify( items );
			layoutEl.disabled = false;
			toArray( form.querySelectorAll( '.wpasb-menu-item-field, .wpasb-menu-label-input' ) ).forEach( function ( el ) {
				el.disabled = true;
			} );
		}

		// Back/forward cache brings the page back with the fields disabled.
		window.addEventListener( 'pageshow', function () {
			if ( layoutEl ) {
				layoutEl.disabled = true;
			}
			toArray( form.querySelectorAll( '.wpasb-menu-item-field, .wpasb-menu-label-input' ) ).forEach( function ( el ) {
				el.disabled = false;
			} );
			submitting = false;
		} );

		form.addEventListener( 'submit', function () {
			submitting = true;
			packLayout();
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
			var text  = '';
			if ( label ) {
				text = 'INPUT' === label.tagName
					? label.value + ' ' + ( label.getAttribute( 'data-original' ) || '' )
					: label.textContent;
			}
			return ( text + ' ' + ( slug ? slug.textContent : '' ) ).toLowerCase();
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

	/**
	 * Drag to reorder (pointer: mouse, pen and touch) plus arrow keys on the
	 * handle. Rows only move inside their own list; a pinned row (a parent's
	 * first submenu item, which core uses as the parent's link) stays first.
	 */
	function initSort( lists, rowsOf, labelOf, onMove, live ) {
		var drag = null;

		// Rows hidden by the search have no box; skipping them keeps a drag
		// from sliding past them on the first pixel.
		function movable( ul ) {
			return rowsOf( ul ).filter( function ( li ) {
				return ! li.classList.contains( 'is-pinned' ) && ! li.classList.contains( 'is-filtered-out' );
			} );
		}

		function announce( li, ul ) {
			if ( ! live ) {
				return;
			}
			var rows = rowsOf( ul );
			live.textContent = sprintf( t( 'moved', '%1$s moved to position %2$d of %3$d.' ), labelOf( li ), rows.indexOf( li ) + 1, rows.length );
		}

		function moveBy( li, ul, step ) {
			var rows = movable( ul );
			var i    = rows.indexOf( li );
			var to   = i + step;
			if ( i < 0 || to < 0 || to >= rows.length ) {
				return false;
			}
			if ( step < 0 ) {
				ul.insertBefore( li, rows[ to ] );
			} else {
				ul.insertBefore( li, rows[ to ].nextSibling );
			}
			return true;
		}

		lists.forEach( function ( ul ) {
			movable( ul ).forEach( function ( li ) {
				var handle = li.querySelector( ':scope > .wpasb-menu-row > button.wpasb-menu-handle' );
				if ( ! handle ) {
					return;
				}

				handle.addEventListener( 'keydown', function ( event ) {
					var step = 'ArrowUp' === event.key ? -1 : ( 'ArrowDown' === event.key ? 1 : 0 );
					if ( ! step ) {
						return;
					}
					event.preventDefault();
					if ( moveBy( li, ul, step ) ) {
						handle.focus();
						onMove();
						announce( li, ul );
					}
				} );

				handle.addEventListener( 'pointerdown', function ( event ) {
					if ( 0 !== event.button || drag ) {
						return;
					}
					event.preventDefault();
					handle.focus();
					drag = { li: li, ul: ul, id: event.pointerId, moved: false };
					li.classList.add( 'is-dragging' );
					ul.classList.add( 'is-sorting' );
					document.addEventListener( 'pointermove', move );
					document.addEventListener( 'pointerup', end );
					document.addEventListener( 'pointercancel', end );
				} );
			} );
		} );

		function rowRect( li ) {
			return li.querySelector( ':scope > .wpasb-menu-row' ).getBoundingClientRect();
		}

		// The dragged row never leaves the DOM (that would drop the pointer
		// and focus); its neighbours are moved around it instead.
		function move( event ) {
			if ( ! drag || event.pointerId !== drag.id ) {
				return;
			}
			var li   = drag.li;
			var ul   = drag.ul;
			var y    = event.clientY;
			var rows = movable( ul );
			var i    = rows.indexOf( li );
			var prev = rows[ i - 1 ];
			var next = rows[ i + 1 ];

			var p = prev ? rowRect( prev ) : null;
			if ( p && y < p.top + p.height / 2 ) {
				ul.insertBefore( prev, li.nextSibling );
				drag.moved = true;
			} else if ( next ) {
				var n = rowRect( next );
				if ( y > n.top + n.height / 2 ) {
					ul.insertBefore( next, li );
					drag.moved = true;
				}
			}

			// Scroll the page near the viewport edges.
			var edge = 48;
			if ( y < edge ) {
				window.scrollBy( 0, -12 );
			} else if ( y > window.innerHeight - edge ) {
				window.scrollBy( 0, 12 );
			}
		}

		function end( event ) {
			if ( ! drag || event.pointerId !== drag.id ) {
				return;
			}
			var li    = drag.li;
			var ul    = drag.ul;
			var moved = drag.moved;
			li.classList.remove( 'is-dragging' );
			ul.classList.remove( 'is-sorting' );
			drag = null;
			document.removeEventListener( 'pointermove', move );
			document.removeEventListener( 'pointerup', end );
			document.removeEventListener( 'pointercancel', end );
			if ( moved ) {
				onMove();
				announce( li, ul );
			}
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
