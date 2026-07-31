/**
 * BOGO Select — gift chooser for the cart and checkout pages.
 *
 * The chooser lives inside a slot element that the server renders on both the
 * classic and the block cart and checkout. Everything below is written against
 * that slot rather than against the chooser itself, because the chooser is
 * replaced wholesale whenever the cart changes.
 *
 * Three modes, decided by the server through data-bogo-mode:
 *
 * - classic: the classic cart page. Selecting or removing a gift posts to
 *   admin-ajax and reloads, so the cart table, totals, and theme fragments
 *   re-render from PHP.
 * - checkout: the classic checkout page. Same request, but no reload — that
 *   would empty a half-filled form — so the chooser re-renders from the
 *   response and WooCommerce is asked to update the order review.
 * - block: the cart is changed through the Store API (extensionCartUpdate), so
 *   the Cart and Checkout blocks update from the response they already trust.
 *   The chooser then follows the wc/store/cart data store and re-renders
 *   itself whenever the customer changes the cart elsewhere on the page.
 *
 * Paging and searching swap the grid in place in every mode: nothing about the
 * cart has changed.
 */
( function () {
	'use strict';

	if ( typeof window.bogoSelect === 'undefined' ) {
		return;
	}

	var settings = window.bogoSelect;
	var slot = document.querySelector( '[data-bogo-slot]' );

	if ( ! slot ) {
		return;
	}

	var mode = slot.getAttribute( 'data-bogo-mode' ) || 'classic';
	var isBlockMode = 'block' === mode;
	var isClassicCheckout = 'checkout' === mode;

	var state = {
		search: '',
		page: 1,
		pages: 1
	};

	var searchTimer = null;
	var latestRequest = 0;
	var refreshing = false;
	var refreshQueued = false;
	var cartSignature = null;

	/**
	 * The chooser panel currently in the slot, if any.
	 *
	 * @return {Element|null} The panel element.
	 */
	function root() {
		return slot.querySelector( '.bogo-select' );
	}

	/**
	 * Find an element inside the current chooser.
	 *
	 * @param {string} selector CSS selector.
	 * @return {Element|null} The element.
	 */
	function part( selector ) {
		return slot.querySelector( selector );
	}

	/**
	 * Read the page state the server rendered into the chooser.
	 */
	function readState() {
		var el = root();

		if ( ! el ) {
			state.page = 1;
			state.pages = 1;
			return;
		}

		state.page = parseInt( el.getAttribute( 'data-page' ), 10 ) || 1;
		state.pages = parseInt( el.getAttribute( 'data-pages' ), 10 ) || 1;
	}

	/**
	 * Fill %1$d-style placeholders in a translated string.
	 *
	 * @param {string} template Format string.
	 * @param {Array}  args     Replacements, in order.
	 * @return {string} Formatted string.
	 */
	function format( template, args ) {
		return String( template ).replace( /%(\d+)\$d/g, function ( match, index ) {
			return args[ parseInt( index, 10 ) - 1 ];
		} );
	}

	/**
	 * Show a message inside the chooser.
	 *
	 * @param {string} text Message text.
	 * @param {string} type 'error' or 'info'.
	 */
	function message( text, type ) {
		var el = root();

		if ( ! el ) {
			return;
		}

		var box = el.querySelector( '.bogo-select__message' );

		if ( ! box ) {
			box = document.createElement( 'p' );
			box.className = 'bogo-select__message';
			el.appendChild( box );
		}

		box.textContent = text;
		box.setAttribute( 'role', 'status' );
		box.hidden = ! text;
		box.classList.toggle( 'is-error', 'error' === type );
	}

	/**
	 * Sync the pagination controls with the current page state.
	 */
	function updatePagination() {
		var pagination = part( '[data-bogo-pagination]' );

		if ( ! pagination ) {
			return;
		}

		var prev = pagination.querySelector( '[data-bogo-page="prev"]' );
		var next = pagination.querySelector( '[data-bogo-page="next"]' );
		var status = part( '[data-bogo-page-status]' );

		if ( prev ) {
			prev.disabled = state.page <= 1;
		}

		if ( next ) {
			next.disabled = state.page >= state.pages;
		}

		if ( status ) {
			status.textContent = state.pages > 0
				? format( settings.i18n.pageOf, [ state.page, state.pages ] )
				: settings.i18n.noPages;
		}
	}

	/**
	 * Toggle the busy state on the chooser.
	 *
	 * @param {boolean} busy Whether a request is in flight.
	 */
	function setBusy( busy ) {
		var el = root();

		if ( ! el ) {
			return;
		}

		el.classList.toggle( 'is-busy', busy );

		Array.prototype.forEach.call( el.querySelectorAll( 'button' ), function ( button ) {
			if ( busy ) {
				button.disabled = true;
			} else if ( ! button.hasAttribute( 'data-permanently-disabled' ) ) {
				button.disabled = false;
			}
		} );

		if ( ! busy ) {
			updatePagination();
		}
	}

	/**
	 * Post to one of the plugin's AJAX endpoints.
	 *
	 * @param {string} action    Endpoint action name.
	 * @param {Object} extraData Additional POST fields.
	 * @return {Promise} Resolves with the decoded response.
	 */
	function post( action, extraData ) {
		var body = new URLSearchParams();

		body.append( 'action', action );
		body.append( 'nonce', settings.nonce );

		Object.keys( extraData || {} ).forEach( function ( key ) {
			body.append( key, extraData[ key ] );
		} );

		return fetch( settings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} ).then( function ( response ) {
			return response.json();
		} );
	}

	/**
	 * Replace the chooser with freshly rendered markup.
	 *
	 * @param {string} html Chooser markup, possibly empty.
	 */
	function render( html ) {
		var searchBox = part( '[data-bogo-search]' );
		var hadFocus = searchBox === document.activeElement;
		var term = state.search;

		slot.innerHTML = html || '';

		readState();

		searchBox = part( '[data-bogo-search]' );

		if ( searchBox && term ) {
			searchBox.value = term;

			if ( hadFocus ) {
				searchBox.focus();
			}

			// The server always renders page one of the unfiltered list; put
			// the customer's search back over the top of it.
			loadPage( 1 );
			return;
		}

		updatePagination();
	}

	/**
	 * Re-render the chooser for the cart as it now stands.
	 */
	function refresh() {
		if ( refreshing ) {
			refreshQueued = true;
			return;
		}

		refreshing = true;

		post( 'bogo_select_refresh', {} )
			.then( function ( result ) {
				refreshing = false;

				if ( result && result.success && result.data ) {
					render( result.data.html );

					// Self-healing validation may have changed the cart while
					// rendering this — a gift whose stock ran out is dropped,
					// for instance. The blocks are holding the cart as it was,
					// so tell them to fetch it again.
					if ( isBlockMode && result.data.state && cartSignature && result.data.state !== cartSignature ) {
						cartSignature = result.data.state;
						invalidateBlockCart();
					}
				}

				if ( refreshQueued ) {
					refreshQueued = false;
					refresh();
				}
			} )
			.catch( function () {
				refreshing = false;
			} );
	}

	/**
	 * The variation a card would award.
	 *
	 * A variable card settles this with its selector; every other card carries it
	 * on the button, as zero for a simple product and its own ID for a variation
	 * listed on its own.
	 *
	 * @param {Element} button The chosen card's Select button.
	 * @return {string|number} Variation ID, or 0.
	 */
	function chosenVariation( button ) {
		var card = button.closest( '.bogo-select__item' );
		var select = card ? card.querySelector( '[data-bogo-variation]' ) : null;

		if ( select && select.value ) {
			return select.value;
		}

		return button.getAttribute( 'data-variation-id' ) || 0;
	}

	/**
	 * Quote the option the customer is looking at.
	 *
	 * The card is server-rendered against one variation, and a variable
	 * product's own price is the low end of a range rather than any variation's,
	 * so leaving the figure alone as the selector moves would misquote it.
	 *
	 * @param {Element} select The card's variation selector.
	 */
	function syncCardPrice( select ) {
		var card = select.closest( '.bogo-select__item' );
		var price = card ? card.querySelector( '[data-bogo-price]' ) : null;
		var option = select.options[ select.selectedIndex ];

		if ( price && option && option.getAttribute( 'data-price' ) ) {
			price.innerHTML = option.getAttribute( 'data-price' );
		}
	}

	/**
	 * Change the cart, then bring the page up to date.
	 *
	 * @param {string} action    'choose' or 'remove'.
	 * @param {Object} extraData Additional fields.
	 */
	function mutate( action, extraData ) {
		setBusy( true );

		if ( isBlockMode ) {
			mutateThroughStoreApi( action, extraData );
			return;
		}

		post( 'bogo_select_' + action, extraData )
			.then( function ( result ) {
				if ( result && result.success ) {
					settle( result.data );
					return;
				}

				setBusy( false );
				message( ( result && result.data && result.data.message ) || settings.i18n.error, 'error' );
			} )
			.catch( function () {
				setBusy( false );
				message( settings.i18n.error, 'error' );
			} );
	}

	/**
	 * Bring the page up to date after the cart has changed.
	 *
	 * @param {Object} data Successful response payload.
	 */
	function settle( data ) {
		if ( ! isClassicCheckout ) {
			// The classic cart page is rendered by PHP from top to bottom;
			// reloading is the only way its table and totals agree with the
			// cart again.
			window.location.reload();
			return;
		}

		// A checkout form may be half filled in, so it must survive. The order
		// review re-renders itself over AJAX instead.
		render( data && data.html );
		setBusy( false );

		if ( window.jQuery ) {
			window.jQuery( document.body ).trigger( 'update_checkout' );
			return;
		}

		window.location.reload();
	}

	/**
	 * Change the cart through the Store API so the blocks re-render with it.
	 *
	 * @param {string} action    'choose' or 'remove'.
	 * @param {Object} extraData Additional fields.
	 */
	function mutateThroughStoreApi( action, extraData ) {
		var update = extensionCartUpdate( Object.assign( { action: action }, extraData || {} ) );

		if ( ! update ) {
			// WooCommerce Blocks is not exposing its checkout helpers on this
			// page. Change the cart over admin-ajax instead, then ask the blocks
			// to fetch it again — heavier, but it always works.
			post( 'bogo_select_' + action, extraData )
				.then( function ( result ) {
					if ( result && result.success ) {
						setBusy( false );
						render( result.data && result.data.html );
						invalidateBlockCart();
						return;
					}

					setBusy( false );
					message( ( result && result.data && result.data.message ) || settings.i18n.error, 'error' );
				} )
				.catch( function () {
					setBusy( false );
					message( settings.i18n.error, 'error' );
				} );

			return;
		}

		update
			.then( function () {
				setBusy( false );
				refresh();
			} )
			.catch( function ( error ) {
				setBusy( false );
				message( ( error && error.message ) || settings.i18n.error, 'error' );
			} );
	}

	/**
	 * Send a cart update through the WooCommerce Blocks Store API helper.
	 *
	 * @param {Object} data Payload for the registered update callback.
	 * @return {Promise|null} Null when the helper is unavailable.
	 */
	function extensionCartUpdate( data ) {
		if ( ! window.wc || ! window.wc.blocksCheckout || 'function' !== typeof window.wc.blocksCheckout.extensionCartUpdate ) {
			return null;
		}

		try {
			return window.wc.blocksCheckout.extensionCartUpdate( {
				namespace: settings.namespace,
				data: data
			} );
		} catch ( error ) {
			return null;
		}
	}

	/**
	 * Fetch and render one page of gift options.
	 *
	 * @param {number} page Page number to load.
	 */
	function loadPage( page ) {
		if ( ! part( '[data-bogo-grid]' ) ) {
			return;
		}

		var token = ++latestRequest;

		setBusy( true );
		message( '', 'info' );

		post( 'bogo_select_choices', { search: state.search, page: page } )
			.then( function ( result ) {
				// A later keystroke has already superseded this response.
				if ( token !== latestRequest ) {
					return;
				}

				if ( ! result || ! result.success || ! result.data ) {
					setBusy( false );
					message( ( result && result.data && result.data.message ) || settings.i18n.error, 'error' );
					return;
				}

				state.page = parseInt( result.data.page, 10 ) || 1;
				state.pages = parseInt( result.data.pages, 10 ) || 1;

				var grid = part( '[data-bogo-grid]' );
				var empty = part( '[data-bogo-empty]' );

				if ( grid ) {
					grid.innerHTML = result.data.items || '';
				}

				if ( empty ) {
					empty.textContent = result.data.empty || '';
					empty.hidden = ! result.data.empty;
				}

				setBusy( false );
			} )
			.catch( function () {
				if ( token !== latestRequest ) {
					return;
				}

				setBusy( false );
				message( settings.i18n.error, 'error' );
			} );
	}

	// Delegated, because the chooser's markup is replaced wholesale after every
	// change and any listener bound to a card would go with it.
	slot.addEventListener( 'change', function ( event ) {
		var select = event.target.closest( '[data-bogo-variation]' );

		if ( select ) {
			syncCardPrice( select );
		}
	} );

	slot.addEventListener( 'click', function ( event ) {
		var chooseButton = event.target.closest( '.bogo-select__choose' );

		if ( chooseButton ) {
			event.preventDefault();
			chooseButton.textContent = settings.i18n.working;
			mutate( 'choose', {
				product_id: chooseButton.getAttribute( 'data-product-id' ),
				variation_id: chosenVariation( chooseButton ),
			} );
			return;
		}

		var removeButton = event.target.closest( '[data-bogo-remove]' );

		if ( removeButton ) {
			event.preventDefault();

			if ( ! window.confirm( settings.i18n.confirm ) ) {
				return;
			}

			mutate( 'remove', {} );
			return;
		}

		var pageButton = event.target.closest( '[data-bogo-page]' );

		if ( pageButton ) {
			event.preventDefault();

			var target = 'prev' === pageButton.getAttribute( 'data-bogo-page' )
				? state.page - 1
				: state.page + 1;

			if ( target < 1 || target > state.pages ) {
				return;
			}

			loadPage( target );
		}
	} );

	slot.addEventListener( 'input', function ( event ) {
		if ( ! event.target.hasAttribute( 'data-bogo-search' ) ) {
			return;
		}

		var input = event.target;

		window.clearTimeout( searchTimer );

		searchTimer = window.setTimeout( function () {
			var term = input.value.trim();

			if ( term === state.search ) {
				return;
			}

			state.search = term;
			loadPage( 1 );
		}, 350 );
	} );

	slot.addEventListener( 'keydown', function ( event ) {
		if ( 'Enter' !== event.key || ! event.target.hasAttribute( 'data-bogo-search' ) ) {
			return;
		}

		// Enter would otherwise submit the surrounding cart or checkout form.
		event.preventDefault();
		window.clearTimeout( searchTimer );
		state.search = event.target.value.trim();
		loadPage( 1 );
	} );

	/**
	 * A fingerprint of the block cart's current contents.
	 *
	 * The server sends its own signature through the Store API extension data;
	 * the item fallback covers a cart response that predates it.
	 *
	 * @param {Object} cart Cart data from the wc/store/cart store.
	 * @return {string} Fingerprint.
	 */
	function cartFingerprint( cart ) {
		if ( ! cart ) {
			return '';
		}

		var extension = cart.extensions && cart.extensions[ settings.namespace ];

		if ( extension && extension.signature ) {
			return String( extension.signature );
		}

		return ( cart.items || [] ).map( function ( item ) {
			return item.key + ':' + item.quantity;
		} ).join( '|' );
	}

	/**
	 * Ask the blocks to fetch the cart again.
	 */
	function invalidateBlockCart() {
		try {
			var actions = window.wp.data.dispatch( 'wc/store/cart' );

			if ( actions && 'function' === typeof actions.invalidateResolutionForStore ) {
				actions.invalidateResolutionForStore();
			} else if ( actions && 'function' === typeof actions.invalidateResolution ) {
				actions.invalidateResolution( 'getCartData', [] );
			}
		} catch ( error ) {
			// Nothing to do: the chooser itself is already up to date.
		}
	}

	/**
	 * Follow the block cart and re-render the chooser when it changes.
	 *
	 * @return {boolean} Whether the store was found and subscribed to.
	 */
	function watchBlockCart() {
		if ( ! window.wp || ! window.wp.data || 'function' !== typeof window.wp.data.subscribe ) {
			return false;
		}

		var store;

		try {
			store = window.wp.data.select( 'wc/store/cart' );
		} catch ( error ) {
			return false;
		}

		if ( ! store || 'function' !== typeof store.getCartData ) {
			return false;
		}

		cartSignature = cartFingerprint( store.getCartData() );

		window.wp.data.subscribe( function () {
			if ( store.isCustomerDataUpdating && store.isCustomerDataUpdating() ) {
				return;
			}

			var next = cartFingerprint( store.getCartData() );

			if ( '' === next || next === cartSignature ) {
				return;
			}

			cartSignature = next;
			refresh();
		} );

		return true;
	}

	readState();
	updatePagination();

	if ( isBlockMode && ! watchBlockCart() ) {
		// The blocks bundle may register its store after this script runs.
		var attempts = 0;
		var poll = window.setInterval( function () {
			attempts++;

			if ( watchBlockCart() || attempts > 40 ) {
				window.clearInterval( poll );
			}
		}, 250 );
	}
}() );
