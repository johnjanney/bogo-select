<?php
/**
 * Fixture store for the integration job.
 *
 * Run through `wp eval-file`. Prints the created product IDs as JSON on the
 * last line so the browser test can address them without guessing.
 *
 * @package BOGO_Select
 */

// WooCommerce 10.x ships "coming soon" on for fresh installs, which serves a
// placeholder in place of the store pages. Without this the whole run silently
// asserts against a launch screen.
update_option( 'woocommerce_coming_soon', 'no' );
update_option( 'woocommerce_store_pages_only', 'no' );

update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_calc_taxes', 'no' );
update_option( 'woocommerce_default_country', 'US:CA' );

/**
 * Create a purchasable simple product.
 *
 * @param string $title Product name.
 * @param int    $price Price.
 * @return int Product ID.
 */
function bogo_fixture_product( $title, $price ) {
	$id = wp_insert_post(
		array(
			'post_type'   => 'product',
			'post_title'  => $title,
			'post_status' => 'publish',
		)
	);

	wp_set_object_terms( $id, 'simple', 'product_type' );
	update_post_meta( $id, '_price', $price );
	update_post_meta( $id, '_regular_price', $price );
	update_post_meta( $id, '_manage_stock', 'no' );
	update_post_meta( $id, '_stock_status', 'instock' );
	update_post_meta( $id, '_sku', 'sku-' . $id );

	return $id;
}

$paid   = bogo_fixture_product( 'Bought Thing', 25 );
$gift_a = bogo_fixture_product( 'Gift A', 10 );
$gift_b = bogo_fixture_product( 'Gift B', 15 );

// Buy 1 get 1, gifts drawn from the whole catalogue. The offer title is
// deliberately not "BOGO promotion": the gift line's item metadata uses that
// string, and the test has to be able to tell the two apart.
update_option(
	'bogo_select_settings',
	array(
		'enabled'      => 'yes',
		'offer_title'  => 'CHOOSER-HEADING-XYZ',
		'buy_qty'      => 1,
		'get_qty'      => 1,
		'buy_scope'    => 'all',
		'get_scope'    => 'all',
		'get_products' => array(),
		'repeating'    => 'no',
	)
);

echo wp_json_encode(
	array(
		'paid'   => $paid,
		'gift_a' => $gift_a,
		'gift_b' => $gift_b,
	)
) . "\n";
