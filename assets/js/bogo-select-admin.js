/**
 * BOGO Select — settings screen.
 *
 * Shows or hides each product picker depending on whether its scope is set to
 * "Select Products", does the same for the discount amount, and initialises
 * WooCommerce's product search select.
 */
jQuery( function ( $ ) {
	'use strict';

	/**
	 * Show the row a radio fieldset controls, only for the chosen value.
	 *
	 * @param {jQuery} $fieldset Fieldset carrying data-target.
	 * @param {string} showFor   Radio value that reveals the row.
	 */
	function syncRow( $fieldset, showFor ) {
		var targetId = $fieldset.data( 'target' );
		var checked = $fieldset.find( 'input[type="radio"]:checked' ).val();

		$( '#' + targetId ).toggle( showFor === checked );
	}

	/**
	 * Bind a fieldset class to the value that reveals its row.
	 *
	 * @param {string} selector Fieldset selector.
	 * @param {string} showFor  Radio value that reveals the row.
	 */
	function bindRows( selector, showFor ) {
		$( selector ).each( function () {
			syncRow( $( this ), showFor );
		} );

		$( selector ).on( 'change', 'input[type="radio"]', function () {
			syncRow( $( this ).closest( selector ), showFor );
		} );
	}

	bindRows( '.bogo-scope', 'select' );
	bindRows( '.bogo-discount', 'percent' );

	// WooCommerce initialises .wc-product-search on this event.
	$( document.body ).trigger( 'wc-enhanced-select-init' );
} );
