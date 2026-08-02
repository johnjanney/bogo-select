<?php
/**
 * Fixture for placing a real order with a discounted reward on it.
 *
 * Run through `wp eval-file`. Prints the product IDs and their starting stock as
 * JSON on the last line, so the assertions afterwards can compare against a
 * figure taken from the store rather than a hardcoded guess.
 *
 * The products are virtual and stock-managed on purpose: virtual so checkout
 * needs no shipping method configured, stock-managed so the reduction that
 * follows an order is observable at all.
 *
 * @package BOGO_Select
 */

// Cash on delivery is the only gateway that needs no credentials, so it is what
// makes an end-to-end checkout possible in a disposable store.
update_option(
	'woocommerce_cod_settings',
	array(
		'enabled'      => 'yes',
		'title'        => 'Cash on delivery',
		'description'  => '',
		'instructions' => '',
	)
);

update_option( 'woocommerce_calc_taxes', 'no' );
update_option( 'woocommerce_enable_guest_checkout', 'yes' );
update_option( 'woocommerce_enable_checkout_login_reminder', 'no' );

/**
 * Create a virtual, stock-managed product.
 *
 * @param string $title Product name.
 * @param int    $price Price.
 * @param int    $stock Starting stock.
 * @return int Product ID.
 */
function bogo_order_fixture_product( $title, $price, $stock ) {
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
	update_post_meta( $id, '_virtual', 'yes' );
	update_post_meta( $id, '_manage_stock', 'yes' );
	update_post_meta( $id, '_stock', $stock );
	update_post_meta( $id, '_stock_status', 'instock' );
	update_post_meta( $id, '_sku', 'order-sku-' . $id );

	return $id;
}

$paid   = bogo_order_fixture_product( 'Order Paid Thing', 30, 10 );
$reward = bogo_order_fixture_product( 'Order Reward Thing', 20, 10 );

// Buy 1, get 2 at half price. Two units so the assertions can tell a per-unit
// figure from a per-line one, and catch stock being reduced by one rather than
// by the quantity awarded.
update_option(
	'bogo_select_settings',
	array(
		'enabled'            => 'yes',
		'offer_title'        => 'CHOOSER-HEADING-XYZ',
		'buy_qty'            => 1,
		'get_qty'            => 2,
		'buy_scope'          => 'all',
		'get_scope'          => 'select',
		'get_products'       => array( $reward ),
		'get_discount_type'  => 'percent',
		'get_discount_value' => 50,
		'repeat'             => 'no',
		'show_notice'        => 'yes',
	)
);

echo wp_json_encode(
	array(
		'paid'         => (int) $paid,
		'reward'       => (int) $reward,
		'paid_stock'   => (int) get_post_meta( $paid, '_stock', true ),
		'reward_stock' => (int) get_post_meta( $reward, '_stock', true ),
	)
) . "\n";
