/**
 * Arzo MCP Connect — admin page behaviour.
 *
 * Vanilla, dependency-free. Reads config/i18n from the localized `arzoMcp`
 * object. Progressive: every control has a server-rendered fallback and only
 * gains enhancement when JS runs.
 */
( function () {
	'use strict';

	var cfg = window.arzoMcp || {};
	var i18n = cfg.i18n || {};

	/** Copy text to the clipboard, with a legacy fallback. */
	function copyText( text ) {
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			return navigator.clipboard.writeText( text );
		}
		return new Promise( function ( resolve, reject ) {
			try {
				var ta = document.createElement( 'textarea' );
				ta.value = text;
				ta.style.position = 'fixed';
				ta.style.left = '-9999px';
				document.body.appendChild( ta );
				ta.select();
				document.execCommand( 'copy' );
				document.body.removeChild( ta );
				resolve();
			} catch ( e ) {
				reject( e );
			}
		} );
	}

	/** Flash a "copied" confirmation on a button, then restore its label. */
	function flash( btn ) {
		var original = btn.getAttribute( 'data-label' ) || btn.textContent;
		btn.setAttribute( 'data-label', original );
		btn.textContent = i18n.copied || 'Copied ✓';
		btn.disabled = true;
		window.setTimeout( function () {
			btn.textContent = original;
			btn.disabled = false;
		}, 1500 );
	}

	/** Wire every [data-arzo-copy] button to copy its target's value/text. */
	function initCopyButtons() {
		var buttons = document.querySelectorAll( '[data-arzo-copy]' );
		Array.prototype.forEach.call( buttons, function ( btn ) {
			btn.addEventListener( 'click', function () {
				var sel = btn.getAttribute( 'data-arzo-copy' );
				var target = sel ? document.querySelector( sel ) : null;
				if ( ! target ) {
					return;
				}
				var value = 'value' in target ? target.value : target.textContent;
				copyText( value ).then( function () {
					flash( btn );
				} );
			} );
		} );
	}

	/** Live-test whether the Authorization header reaches WordPress. */
	function initAuthCheck() {
		var el = document.getElementById( 'arzo-auth-check' );
		if ( ! el || ! cfg.diagnosticsUrl ) {
			return;
		}
		fetch( cfg.diagnosticsUrl, {
			headers: { Authorization: 'Bearer arzo-mcp-diagnostic-probe' },
			credentials: 'omit'
		} ).then( function ( r ) {
			return r.json();
		} ).then( function ( d ) {
			if ( d && d.authorization_header_received ) {
				setBadge( el, 'ok', ( i18n.authOk || 'reaches WordPress' ) + ' (' + d.authorization_header_source + ')' );
			} else {
				setBadge( el, 'bad', i18n.authStripped || 'stripped by the server' );
				var fix = document.getElementById( 'arzo-auth-fix' );
				if ( fix ) {
					fix.hidden = false;
				}
			}
		} ).catch( function () {
			setBadge( el, 'bad', i18n.authUnreachable || 'check failed (REST unreachable)' );
		} );
	}

	function setBadge( el, kind, text ) {
		el.className = 'arzo-badge arzo-badge--' + kind;
		el.textContent = text;
	}

	/** Tabbed setup instructions. */
	function initTabs() {
		var lists = document.querySelectorAll( '[data-arzo-tabs]' );
		Array.prototype.forEach.call( lists, function ( list ) {
			var tabs = list.querySelectorAll( '.arzo-tab' );
			Array.prototype.forEach.call( tabs, function ( tab ) {
				tab.addEventListener( 'click', function () {
					select( tabs, tab );
				} );
				tab.addEventListener( 'keydown', function ( e ) {
					var idx = Array.prototype.indexOf.call( tabs, tab );
					if ( 'ArrowRight' === e.key || 'ArrowDown' === e.key ) {
						e.preventDefault();
						select( tabs, tabs[ ( idx + 1 ) % tabs.length ], true );
					} else if ( 'ArrowLeft' === e.key || 'ArrowUp' === e.key ) {
						e.preventDefault();
						select( tabs, tabs[ ( idx - 1 + tabs.length ) % tabs.length ], true );
					}
				} );
			} );
		} );

		function select( tabs, active, focus ) {
			Array.prototype.forEach.call( tabs, function ( tab ) {
				var selected = tab === active;
				tab.setAttribute( 'aria-selected', selected ? 'true' : 'false' );
				tab.tabIndex = selected ? 0 : -1;
				var panel = document.getElementById( tab.getAttribute( 'aria-controls' ) );
				if ( panel ) {
					panel.hidden = ! selected;
				}
			} );
			if ( focus ) {
				active.focus();
			}
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		initCopyButtons();
		initAuthCheck();
		initTabs();
	} );
} )();
