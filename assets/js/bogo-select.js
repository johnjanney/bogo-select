/**
 * BOGO Select — cart page gift chooser.
 *
 * Posts the customer's choice to admin-ajax and reloads the cart so that
 * totals, fragments, and any theme cart markup re-render from the server.
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
		el.classList.toggle( 'is-error', 'error' === type );
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
	}

	/**
	 * Send a request to one of the plugin's AJAX endpoints.
	 *
	 * @param {string} action    Endpoint action name.
	 * @param {Object} extraData Additional POST fields.
	 */
	function post( action, extraData ) {
		var body = new URLSearchParams();

		body.append( 'action', action );
		body.append( 'nonce', settings.nonce );

		Object.keys( extraData || {} ).forEach( function ( key ) {
			body.append( key, extraData[ key ] );
		} );

		setBusy( true );

		fetch( settings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} )
			.then( function ( response ) {
				return response.json();
			} )
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

	root.addEventListener( 'click', function ( event ) {
		var chooseButton = event.target.closest( '.bogo-select__choose' );

		if ( chooseButton ) {
			event.preventDefault();
			chooseButton.textContent = settings.i18n.working;
			post( 'bogo_select_choose', { product_id: chooseButton.getAttribute( 'data-product-id' ) } );
			return;
		}

		var removeButton = event.target.closest( '[data-bogo-remove]' );

		if ( removeButton ) {
			event.preventDefault();

			if ( ! window.confirm( settings.i18n.confirm ) ) {
				return;
			}

			post( 'bogo_select_remove', {} );
		}
	} );

	// Disabled buttons stay disabled when the busy state clears.
	root.querySelectorAll( 'button[disabled]' ).forEach( function ( button ) {
		button.setAttribute( 'data-permanently-disabled', '1' );
	} );
}() );
