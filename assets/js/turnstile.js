/**
 * Renders Cloudflare Turnstile on WooCommerce forms.
 *
 * Server-rendered forms (classic checkout, My Account login / registration /
 * lost password): PHP prints a `.wcct-turnstile[data-action]` container and the
 * token is submitted through the hidden `cf-turnstile-response` field Turnstile
 * adds. The classic checkout container lives in the order review fragment, so
 * it is re-rendered after every `updated_checkout` refresh.
 *
 * Checkout block: the container is injected above the "Place order" button and
 * the token is passed to the Store API as extension data.
 */
/* global turnstile */
( function () {
	'use strict';

	var cfg = window.wcctTurnstileConfig || {};
	var widgets = []; // { id, el }
	var started = false;

	function noop() {}

	function findWidget( el ) {
		for ( var i = 0; i < widgets.length; i++ ) {
			if ( widgets[ i ].el === el ) {
				return widgets[ i ];
			}
		}
		return null;
	}

	// Drop widgets whose container was removed, e.g. by a checkout refresh.
	function prune() {
		widgets = widgets.filter( function ( widget ) {
			if ( document.body.contains( widget.el ) ) {
				return true;
			}
			try {
				turnstile.remove( widget.id );
			} catch ( e ) {}
			return false;
		} );
	}

	function render( el, action, onToken ) {
		prune();
		if ( findWidget( el ) ) {
			return;
		}
		el.innerHTML = '';

		var options = {
			sitekey: cfg.siteKey,
			action: action,
			theme: cfg.theme,
			size: cfg.size,
			appearance: cfg.appearance,
			callback: function ( token ) {
				onToken( token );
			},
			'expired-callback': function () {
				onToken( '' );
			},
			'timeout-callback': function () {
				onToken( '' );
			},
			'error-callback': function () {
				onToken( '' );
			},
		};

		if ( cfg.language && cfg.language !== 'auto' ) {
			options.language = cfg.language;
		}

		var id = turnstile.render( el, options );
		if ( id !== undefined && id !== null ) {
			widgets.push( { id: id, el: el } );
		}
	}

	function reset( el ) {
		var widget = findWidget( el );
		if ( widget ) {
			try {
				turnstile.reset( widget.id );
			} catch ( e ) {}
		}
	}

	function isVisible( el ) {
		return !! ( el.offsetWidth || el.offsetHeight || el.getClientRects().length );
	}

	/* Server-rendered forms ------------------------------------------------ */

	// Containers in hidden forms (like the checkout's collapsed login form) are
	// rendered once they become visible, so the widget can size itself.
	function renderServerWidgets() {
		var containers = document.querySelectorAll( '.wcct-turnstile[data-action]' );
		for ( var i = 0; i < containers.length; i++ ) {
			if ( isVisible( containers[ i ] ) ) {
				render( containers[ i ], containers[ i ].getAttribute( 'data-action' ), noop );
			}
		}
	}

	function initServerForms() {
		renderServerWidgets();

		// Catch forms that were revealed after load (toggles, tabs, popups).
		document.addEventListener( 'focusin', renderServerWidgets );
		document.addEventListener( 'click', function () {
			window.setTimeout( renderServerWidgets, 450 ); // After jQuery slide animations.
		} );

		var $ = window.jQuery;
		if ( ! $ ) {
			return;
		}

		$( document.body ).on( 'updated_checkout', renderServerWidgets );

		// Tokens are single use, so a failed order attempt needs a fresh one.
		$( document.body ).on( 'checkout_error', function () {
			var containers = document.querySelectorAll( 'form.checkout .wcct-turnstile' );
			for ( var i = 0; i < containers.length; i++ ) {
				reset( containers[ i ] );
			}
		} );
	}

	/* Checkout block ------------------------------------------------------- */

	function initBlocks( data ) {
		var storeKey =
			( window.wc && window.wc.wcBlocksData && window.wc.wcBlocksData.CHECKOUT_STORE_KEY ) ||
			'wc/store/checkout';
		var scheduled = false;
		var wasProcessing = false;
		var blockContainer = null;

		function setToken( token ) {
			var actions = data.dispatch( storeKey );
			if ( ! actions ) {
				return;
			}
			var setter = actions.setExtensionData || actions.__internalSetExtensionData;
			if ( setter ) {
				setter( cfg.namespace, { token: token } );
			}
		}

		function ensure() {
			scheduled = false;

			var actionsEl = document.querySelector( '.wc-block-checkout__actions' );
			if ( ! actionsEl || ! actionsEl.parentNode ) {
				return;
			}

			var container = actionsEl.parentNode.querySelector( '.wcct-turnstile--blocks' );
			if ( container && findWidget( container ) ) {
				return;
			}

			if ( ! container ) {
				container = document.createElement( 'div' );
				container.className = 'wcct-turnstile wcct-turnstile--blocks';
				actionsEl.parentNode.insertBefore( container, actionsEl );
			}

			blockContainer = container;
			setToken( '' );
			render( container, cfg.checkoutAction, setToken );
		}

		function scheduleEnsure() {
			if ( ! scheduled ) {
				scheduled = true;
				window.requestAnimationFrame( ensure );
			}
		}

		// The block renders asynchronously and may re-render, so watch the DOM.
		new MutationObserver( scheduleEnsure ).observe( document.body, {
			childList: true,
			subtree: true,
		} );
		scheduleEnsure();

		// Get a fresh token after every order attempt (tokens are single use).
		data.subscribe( function () {
			var select = data.select( storeKey );
			if ( ! select || typeof select.isProcessing !== 'function' ) {
				return;
			}
			var processing = select.isProcessing();
			var finished = wasProcessing && ! processing;
			// Update before resetting: setToken() dispatches, which re-enters this listener.
			wasProcessing = processing;
			if ( finished && blockContainer ) {
				setToken( '' );
				reset( blockContainer );
			}
		} );
	}

	/* Boot ----------------------------------------------------------------- */

	function start() {
		if ( started || typeof turnstile === 'undefined' || ! cfg.siteKey ) {
			return;
		}
		started = true;

		initServerForms();

		if ( cfg.isBlocks && window.wp && window.wp.data ) {
			initBlocks( window.wp.data );
		}
	}

	// Called by api.js (loaded with ?onload=wcctTurnstileOnload).
	window.wcctTurnstileOnload = function () {
		if ( document.readyState === 'loading' ) {
			document.addEventListener( 'DOMContentLoaded', start );
		} else {
			start();
		}
	};

	// In case api.js was already loaded by something else.
	if ( typeof turnstile !== 'undefined' ) {
		window.wcctTurnstileOnload();
	}
} )();
