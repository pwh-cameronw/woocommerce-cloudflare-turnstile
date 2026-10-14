/**
 * Renders Cloudflare Turnstile on the WooCommerce checkout.
 *
 * Classic checkout: the container is printed by PHP inside the order review
 * fragment and re-rendered after every `updated_checkout` refresh. The token is
 * submitted through the hidden `cf-turnstile-response` field Turnstile adds.
 *
 * Checkout block: the container is injected above the "Place order" button and
 * the token is passed to the Store API as extension data.
 */
/* global turnstile */
( function () {
	'use strict';

	var cfg = window.wcctTurnstileConfig || {};
	var widgetId = null;
	var widgetContainer = null;
	var started = false;

	function isRendered( container ) {
		return container === widgetContainer && widgetId !== null;
	}

	function removeWidget() {
		if ( widgetId !== null ) {
			try {
				turnstile.remove( widgetId );
			} catch ( e ) {
				// The element may already have been replaced by a checkout refresh.
			}
		}
		widgetId = null;
		widgetContainer = null;
	}

	function render( container, onToken ) {
		removeWidget();
		container.innerHTML = '';

		var options = {
			sitekey: cfg.siteKey,
			action: cfg.action,
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

		widgetContainer = container;
		widgetId = turnstile.render( container, options );
	}

	function reset( onToken ) {
		onToken( '' );
		if ( widgetId !== null && widgetContainer && document.body.contains( widgetContainer ) ) {
			try {
				turnstile.reset( widgetId );
			} catch ( e ) {}
		}
	}

	/* Classic (shortcode) checkout ---------------------------------------- */

	function initClassic( $ ) {
		var noop = function () {};

		function ensure() {
			var container = document.querySelector( 'form.checkout .wcct-turnstile' );
			if ( ! container || isRendered( container ) ) {
				return;
			}
			render( container, noop );
		}

		$( document.body ).on( 'updated_checkout', ensure );

		// Tokens are single use, so a failed order attempt needs a fresh one.
		$( document.body ).on( 'checkout_error', function () {
			reset( noop );
		} );

		ensure();
	}

	/* Checkout block ------------------------------------------------------- */

	function initBlocks( data ) {
		var storeKey =
			( window.wc && window.wc.wcBlocksData && window.wc.wcBlocksData.CHECKOUT_STORE_KEY ) ||
			'wc/store/checkout';
		var scheduled = false;
		var wasProcessing = false;

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

			var container = actionsEl.parentNode.querySelector( '.wcct-turnstile' );
			if ( container && isRendered( container ) ) {
				return;
			}

			if ( ! container ) {
				container = document.createElement( 'div' );
				container.className = 'wcct-turnstile wcct-turnstile--blocks';
				actionsEl.parentNode.insertBefore( container, actionsEl );
			}

			setToken( '' );
			render( container, setToken );
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
			// Update before resetting: reset() dispatches, which re-enters this listener.
			wasProcessing = processing;
			if ( finished ) {
				reset( setToken );
			}
		} );
	}

	/* Boot ----------------------------------------------------------------- */

	function start() {
		if ( started || typeof turnstile === 'undefined' || ! cfg.siteKey ) {
			return;
		}
		started = true;

		if ( cfg.isBlocks && window.wp && window.wp.data ) {
			initBlocks( window.wp.data );
		} else if ( window.jQuery ) {
			initClassic( window.jQuery );
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
