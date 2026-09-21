/**
 * Admin scripts for WP Admin Speedboost.
 *
 * Every feature below binds independently: module search, module state and
 * dirty tracking, the splash-image picker, the AJAX database cleanup, and the
 * code copy buttons. A missing element in one must never stop the others.
 */
/* global wpasbLogin, wpasbData, wp */
( function () {
	'use strict';

	var data = window.wpasbData || {};
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

	function announce( el, message ) {
		if ( ! el ) {
			return;
		}
		// Clearing first makes a repeated identical message announce again.
		el.textContent = '';
		window.setTimeout( function () {
			el.textContent = message;
		}, 60 );
	}

	/* ------------------------------------------------------------------
	 * Module search
	 * ---------------------------------------------------------------- */
	function initSearch() {
		var input = document.getElementById( 'wpasb-search' );
		if ( ! input ) {
			return;
		}

		var clearBtn = document.getElementById( 'wpasb-search-clear' );
		var status   = document.getElementById( 'wpasb-search-status' );
		var empty    = document.getElementById( 'wpasb-search-empty' );
		var rows     = Array.prototype.slice.call( document.querySelectorAll( '.wpasb-module-row' ) );
		var headings = Array.prototype.slice.call( document.querySelectorAll( '.wpasb-list-group' ) );
		var timer    = null;

		var index = rows.map( function ( row ) {
			var name = row.querySelector( '.wpasb-row-name' );
			var desc = row.querySelector( '.wpasb-row-desc' );

			return {
				row: row,
				haystack: [
					name ? name.textContent : '',
					desc ? desc.textContent : '',
					row.getAttribute( 'data-group-name' ) || ''
				].join( ' ' ).toLowerCase()
			};
		} );

		function apply() {
			var query = input.value.trim().toLowerCase();
			var shown = 0;

			index.forEach( function ( entry ) {
				// Rows are only visually hidden. Every input stays in the
				// form, so filtering never alters what gets submitted.
				var match = '' === query || entry.haystack.indexOf( query ) !== -1;
				entry.row.classList.toggle( 'is-filtered-out', ! match );
				if ( match ) {
					shown++;
				}
			} );

			// A group label belongs to the rows that follow it, so it hides
			// once every row under it is filtered out.
			headings.forEach( function ( heading ) {
				var visible = false;
				var node    = heading.nextElementSibling;
				while ( node && ! node.classList.contains( 'wpasb-list-group' ) ) {
					if ( node.classList.contains( 'wpasb-module-row' ) && ! node.classList.contains( 'is-filtered-out' ) ) {
						visible = true;
						break;
					}
					node = node.nextElementSibling;
				}
				heading.classList.toggle( 'is-filtered-out', ! visible );
			} );

			if ( clearBtn ) {
				clearBtn.hidden = '' === query;
			}
			if ( empty ) {
				empty.hidden = 0 !== shown;
			}
			if ( status ) {
				status.textContent = sprintf(
					t( 'searchStatus', 'Showing %1$d of %2$d modules.' ),
					shown,
					rows.length
				);
			}
		}

		input.addEventListener( 'input', function () {
			window.clearTimeout( timer );
			timer = window.setTimeout( apply, 150 );
		} );

		function clear() {
			input.value = '';
			apply();
			input.focus();
		}

		if ( clearBtn ) {
			clearBtn.addEventListener( 'click', clear );
		}
		Array.prototype.forEach.call(
			document.querySelectorAll( '[data-wpasb-clear-search]' ),
			function ( btn ) {
				btn.addEventListener( 'click', clear );
			}
		);

		apply();
	}

	/* ------------------------------------------------------------------
	 * Module state, dependent panels, counters, dirty tracking
	 * ---------------------------------------------------------------- */
	function initModules() {
		var form = document.getElementById( 'wpasb-settings-form' );
		if ( ! form ) {
			return;
		}

		var savebar     = document.getElementById( 'wpasb-savebar' );
		var discardBtn  = document.getElementById( 'wpasb-discard' );
		var saveBtn     = document.getElementById( 'wpasb-save' );
		var toggleAll   = document.getElementById( 'wpasb-toggle-all' );
		var summaryEl   = document.getElementById( 'wpasb-summary-count' );
		var stateEl     = document.getElementById( 'wpasb-summary-state' );
		var rows        = Array.prototype.slice.call( form.querySelectorAll( '.wpasb-module-row' ) );
		var total       = summaryEl ? parseInt( summaryEl.getAttribute( 'data-total' ), 10 ) : rows.length;
		var submitting  = false;
		var cleanupBusy = false;

		// Fields whose value participates in the dirty comparison. Search,
		// disclosures, nonces and cleanup controls are deliberately excluded.
		function trackedFields() {
			return Array.prototype.slice.call(
				form.querySelectorAll( '.wpasb-module-input, #wpasb-login-slug, #wpasb-login-redirect, #wpasb-splash-id' )
			);
		}

		function snapshotOf() {
			var snap = {};
			trackedFields().forEach( function ( field ) {
				snap[ field.id ] = 'checkbox' === field.type ? field.checked : field.value;
			} );
			return snap;
		}

		var snapshot = snapshotOf();

		function isDirty() {
			var current = snapshotOf();
			return Object.keys( snapshot ).some( function ( key ) {
				return snapshot[ key ] !== current[ key ];
			} );
		}

		function updateLoginPreview( on, initial ) {
			var preview   = document.getElementById( 'wpasb-login-preview' );
			var slugInput = document.getElementById( 'wpasb-login-slug' );
			if ( ! preview || ! slugInput ) {
				return;
			}

			var slug     = slugInput.value.trim();
			var url      = ( data.homeUrl || '' ) + slug;
			var pristine = on && initial && slug === preview.getAttribute( 'data-initial-slug' );
			var template = pristine
				? t( 'loginPreviewNow', 'Current login URL: %s' )
				: t( 'loginPreviewNew', 'Login URL after saving: %s' );
			var parts    = String( template ).split( '%s' );

			preview.textContent = '';
			preview.appendChild( document.createTextNode( parts[ 0 ] || '' ) );
			var strong = document.createElement( 'strong' );
			strong.textContent = url;
			preview.appendChild( strong );
			if ( parts[ 1 ] ) {
				preview.appendChild( document.createTextNode( parts[ 1 ] ) );
			}
		}

		function syncRow( row ) {
			var input = row.querySelector( '.wpasb-module-input' );
			if ( ! input ) {
				return;
			}

			var slug    = row.getAttribute( 'data-slug' );
			var initial = '1' === row.getAttribute( 'data-initial' );
			var on      = input.checked;
			var state   = row.querySelector( '.wpasb-row-state' );
			var panel   = row.querySelector( '.wpasb-module-settings' );
			var hint    = row.querySelector( '.wpasb-module-settings-hint' );

			row.classList.toggle( 'is-on', on );
			row.classList.toggle( 'is-off', ! on );
			row.classList.toggle( 'is-changed', on !== initial );

			if ( state ) {
				state.setAttribute( 'data-state', on !== initial ? 'pending' : ( on ? 'on' : 'off' ) );
				// The row has no text status any more, so the state dot
				// carries the meaning for assistive tech as a tooltip.
				if ( on !== initial ) {
					state.title = on
						? t( 'willEnable', 'Will enable when saved' )
						: t( 'willDisable', 'Will disable when saved' );
				} else {
					state.title = on ? t( 'enabled', 'Enabled' ) : t( 'disabled', 'Disabled' );
				}
			}

			if ( panel ) {
				// Move focus out before hiding, or focus stays on an element
				// the user can no longer perceive.
				if ( ! on && panel.contains( document.activeElement ) ) {
					input.focus();
				}
				panel.hidden = ! on;
			}
			if ( hint ) {
				hint.hidden = on;
			}

			if ( 'hide-login' === slug ) {
				updateLoginPreview( on, initial );
			}
		}

		function syncToggleAll() {
			if ( ! toggleAll ) {
				return;
			}
			var checked = form.querySelectorAll( '.wpasb-module-input:checked' ).length;
			var allOn   = checked === rows.length && rows.length > 0;
			toggleAll.textContent = allOn
				? toggleAll.getAttribute( 'data-disable-label' )
				: toggleAll.getAttribute( 'data-enable-label' );
			toggleAll.setAttribute( 'data-mode', allOn ? 'disable' : 'enable' );
		}

		function updateCounts() {
			var active = form.querySelectorAll( '.wpasb-module-input:checked' ).length;
			var dirty  = isDirty();

			if ( summaryEl ) {
				summaryEl.textContent = dirty
					? sprintf( t( 'selectedCount', '%1$d of %2$d selected' ), active, total )
					: sprintf( t( 'enabledCount', '%1$d of %2$d modules enabled' ), active, total );
			}
			if ( stateEl ) {
				stateEl.textContent = dirty ? t( 'unsaved', 'Unsaved changes' ) : t( 'saved', 'Saved configuration' );
				stateEl.classList.toggle( 'is-unsaved', dirty );
			}

			syncToggleAll();

			if ( savebar ) {
				if ( ! dirty && savebar.contains( document.activeElement ) ) {
					var heading = document.querySelector( '.wpasb-module-toolbar .wpasb-section-title' );
					if ( heading ) {
						heading.setAttribute( 'tabindex', '-1' );
						heading.focus();
					}
				}
				savebar.classList.toggle( 'is-visible', dirty );
				savebar.setAttribute( 'aria-hidden', dirty ? 'false' : 'true' );
				document.body.classList.toggle( 'wpasb-savebar-open', dirty );
			}
		}

		function refresh() {
			rows.forEach( syncRow );
			updateCounts();
		}

		if ( toggleAll ) {
			toggleAll.addEventListener( 'click', function () {
				// Acts on every module, not just the rows search left visible,
				// so the label's promise ("Enable all") is always the truth.
				var enable = 'disable' !== toggleAll.getAttribute( 'data-mode' );
				rows.forEach( function ( row ) {
					var input = row.querySelector( '.wpasb-module-input' );
					if ( input ) {
						input.checked = enable;
					}
				} );
				refresh();
			} );
		}

		form.addEventListener( 'change', function ( event ) {
			if ( event.target.classList.contains( 'wpasb-module-input' ) ) {
				refresh();
			}
		} );

		form.addEventListener( 'input', function ( event ) {
			if ( event.target.matches( '#wpasb-login-slug, #wpasb-login-redirect' ) ) {
				var row = event.target.closest( '.wpasb-module-row' );
				if ( row ) {
					syncRow( row );
				}
				updateCounts();
			}
		} );

		// The splash picker writes to a hidden input, which never fires input
		// events on its own, so it dispatches this event instead.
		document.addEventListener( 'wpasb:dirty', updateCounts );

		if ( discardBtn ) {
			discardBtn.addEventListener( 'click', function () {
				trackedFields().forEach( function ( field ) {
					if ( 'checkbox' === field.type ) {
						field.checked = snapshot[ field.id ];
					} else {
						field.value = snapshot[ field.id ];
					}
				} );
				document.dispatchEvent( new CustomEvent( 'wpasb:restore' ) );
				refresh();
			} );
		}

		form.addEventListener( 'submit', function ( event ) {
			if ( submitting || cleanupBusy ) {
				event.preventDefault();
				return;
			}
			submitting = true;
			if ( saveBtn ) {
				saveBtn.textContent = t( 'saving', 'Saving…' );
				saveBtn.setAttribute( 'aria-busy', 'true' );
			}
		} );

		window.addEventListener( 'beforeunload', function ( event ) {
			if ( submitting || ! isDirty() ) {
				return;
			}
			event.preventDefault();
			event.returnValue = '';
		} );

		document.addEventListener( 'wpasb:cleanup-busy', function ( event ) {
			cleanupBusy = !! ( event.detail && event.detail.busy );
		} );

		refresh();
	}

	/* ------------------------------------------------------------------
	 * Splash image picker
	 * ---------------------------------------------------------------- */
	function initSplashPicker() {
		var chooseBtn = document.getElementById( 'wpasb-splash-choose' );
		var field     = document.getElementById( 'wpasb-splash-id' );
		var preview   = document.getElementById( 'wpasb-splash-preview-img' );
		var note      = document.getElementById( 'wpasb-splash-default-note' );
		var resetBtn  = document.getElementById( 'wpasb-splash-reset' );
		var errorEl   = document.getElementById( 'wpasb-splash-error' );

		if ( ! chooseBtn || ! field || ! preview ) {
			return;
		}

		var config = window.wpasbLogin || {};
		var frame  = null;

		function markDirty() {
			document.dispatchEvent( new CustomEvent( 'wpasb:dirty' ) );
		}

		function paint( url, isCustom ) {
			if ( url ) {
				preview.src = url;
			}
			if ( note ) {
				note.textContent = isCustom
					? ( note.getAttribute( 'data-custom-label' ) || t( 'customImage', 'Custom image' ) )
					: ( note.getAttribute( 'data-default-label' ) || t( 'defaultImage', 'Bundled default image' ) );
			}
			if ( resetBtn ) {
				resetBtn.hidden = ! isCustom;
			}
		}

		chooseBtn.addEventListener( 'click', function () {
			if ( errorEl ) {
				errorEl.hidden = true;
			}

			if ( ! window.wp || ! wp.media ) {
				// Say what happened instead of silently doing nothing.
				if ( errorEl ) {
					errorEl.textContent = t( 'mediaFailed', 'The media library could not open. Reload this page and try again.' );
					errorEl.hidden = false;
				}
				return;
			}

			if ( ! frame ) {
				frame = wp.media( {
					title: config.frameTitle || '',
					button: { text: config.frameButton || '' },
					library: { type: 'image' },
					multiple: false
				} );

				frame.on( 'select', function () {
					var att = frame.state().get( 'selection' ).first().toJSON();
					if ( ! att || ! att.id ) {
						return;
					}
					field.value = att.id;
					paint( att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url, true );
					markDirty();
				} );
			}

			frame.open();
		} );

		if ( resetBtn ) {
			resetBtn.addEventListener( 'click', function () {
				field.value = '0';
				paint( config.defaultUrl || '', false );
				markDirty();
				chooseBtn.focus();
			} );
		}

		document.addEventListener( 'wpasb:restore', function () {
			if ( ! parseInt( field.value, 10 ) ) {
				paint( config.defaultUrl || '', false );
			}
		} );
	}

	/* ------------------------------------------------------------------
	 * Database cleanup over AJAX
	 * ---------------------------------------------------------------- */
	function initCleanup() {
		var section = document.getElementById( 'wpasb-cleanup' );
		if ( ! section ) {
			return;
		}

		var reviewBtn  = document.getElementById( 'wpasb-cleanup-review' );
		var confirm    = document.getElementById( 'wpasb-cleanup-confirm' );
		var confirmTtl = document.getElementById( 'wpasb-cleanup-confirm-title' );
		var actions    = document.getElementById( 'wpasb-cleanup-actions' );
		var cancelBtn  = document.getElementById( 'wpasb-cleanup-cancel' );
		var runBtn     = document.getElementById( 'wpasb-cleanup-run' );
		var running    = document.getElementById( 'wpasb-cleanup-running' );
		var runningTxt = running ? running.querySelector( '.wpasb-cleanup-running-text' ) : null;
		var result     = document.getElementById( 'wpasb-cleanup-result' );
		var live       = document.getElementById( 'wpasb-cleanup-announcement' );
		var againBtn   = document.getElementById( 'wpasb-cleanup-again' );

		if ( ! reviewBtn || ! confirm || ! runBtn || ! window.fetch ) {
			return;
		}

		var busy      = false;
		var slowTimer = null;

		function setBusy( state ) {
			busy = state;
			document.dispatchEvent( new CustomEvent( 'wpasb:cleanup-busy', { detail: { busy: state } } ) );
		}

		function openConfirm() {
			if ( busy ) {
				return;
			}
			confirm.hidden = false;
			if ( result ) {
				result.hidden = true;
			}
			if ( confirmTtl ) {
				confirmTtl.focus();
			}
		}

		function closeConfirm( focusReview ) {
			confirm.hidden = true;
			if ( focusReview ) {
				reviewBtn.focus();
			}
		}

		reviewBtn.addEventListener( 'click', openConfirm );
		if ( againBtn ) {
			againBtn.addEventListener( 'click', openConfirm );
		}
		if ( cancelBtn ) {
			cancelBtn.addEventListener( 'click', function () {
				closeConfirm( true );
			} );
		}

		confirm.addEventListener( 'keydown', function ( event ) {
			// Escape applies only while the review panel holds focus: this is
			// an inline disclosure, not a modal, so nothing is trapped.
			if ( 'Escape' === event.key && ! busy ) {
				closeConfirm( true );
			}
		} );

		function setValue( key, value ) {
			var cell = result ? result.querySelector( '[data-key="' + key + '"] .wpasb-cleanup-result-value' ) : null;
			if ( cell ) {
				cell.textContent = value;
			}
		}

		function showResult( variant, title, message, report, note ) {
			if ( ! result ) {
				return;
			}

			result.className = 'wpasb-cleanup-result wpasb-cleanup-result--' + variant;

			var titleEl = result.querySelector( '#wpasb-cleanup-result-title' );
			var msgEl   = result.querySelector( '.wpasb-cleanup-result-message' );
			var noteEl  = result.querySelector( '.wpasb-cleanup-result-note' );
			var grid    = result.querySelector( '.wpasb-cleanup-result-grid' );

			if ( titleEl ) {
				titleEl.textContent = title;
			}
			if ( msgEl ) {
				msgEl.textContent = message || '';
				msgEl.hidden = ! message;
			}

			if ( report ) {
				[ 'revisions', 'transients', 'orphan_meta', 'tables', 'skipped_innodb' ].forEach( function ( key ) {
					var value = report[ key ];
					// Real zeroes are shown. Only genuinely missing values read
					// as "Not available" — never as a fabricated 0.
					setValue(
						key,
						( 'number' === typeof value && isFinite( value ) )
							? value.toLocaleString()
							: t( 'notAvailable', 'Not available' )
					);
				} );
				if ( grid ) {
					grid.hidden = false;
				}
			} else if ( grid ) {
				grid.hidden = true;
			}

			if ( noteEl ) {
				noteEl.textContent = note || '';
				noteEl.hidden = ! note;
			}

			result.hidden = false;
			if ( titleEl ) {
				titleEl.focus();
			}
			announce( live, title + ( message ? '. ' + message : '' ) );
		}

		function startRunning() {
			setBusy( true );
			section.setAttribute( 'aria-busy', 'true' );
			runBtn.disabled    = true;
			reviewBtn.disabled = true;
			if ( cancelBtn ) {
				cancelBtn.disabled = true;
			}
			if ( actions ) {
				actions.hidden = true;
			}
			if ( running ) {
				running.hidden = false;
				if ( runningTxt ) {
					runningTxt.textContent = t( 'cleaning', 'Cleaning database…' );
				}
			}
			announce( live, t( 'cleanStarted', 'Database cleanup started.' ) );

			// No fake progress: after 15s just say it is taking longer.
			slowTimer = window.setTimeout( function () {
				if ( runningTxt ) {
					runningTxt.textContent = t( 'cleanSlow', 'Still working. Larger databases can take longer.' );
				}
			}, 15000 );
		}

		function stopRunning() {
			window.clearTimeout( slowTimer );
			setBusy( false );
			section.removeAttribute( 'aria-busy' );
			runBtn.disabled    = false;
			reviewBtn.disabled = false;
			if ( cancelBtn ) {
				cancelBtn.disabled = false;
			}
			if ( actions ) {
				actions.hidden = false;
			}
			if ( running ) {
				running.hidden = true;
			}
			confirm.hidden = true;
		}

		runBtn.addEventListener( 'click', function () {
			if ( busy ) {
				return;
			}

			startRunning();

			var body = 'action=wpasb_cleanup&nonce=' + encodeURIComponent( data.nonce || '' );

			window.fetch( data.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body
			} )
				.then( function ( response ) {
					return response.json().then( function ( payload ) {
						return { ok: response.ok, payload: payload };
					} );
				} )
				.then( function ( res ) {
					stopRunning();
					var payload = ( res.payload && res.payload.data ) || {};

					if ( res.ok && res.payload && res.payload.success ) {
						showResult(
							'success',
							t( 'cleanDone', 'Cleanup complete' ),
							payload.message || '',
							payload.report || null,
							payload.note || ''
						);
					} else {
						showResult(
							'error',
							t( 'cleanFailed', 'Cleanup could not finish' ),
							payload.message || t( 'cleanGeneric', 'The cleanup could not be completed. Nothing further was deleted.' ),
							null,
							''
						);
					}
				} )
				.catch( function () {
					// No readable answer arrived, so the server may or may not
					// have finished. Say exactly that; never auto-retry
					// destructive work.
					stopRunning();
					showResult(
						'unknown',
						t( 'cleanUnknown', 'Cleanup status unknown' ),
						t( 'cleanLost', 'The connection ended before a result arrived.' ),
						null,
						''
					);
				} );
		} );
	}

	/* ------------------------------------------------------------------
	 * Copy-to-clipboard for the code blocks
	 * ---------------------------------------------------------------- */
	function initCopy() {
		Array.prototype.forEach.call( document.querySelectorAll( '.wpasb-copy' ), function ( btn ) {
			var label  = btn.querySelector( '.wpasb-copy-label' );
			var target = document.getElementById( btn.getAttribute( 'data-copy-target' ) );
			var panel  = btn.closest( '.wpasb-panel' );
			var status = panel ? panel.querySelector( '.wpasb-copy-status' ) : null;

			if ( ! target ) {
				return;
			}

			btn.addEventListener( 'click', function () {
				function done() {
					if ( label ) {
						label.textContent = t( 'copied', 'Copied' );
						btn.classList.add( 'is-copied' );
						window.setTimeout( function () {
							label.textContent = t( 'copyCode', 'Copy code' );
							btn.classList.remove( 'is-copied' );
						}, 2000 );
					}
					announce( status, t( 'copied', 'Copied' ) );
				}

				function failed() {
					announce( status, t( 'copyFailed', 'Could not copy automatically. Select and copy the code below.' ) );
				}

				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( target.textContent ).then( done, failed );
				} else {
					failed();
				}
			} );
		} );
	}

	function boot() {
		[ initSearch, initModules, initSplashPicker, initCleanup, initCopy ].forEach( function ( fn ) {
			try {
				fn();
			} catch ( e ) {
				if ( window.console && window.console.error ) {
					window.console.error( 'Admin Speedboost:', e );
				}
			}
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();
