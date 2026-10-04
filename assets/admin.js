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
			.replace( /%(\d)\$[sd]/g, function ( _, n ) {
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
	 * Database cleanup: scan, review and consent, then run with live progress
	 *
	 * Nothing is deleted until the scan has been shown and the user has ticked
	 * the confirmation. The run is a loop of small server batches, so the
	 * percentage and the checklist reflect rows actually removed.
	 *
	 * Every string that came from the database is written with textContent,
	 * never innerHTML: a post title can contain markup.
	 * ---------------------------------------------------------------- */
	function initCleanup() {
		var section = document.getElementById( 'wpasb-cleanup' );
		if ( ! section || ! window.fetch ) {
			return;
		}

		function byId( id ) {
			return document.getElementById( id );
		}

		var el = {
			scanBtn:   byId( 'wpasb-cleanup-scan' ),
			scanning:  byId( 'wpasb-cleanup-scanning' ),
			scanText:  section.querySelector( '.wpasb-cleanup-scanning-text' ),
			review:    byId( 'wpasb-cleanup-review' ),
			reviewTtl: byId( 'wpasb-cleanup-review-title' ),
			summary:   byId( 'wpasb-cleanup-summary' ),
			cats:      byId( 'wpasb-cleanup-cats' ),
			note:      byId( 'wpasb-cleanup-note' ),
			consentBox: byId( 'wpasb-cleanup-consent-wrap' ),
			consent:   byId( 'wpasb-cleanup-consent' ),
			cancelBtn: byId( 'wpasb-cleanup-cancel' ),
			runBtn:    byId( 'wpasb-cleanup-run' ),
			gate:      byId( 'wpasb-cleanup-gate' ),
			progress:  byId( 'wpasb-cleanup-progress' ),
			progTitle: byId( 'wpasb-cleanup-progress-title' ),
			percent:   byId( 'wpasb-cleanup-percent' ),
			bar:       byId( 'wpasb-cleanup-bar' ),
			fill:      byId( 'wpasb-cleanup-bar-fill' ),
			steps:     byId( 'wpasb-cleanup-steps' ),
			status:    byId( 'wpasb-cleanup-status' ),
			result:    byId( 'wpasb-cleanup-result' ),
			again:     byId( 'wpasb-cleanup-again' ),
			live:      byId( 'wpasb-cleanup-announcement' )
		};

		if ( ! el.scanBtn || ! el.review || ! el.cats || ! el.consent || ! el.runBtn || ! el.progress || ! el.steps ) {
			return;
		}

		// The button ships hidden, so a page without working JS never shows a
		// scan it cannot run.
		el.scanBtn.hidden = false;

		var busy    = false; // a scan or a run is in flight
		var running = false; // the destructive run is in flight
		var scan    = null;  // payload of the last scan
		var picked  = {};    // category key -> included in the run
		var token   = '';    // session token for the run
		var timers  = { slow: null };

		var MIN_STEP_MS = 650; // so a fast database still shows each step

		/* ---------------------------------------------------------- helpers */

		function h( tag, className ) {
			var node = document.createElement( tag );
			if ( className ) {
				node.className = className;
			}
			return node;
		}

		function num( n ) {
			return Number( n || 0 ).toLocaleString();
		}

		function plural( n, oneKey, manyKey, oneFallback, manyFallback ) {
			return 1 === n
				? sprintf( t( oneKey, oneFallback ), num( n ) )
				: sprintf( t( manyKey, manyFallback ), num( n ) );
		}

		function wait( ms ) {
			return new Promise( function ( resolve ) {
				window.setTimeout( resolve, Math.max( 0, ms ) );
			} );
		}

		function setBusy( state ) {
			busy = state;
			el.scanBtn.disabled = state;
		}

		function setRunning( state ) {
			running = state;
			// Lets the settings form refuse a submit while rows are being deleted.
			document.dispatchEvent( new CustomEvent( 'wpasb:cleanup-busy', { detail: { busy: state } } ) );
		}

		/**
		 * One admin-ajax call. Rejects with kind 'network' when no readable
		 * answer arrived (the server may or may not have finished), or kind
		 * 'server' with the server's own message.
		 */
		function post( action, params ) {
			var body = new URLSearchParams();
			body.set( 'action', action );
			body.set( 'nonce', data.nonce || '' );
			Object.keys( params || {} ).forEach( function ( key ) {
				body.set( key, params[ key ] );
			} );

			return window.fetch( data.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString()
			} ).then(
				function ( response ) {
					return response.json().then(
						function ( payload ) {
							if ( response.ok && payload && payload.success ) {
								return payload.data || {};
							}
							var err = new Error( ( payload && payload.data && payload.data.message ) || '' );
							err.kind = 'server';
							throw err;
						},
						function () {
							var err = new Error( '' );
							err.kind = 'server';
							throw err;
						}
					);
				},
				function () {
					var err = new Error( '' );
					err.kind = 'network';
					throw err;
				}
			);
		}

		/* ------------------------------------------------------------ panels */

		function hideAll() {
			el.scanning.hidden = true;
			el.review.hidden   = true;
			el.progress.hidden = true;
			if ( el.result ) {
				el.result.hidden = true;
			}
			section.setAttribute( 'data-state', 'idle' );
		}

		function setValue( key, value ) {
			var cell = el.result ? el.result.querySelector( '[data-key="' + key + '"] .wpasb-cleanup-result-value' ) : null;
			if ( cell ) {
				cell.textContent = value;
			}
		}

		function showResult( variant, title, message, report, note ) {
			if ( ! el.result ) {
				return;
			}

			el.result.className = 'wpasb-cleanup-result wpasb-cleanup-result--' + variant;

			var titleEl = el.result.querySelector( '#wpasb-cleanup-result-title' );
			var msgEl   = el.result.querySelector( '.wpasb-cleanup-result-message' );
			var noteEl  = el.result.querySelector( '.wpasb-cleanup-result-note' );
			var grid    = el.result.querySelector( '.wpasb-cleanup-result-grid' );

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
					// as "Not available", never as a fabricated 0.
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

			el.result.hidden = false;
			if ( titleEl ) {
				titleEl.focus();
			}
			announce( el.live, title + ( message ? '. ' + message : '' ) );
		}

		/* -------------------------------------------------------------- scan */

		function startScan() {
			if ( busy ) {
				return;
			}

			setBusy( true );
			hideAll();
			section.setAttribute( 'data-state', 'scanning' );
			el.scanning.hidden = false;
			if ( el.scanText ) {
				el.scanText.textContent = t( 'scanning', 'Scanning your database…' );
			}

			timers.slow = window.setTimeout( function () {
				if ( el.scanText ) {
					el.scanText.textContent = t( 'scanSlow', 'Still scanning. Larger databases can take longer.' );
				}
			}, 15000 );

			post( 'wpasb_cleanup_scan' ).then(
				function ( payload ) {
					window.clearTimeout( timers.slow );
					setBusy( false );
					scan = payload;
					renderReview( payload );
				},
				function ( err ) {
					window.clearTimeout( timers.slow );
					setBusy( false );
					hideAll();
					showResult(
						'error',
						t( 'scanFailed', 'The scan could not finish' ),
						err.message || t( 'scanGeneric', 'The scan could not be completed. Nothing was deleted.' ),
						null,
						''
					);
				}
			);
		}

		/* ------------------------------------------------------------ review */

		function selectedItems() {
			if ( ! scan ) {
				return 0;
			}
			return scan.categories.reduce( function ( sum, cat ) {
				return sum + ( picked[ cat.key ] ? cat.count : 0 );
			}, 0 );
		}

		function refreshGate() {
			var items = selectedItems();
			var ready = items > 0 && el.consent.checked;

			el.runBtn.disabled = ! ready;
			el.runBtn.textContent = items > 0
				? sprintf( t( 1 === items ? 'runOne' : 'runMany', 1 === items ? 'Clean up %s item' : 'Clean up %s items' ), num( items ) )
				: t( 'runNone', 'Clean up' );

			if ( ready ) {
				el.gate.textContent = '';
			} else {
				el.gate.textContent = items < 1
					? t( 'gatePick', 'Select at least one category to continue.' )
					: t( 'gateConsent', 'Tick the confirmation to continue.' );
			}
		}

		function appendRows( list, rows, muteTagged ) {
			rows.forEach( function ( row ) {
				var li   = h( 'li', 'wpasb-rec' );
				var main = h( 'span', 'wpasb-rec-main' );
				var ttl  = h( 'span', 'wpasb-rec-title' );
				var det  = h( 'span', 'wpasb-rec-detail' );

				ttl.textContent = row.title || '';
				ttl.title       = row.title || '';
				det.textContent = row.detail || '';
				main.appendChild( ttl );
				main.appendChild( det );
				li.appendChild( main );

				if ( row.tag || row.extra ) {
					var side = h( 'span', 'wpasb-rec-side' );
					if ( row.tag ) {
						var tag = h( 'span', 'wpasb-rec-tag' );
						tag.textContent = row.tag;
						side.appendChild( tag );
						if ( muteTagged ) {
							li.classList.add( 'is-muted' );
						}
					}
					if ( row.extra ) {
						var extra = h( 'span', 'wpasb-rec-extra' );
						extra.textContent = row.extra;
						side.appendChild( extra );
					}
					li.appendChild( side );
				}

				list.appendChild( li );
			} );
		}

		function buildCategory( cat ) {
			var empty  = cat.count < 1;
			var li     = h( 'li', 'wpasb-cat' + ( empty ? ' is-empty' : '' ) );
			var head   = h( 'div', 'wpasb-cat-head' );
			var inputId = 'wpasb-cat-' + cat.key;

			li.setAttribute( 'data-key', cat.key );

			// Include checkbox.
			var check = h( 'span', 'wpasb-check wpasb-cat-check' );
			var input = h( 'input', 'wpasb-check-input' );
			var box   = h( 'span', 'wpasb-check-box' );
			input.type     = 'checkbox';
			input.id       = inputId;
			input.checked  = ! empty;
			input.disabled = empty;
			input.setAttribute( 'aria-label', sprintf( t( 'includeCat', 'Include %s' ), cat.label ) );
			box.setAttribute( 'aria-hidden', 'true' );
			check.appendChild( input );
			check.appendChild( box );
			head.appendChild( check );

			// Name and description. The label makes the whole block a click
			// target for the checkbox.
			var text = h( 'label', 'wpasb-cat-text' );
			var name = h( 'span', 'wpasb-cat-name' );
			var desc = h( 'span', 'wpasb-cat-desc' );
			text.setAttribute( 'for', inputId );
			name.textContent = cat.label;
			desc.textContent = cat.description;
			text.appendChild( name );
			text.appendChild( desc );
			head.appendChild( text );

			// Count and size.
			var meta  = h( 'div', 'wpasb-cat-meta' );
			var count = h( 'span', 'wpasb-cat-count' );
			if ( empty ) {
				count.textContent = t( 'nothingToClean', 'Nothing to clean' );
				count.classList.add( 'is-muted' );
			} else if ( 'tables' === cat.key ) {
				count.textContent = plural( cat.count, 'tablesOne', 'tablesMany', '%s table', '%s tables' );
			} else {
				count.textContent = plural( cat.count, 'recordsOne', 'recordsMany', '%s record', '%s records' );
			}
			meta.appendChild( count );
			if ( cat.size && ! empty ) {
				var size = h( 'span', 'wpasb-cat-size' );
				size.textContent = cat.size;
				meta.appendChild( size );
			}
			head.appendChild( meta );

			li.appendChild( head );

			input.addEventListener( 'change', function () {
				picked[ cat.key ] = input.checked;
				li.classList.toggle( 'is-off', ! input.checked );
				refreshGate();
			} );

			// Records behind the count.
			if ( cat.rows && cat.rows.length ) {
				var bodyId = 'wpasb-cat-body-' + cat.key;
				var toggle = h( 'button', 'wpasb-cat-toggle' );
				var body   = h( 'div', 'wpasb-cat-body' );
				var list   = h( 'ul', 'wpasb-recs' );
				var loaded = cat.rows.length;

				toggle.type = 'button';
				toggle.textContent = t( 'viewRecords', 'View records' );
				toggle.setAttribute( 'aria-expanded', 'false' );
				toggle.setAttribute( 'aria-controls', bodyId );
				head.appendChild( toggle );

				body.id     = bodyId;
				body.hidden = true;
				list.setAttribute( 'role', 'region' );
				list.setAttribute( 'tabindex', '0' );
				list.setAttribute( 'aria-label', cat.label );
				appendRows( list, cat.rows, 'tables' === cat.key );
				body.appendChild( list );

				if ( cat.has_more ) {
					var more = h( 'button', 'wpasb-link-button wpasb-cat-more' );
					more.type = 'button';

					var paintMore = function () {
						more.disabled    = false;
						more.textContent = sprintf( t( 'showMoreLeft', 'Show more (%s left)' ), num( Math.max( 0, cat.count - loaded ) ) );
					};
					paintMore();

					more.addEventListener( 'click', function () {
						more.disabled    = true;
						more.textContent = t( 'loadingMore', 'Loading…' );

						post( 'wpasb_cleanup_list', { category: cat.key, cutoff: cat.cutoff, offset: loaded } ).then(
							function ( page ) {
								appendRows( list, page.rows || [], 'tables' === cat.key );
								loaded += ( page.rows || [] ).length;
								if ( page.has_more ) {
									paintMore();
								} else {
									more.parentNode.removeChild( more );
								}
							},
							function ( err ) {
								more.disabled    = false;
								more.textContent = err.message || t( 'listFailed', 'The records could not be loaded.' );
							}
						);
					} );

					body.appendChild( more );
				}

				li.appendChild( body );

				toggle.addEventListener( 'click', function () {
					var open = 'true' !== toggle.getAttribute( 'aria-expanded' );
					toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
					toggle.textContent = open ? t( 'hideRecords', 'Hide records' ) : t( 'viewRecords', 'View records' );
					body.hidden = ! open;
				} );
			}

			return li;
		}

		function renderReview( payload ) {
			var cats = payload.categories || [];
			picked = {};
			el.cats.textContent = '';

			cats.forEach( function ( cat ) {
				picked[ cat.key ] = cat.count > 0;
				el.cats.appendChild( buildCategory( cat ) );
			} );

			var records = payload.records || 0;
			var tables  = payload.tables || 0;
			var nothing = ( records + tables ) < 1;
			var parts   = [];

			if ( nothing ) {
				el.summary.textContent = t( 'nothingFound', 'Nothing to clean. Your database is already tidy.' );
			} else {
				if ( records > 0 ) {
					parts.push( plural( records, 'recordsOne', 'recordsMany', '%s record', '%s records' ) );
				}
				if ( tables > 0 ) {
					parts.push( sprintf( t( 'tablesToDo', '%s to optimise' ), plural( tables, 'tablesOne', 'tablesMany', '%s table', '%s tables' ) ) );
				}
				if ( payload.size ) {
					parts.push( sprintf( t( 'aboutSize', 'about %s' ), payload.size ) );
				}
				el.summary.textContent = parts.join( ' · ' );
			}

			el.note.textContent = payload.note || '';
			el.note.hidden      = ! payload.note;

			el.consent.checked      = false;
			el.consentBox.hidden    = nothing;
			el.runBtn.hidden        = nothing;
			el.gate.hidden          = nothing;
			el.cancelBtn.textContent = nothing ? t( 'close', 'Close' ) : t( 'cancel', 'Cancel' );
			refreshGate();

			hideAll();
			section.setAttribute( 'data-state', 'review' );
			el.review.hidden = false;
			el.reviewTtl.focus();
			announce(
				el.live,
				sprintf( t( 'scanDone', 'Scan complete. %s found.' ), nothing ? t( 'nothingToClean', 'Nothing to clean' ) : parts.join( ', ' ) )
			);
		}

		/* ---------------------------------------------------------- progress */

		var STEP_LABELS = {
			revisions:          [ 'stepRevisions', 'Deleting post revisions' ],
			transients:         [ 'stepTransients', 'Removing expired transients' ],
			orphan_postmeta:    [ 'stepOrphanPostmeta', 'Removing orphaned post meta' ],
			orphan_commentmeta: [ 'stepOrphanCommentmeta', 'Removing orphaned comment meta' ],
			tables:             [ 'stepTables', 'Optimising database tables' ],
			finish:             [ 'stepFinish', 'Refreshing caches' ]
		};

		var TICK_SVG = '<svg viewBox="0 0 16 16" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false">'
			+ '<path class="wpasb-tick" pathLength="1" d="M3.5 8.5 6.5 11.5 12.5 4.5"/>'
			+ '<path class="wpasb-bang" d="M8 4v5M8 11.75h.01"/>'
			+ '<path class="wpasb-cross" d="M4.5 4.5l7 7M11.5 4.5l-7 7"/>'
			+ '</svg>';

		var plan = [];
		var shown = 0;
		var target = 0;
		var raf = null;

		function buildPlan() {
			var steps = [];
			var base  = 0;

			scan.categories.forEach( function ( cat ) {
				if ( picked[ cat.key ] && cat.count > 0 ) {
					steps.push( { key: cat.key, count: cat.count, cutoff: cat.cutoff, done: 0, remaining: cat.count, progress: 0, weight: cat.count } );
					if ( 'tables' !== cat.key ) {
						base += cat.count;
					}
				}
			} );

			// Optimising a table takes a while whatever the row count, so it
			// gets a floor of the bar rather than a sliver at the end.
			steps.forEach( function ( step ) {
				if ( 'tables' === step.key ) {
					step.weight = Math.max( step.count, Math.round( base * 0.08 ), 1 );
				}
			} );

			var total = steps.reduce( function ( sum, step ) {
				return sum + step.weight;
			}, 0 );
			steps.push( { key: 'finish', count: 1, cutoff: 0, done: 0, remaining: 0, progress: 0, weight: Math.max( 1, Math.round( total * 0.04 ) ) } );

			return steps;
		}

		function stepLabel( step ) {
			var pair = STEP_LABELS[ step.key ];
			return t( pair[ 0 ], pair[ 1 ] );
		}

		function buildStepNode( step, index ) {
			var li     = h( 'li', 'wpasb-step is-pending' );
			var icon   = h( 'span', 'wpasb-step-icon' );
			var text   = h( 'span', 'wpasb-step-text' );
			var label  = h( 'span', 'wpasb-step-label' );
			var detail = h( 'span', 'wpasb-step-detail' );
			var meter  = h( 'span', 'wpasb-step-meter' );
			var fill   = h( 'span', 'wpasb-step-meter-fill' );

			li.style.setProperty( '--i', index );
			li.setAttribute( 'data-key', step.key );
			icon.setAttribute( 'aria-hidden', 'true' );
			icon.innerHTML = TICK_SVG; // constant markup, no data in it.
			label.textContent  = stepLabel( step );
			detail.textContent = 'finish' === step.key
				? ''
				: sprintf( t( 'detailQueued', '%s waiting' ), num( step.count ) );

			meter.setAttribute( 'aria-hidden', 'true' );
			meter.appendChild( fill );
			text.appendChild( label );
			text.appendChild( detail );
			text.appendChild( meter );
			li.appendChild( icon );
			li.appendChild( text );

			step.node   = li;
			step.detail = detail;
			step.fill   = fill;
			return li;
		}

		function setStepState( step, state ) {
			step.node.className = 'wpasb-step is-' + state;
		}

		function paintStep( step ) {
			var frac = Math.max( 0, Math.min( 1, step.progress ) );
			step.fill.style.width = ( frac * 100 ) + '%';

			if ( 'finish' === step.key ) {
				return;
			}
			step.detail.textContent = sprintf(
				t( 'detailProgress', '%1$s of %2$s' ),
				num( Math.round( step.count * frac ) ),
				num( step.count )
			);
		}

		function overall() {
			var total = 0;
			var done  = 0;
			plan.forEach( function ( step ) {
				total += step.weight;
				done  += step.weight * Math.max( 0, Math.min( 1, step.progress ) );
			} );
			return total > 0 ? ( done / total ) * 100 : 0;
		}

		function setPercent( pct, immediate ) {
			target = Math.max( 0, Math.min( 100, pct ) );
			el.fill.style.width = target + '%';
			el.bar.setAttribute( 'aria-valuenow', String( Math.round( target ) ) );

			if ( immediate ) {
				shown = target;
				el.percent.textContent = Math.round( shown ) + '%';
				return;
			}
			if ( raf ) {
				return;
			}

			var tick = function () {
				var diff = target - shown;
				shown = Math.abs( diff ) < 0.5 ? target : shown + diff * 0.2;
				el.percent.textContent = Math.round( shown ) + '%';
				raf = shown === target ? null : window.requestAnimationFrame( tick );
			};
			raf = window.requestAnimationFrame( tick );
		}

		// While work is underway the bar never claims 100%: that is reserved
		// for the moment the last step has really finished.
		function pushProgress() {
			setPercent( Math.min( 99, overall() ) );
			armSlowTimer();
		}

		function armSlowTimer() {
			window.clearTimeout( timers.slow );
			el.status.textContent = '';
			timers.slow = window.setTimeout( function () {
				el.status.textContent = t( 'cleanSlow', 'Still working. Larger databases can take longer.' );
			}, 15000 );
		}

		function collectReport() {
			var report = { revisions: 0, transients: 0, orphan_meta: 0, tables: 0, skipped_innodb: scan.skipped_innodb || 0 };
			plan.forEach( function ( step ) {
				if ( 'revisions' === step.key ) {
					report.revisions += step.done;
				} else if ( 'transients' === step.key ) {
					report.transients += step.done;
				} else if ( 'orphan_postmeta' === step.key || 'orphan_commentmeta' === step.key ) {
					report.orphan_meta += step.done;
				} else if ( 'tables' === step.key ) {
					report.tables += step.done;
				}
			} );
			return report;
		}

		/* --------------------------------------------------------- the run */

		function loopRecords( step ) {
			return post( 'wpasb_cleanup_step', { category: step.key, cutoff: step.cutoff, token: token } ).then( function ( res ) {
				step.done     += res.processed || 0;
				step.remaining = res.remaining || 0;
				step.progress  = Math.max( step.progress, ( step.count - step.remaining ) / step.count );
				paintStep( step );
				pushProgress();

				if ( res.stalled ) {
					step.stalled = true;
					return null;
				}
				if ( step.remaining > 0 && ( res.processed || 0 ) > 0 ) {
					return loopRecords( step );
				}
				return null;
			} );
		}

		function loopTables( step, index ) {
			index = index || 0;
			if ( index >= step.count ) {
				return Promise.resolve();
			}
			return post( 'wpasb_cleanup_step', { category: 'tables', index: index, token: token } ).then( function ( res ) {
				step.done    += res.processed || 0;
				step.progress = ( index + 1 ) / step.count;
				paintStep( step );
				pushProgress();
				return loopTables( step, index + 1 );
			} );
		}

		function activate( step ) {
			setStepState( step, 'active' );
			step.node.scrollIntoView && step.node.scrollIntoView( { block: 'nearest', behavior: 'smooth' } );
			if ( 'finish' !== step.key ) {
				paintStep( step );
			}
		}

		function complete( step ) {
			var partial = step.remaining > 0 && 'tables' !== step.key;

			step.progress = 1;
			step.fill.style.width = '100%';

			if ( 'finish' === step.key ) {
				step.detail.textContent = t( 'detailDone', 'Done' );
			} else if ( 'tables' === step.key ) {
				step.detail.textContent = sprintf( t( 'detailOptimised', '%s optimised' ), num( step.done ) );
			} else if ( partial ) {
				step.detail.textContent = sprintf( t( 'detailPartial', '%1$s removed, %2$s could not be' ), num( step.done ), num( step.remaining ) );
			} else {
				step.detail.textContent = sprintf( t( 'detailDeleted', '%s deleted' ), num( step.done ) );
			}

			step.partial = partial;
			setStepState( step, partial ? 'warn' : 'done' );
			announce( el.live, sprintf( t( 'stepFinished', '%s: done.' ), stepLabel( step ) ) );
			setPercent( Math.min( 99, overall() ) );
		}

		function runStep( step ) {
			var started = Date.now();
			activate( step );

			var work = 'finish' === step.key
				? post( 'wpasb_cleanup_finish', { token: token } )
				: ( 'tables' === step.key ? loopTables( step ) : loopRecords( step ) );

			return work.then( function () {
				return wait( ( 'finish' === step.key ? 450 : MIN_STEP_MS ) - ( Date.now() - started ) );
			} ).then( function () {
				complete( step );
			} );
		}

		function failActiveStep() {
			plan.forEach( function ( step ) {
				if ( step.node && step.node.classList.contains( 'is-active' ) ) {
					setStepState( step, 'failed' );
					step.detail.textContent = t( 'detailFailed', 'Stopped' );
				}
			} );
		}

		function finishRun() {
			window.clearTimeout( timers.slow );
			el.status.textContent = '';
			setRunning( false );
			setBusy( false );
			section.removeAttribute( 'aria-busy' );

			var report  = collectReport();
			var partial = plan.some( function ( step ) {
				return step.partial;
			} );

			setPercent( 100, true );
			el.progress.classList.add( 'is-complete' );
			el.progTitle.textContent = t( 'cleanDone', 'Cleanup complete' );

			var note = scan.note || '';
			if ( partial ) {
				note = ( note ? note + ' ' : '' ) + t( 'cleanSomeLeft', 'Some records could not be removed and are still there. Scan again to see which.' );
			}

			showResult( 'success', t( 'cleanDone', 'Cleanup complete' ), t( 'cleanDoneMsg', 'The database was cleaned. Here is what changed.' ), report, note );

			if ( ! partial ) {
				celebrate();
			}
		}

		function failRun( err ) {
			window.clearTimeout( timers.slow );
			el.status.textContent = '';
			setRunning( false );
			setBusy( false );
			section.removeAttribute( 'aria-busy' );
			failActiveStep();
			el.progTitle.textContent = t( 'cleanFailed', 'Cleanup could not finish' );

			if ( err && 'network' === err.kind ) {
				// No readable answer: the server may still be working. Say so,
				// and never invite an immediate retry of a destructive run.
				showResult( 'unknown', t( 'cleanUnknown', 'Cleanup status unknown' ), t( 'cleanLost', 'The connection ended before a result arrived. The cleanup may still be running. Reload the page in a minute before trying again.' ), null, '' );
				return;
			}

			// The run is over either way; hand the lock back so a new scan can start.
			post( 'wpasb_cleanup_finish', { token: token } ).then( null, function () {} );

			showResult(
				'error',
				t( 'cleanFailed', 'Cleanup could not finish' ),
				( err && err.message ) || t( 'cleanPartial', 'The cleanup stopped part-way. Steps that already finished are not rolled back.' ),
				collectReport(),
				''
			);
		}

		function startRun() {
			if ( busy || el.runBtn.disabled ) {
				return;
			}

			plan = buildPlan();
			if ( plan.length < 2 ) {
				return;
			}

			setBusy( true );
			setRunning( true );
			section.setAttribute( 'aria-busy', 'true' );
			hideAll();
			section.setAttribute( 'data-state', 'running' );

			el.progress.classList.remove( 'is-complete' );
			el.progress.hidden       = false;
			el.progTitle.textContent = t( 'cleaning', 'Cleaning database…' );
			el.status.textContent    = '';
			el.steps.textContent     = '';
			plan.forEach( function ( step, index ) {
				el.steps.appendChild( buildStepNode( step, index ) );
			} );
			setPercent( 0, true );
			el.progTitle.focus();
			announce( el.live, t( 'cleanStarted', 'Database cleanup started.' ) );
			armSlowTimer();

			var chain = post( 'wpasb_cleanup_start' ).then( function ( res ) {
				token = res.token || '';
			} );

			plan.forEach( function ( step ) {
				chain = chain.then( function () {
					return runStep( step );
				} );
			} );

			chain.then( finishRun, failRun );
		}

		/* ---------------------------------------------------------- confetti */

		function celebrate() {
			if ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
				return;
			}

			var canvas = h( 'canvas', 'wpasb-confetti' );
			canvas.setAttribute( 'aria-hidden', 'true' );
			document.body.appendChild( canvas );

			var ctx = canvas.getContext( '2d' );
			if ( ! ctx ) {
				document.body.removeChild( canvas );
				return;
			}

			var dpr = Math.min( window.devicePixelRatio || 1, 2 );
			var w   = 0;
			var hgt = 0;

			function size() {
				w   = window.innerWidth;
				hgt = window.innerHeight;
				canvas.width  = Math.round( w * dpr );
				canvas.height = Math.round( hgt * dpr );
				ctx.setTransform( dpr, 0, 0, dpr, 0, 0 );
			}
			size();
			window.addEventListener( 'resize', size );

			var colours   = [ '#FFFFFF', '#FFD23F', '#4ADE80', '#38BDF8', '#F472B6', '#A78BFA', '#FB923C' ];
			var particles = [];

			function burst( x, y, count, angle, spread, power ) {
				var i;
				for ( i = 0; i < count; i++ ) {
					var a = ( angle + ( Math.random() - 0.5 ) * spread ) * Math.PI / 180;
					var v = power * ( 0.45 + Math.random() * 0.75 );
					particles.push( {
						x: x,
						y: y,
						vx: Math.cos( a ) * v,
						vy: Math.sin( a ) * v,
						size: 5 + Math.random() * 6,
						round: Math.random() < 0.28,
						colour: colours[ Math.floor( Math.random() * colours.length ) ],
						rot: Math.random() * Math.PI * 2,
						spin: ( Math.random() - 0.5 ) * 0.4,
						wobble: Math.random() * Math.PI * 2,
						life: 0,
						ttl: 2600 + Math.random() * 1600
					} );
				}
			}

			// One burst from the percentage badge, then a cannon from each side.
			var anchor = el.percent.getBoundingClientRect();
			var ox     = Math.max( 40, Math.min( w - 40, anchor.left + anchor.width / 2 ) );
			var oy     = Math.max( 120, Math.min( hgt - 80, anchor.top + anchor.height / 2 ) );
			burst( ox, oy, 120, -90, 100, 17 );
			window.setTimeout( function () {
				burst( 0, hgt * 0.95, 70, -58, 36, 23 );
				burst( w, hgt * 0.95, 70, -122, 36, 23 );
			}, 180 );

			var last = null;
			var born = Date.now();

			function frame( now ) {
				var dt = last ? Math.min( 40, now - last ) : 16;
				var k  = dt / 16.67;
				last = now;

				ctx.clearRect( 0, 0, w, hgt );

				var alive = 0;
				particles.forEach( function ( p ) {
					p.life += dt;
					p.vx *= Math.pow( 0.985, k );
					p.vy = p.vy * Math.pow( 0.985, k ) + 0.34 * k;
					p.x += p.vx * k;
					p.y += p.vy * k;
					p.rot += p.spin * k;
					p.wobble += 0.12 * k;

					if ( p.life >= p.ttl || p.y > hgt + 30 ) {
						return;
					}
					alive++;

					var fade = Math.min( 1, ( p.ttl - p.life ) / 600 );
					ctx.save();
					ctx.globalAlpha = fade;
					ctx.translate( p.x, p.y );
					ctx.rotate( p.rot );
					ctx.fillStyle = p.colour;
					if ( p.round ) {
						ctx.beginPath();
						ctx.arc( 0, 0, p.size / 2.6, 0, Math.PI * 2 );
						ctx.fill();
					} else {
						// A flipping rectangle reads as paper turning in the air.
						ctx.scale( 1, Math.cos( p.wobble ) );
						ctx.fillRect( -p.size / 2, -p.size / 4, p.size, p.size / 2 );
					}
					ctx.restore();
				} );

				if ( ( alive > 0 || Date.now() - born < 400 ) && Date.now() - born < 7000 ) {
					window.requestAnimationFrame( frame );
					return;
				}

				window.removeEventListener( 'resize', size );
				if ( canvas.parentNode ) {
					canvas.parentNode.removeChild( canvas );
				}
			}

			window.requestAnimationFrame( frame );
		}

		/* ------------------------------------------------------------- wiring */

		el.scanBtn.addEventListener( 'click', startScan );
		if ( el.again ) {
			el.again.addEventListener( 'click', startScan );
		}

		el.consent.addEventListener( 'change', refreshGate );
		el.runBtn.addEventListener( 'click', startRun );

		el.cancelBtn.addEventListener( 'click', function () {
			if ( busy ) {
				return;
			}
			hideAll();
			el.scanBtn.focus();
		} );

		el.review.addEventListener( 'keydown', function ( event ) {
			// Escape applies only while the review panel holds focus: this is
			// an inline panel, not a modal, so nothing is trapped.
			if ( 'Escape' === event.key && ! busy ) {
				hideAll();
				el.scanBtn.focus();
			}
		} );

		window.addEventListener( 'beforeunload', function ( event ) {
			if ( ! running ) {
				return;
			}
			event.preventDefault();
			event.returnValue = '';
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
