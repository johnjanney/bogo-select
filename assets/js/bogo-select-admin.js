/**
 * BOGO Select — settings screen.
 *
 * Shows or hides each product picker depending on whether its scope is set to
 * "Select Products", and initialises WooCommerce's product search select.
 */
jQuery( function ( $ ) {
	'use strict';

	/**
	 * Sync one scope fieldset with the product row it controls.
	 *
	 * @param {jQuery} $fieldset The .bogo-scope fieldset.
	 */
	function syncScope( $fieldset ) {
		var targetId = $fieldset.data( 'target' );
		var isSelect = 'select' === $fieldset.find( 'input[type="radio"]:checked' ).val();

		$( '#' + targetId ).toggle( isSelect );
	}

	$( '.bogo-scope' ).each( function () {
		syncScope( $( this ) );
	} );

	$( '.bogo-scope' ).on( 'change', 'input[type="radio"]', function () {
		syncScope( $( this ).closest( '.bogo-scope' ) );
	} );

	// WooCommerce initialises .wc-product-search on this event.
	$( document.body ).trigger( 'wc-enhanced-select-init' );
} );
