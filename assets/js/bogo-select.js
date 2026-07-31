/**
 * BOGO Select — cart page gift chooser.
 *
 * Selecting or removing a gift posts to admin-ajax and reloads the cart so that
 * totals, fragments, and any theme cart markup re-render from the server.
 * Paging and searching the gift list swap the grid in place instead, since
 * nothing about the cart has changed.
 */
( function () {
	'use strict';

	if ( typeof window.bogoSelect === 'undefined' ) {
		return;
	}

	var settings = window.bogoSelect;
	var root = document.getElementById( 'bogo-select' );

	if ( ! root ) {
		return;
	}

	var grid = root.querySelector( '[data-bogo-grid]' );
	var empty = root.querySelector( '[data-bogo-empty]' );
	var pagination = root.querySelector( '[data-bogo-pagination]' );
	var pageStatus = root.querySelector( '[data-bogo-page-status]' );
	var searchInput = root.querySelector( '[data-bogo-search]' );

	var state = {
		search: '',
		page: parseInt( root.getAttribute( 'data-page' ), 10 ) || 1,
		pages: parseInt( root.getAttribute( 'data-pages' ), 10 ) || 1
	};

	var searchTimer = null;
	var latestRequest = 0;

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
		var el = root.querySelector( '.bogo-select__message' );

		if ( ! el ) {
			el = document.createElement( 'p' );
			el.className = 'bogo-select__message';
			root.appendChild( el );
		}

		el.textContent = text;
		el.setAttribute( 'role', 'status' );
		el.hidden = ! text;
		el.classList.toggle( 'is-error', 'error' === type );
	}

	/**
	 * Sync the pagination controls with the current page state.
	 */
	function updatePagination() {
		if ( ! pagination ) {
			return;
		}

		var prev = pagination.querySelector( '[data-bogo-page="prev"]' );
		var next = pagination.querySelector( '[data-bogo-page="next"]' );

		if ( prev ) {
			prev.disabled = state.page <= 1;
		}

		if ( next ) {
			next.disabled = state.page >= state.pages;
		}

		if ( pageStatus ) {
			pageStatus.textContent = state.pages > 0
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
		root.classList.toggle( 'is-busy', busy );

		root.querySelectorAll( 'button' ).forEach( function ( button ) {
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
	 * Change the cart, then reload so every cart component re-renders.
	 *
	 * @param {string} action    Endpoint action name.
	 * @param {Object} extraData Additional POST fields.
	 */
	function mutate( action, extraData ) {
		setBusy( true );

		post( action, extraData )
			.then( function ( result ) {
				if ( result && result.success ) {
					window.location.reload();
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
	 * Fetch and render one page of gift options.
	 *
	 * @param {number} page Page number to load.
	 */
	function loadPage( page ) {
		if ( ! grid ) {
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

				grid.innerHTML = result.data.items || '';

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

	root.addEventListener( 'click', function ( event ) {
		var chooseButton = event.target.closest( '.bogo-select__choose' );

		if ( chooseButton ) {
			event.preventDefault();
			chooseButton.textContent = settings.i18n.working;
			mutate( 'bogo_select_choose', { product_id: chooseButton.getAttribute( 'data-product-id' ) } );
			return;
		}

		var removeButton = event.target.closest( '[data-bogo-remove]' );

		if ( removeButton ) {
			event.preventDefault();

			if ( ! window.confirm( settings.i18n.confirm ) ) {
				return;
			}

			mutate( 'bogo_select_remove', {} );
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

	if ( searchInput ) {
		searchInput.addEventListener( 'input', function () {
			window.clearTimeout( searchTimer );

			searchTimer = window.setTimeout( function () {
				var term = searchInput.value.trim();

				if ( term === state.search ) {
					return;
				}

				state.search = term;
				loadPage( 1 );
			}, 350 );
		} );

		// Enter would otherwise submit the surrounding cart form.
		searchInput.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key ) {
				event.preventDefault();
				window.clearTimeout( searchTimer );
				state.search = searchInput.value.trim();
				loadPage( 1 );
			}
		} );
	}

	updatePagination();
}() );
