<?php
/**
 * A reward that is already on sale, then discounted again.
 *
 * Run through `wp eval-file`. `DECISION.md` D-016 chose to discount the
 * effective selling price rather than the regular one, so a reward already
 * reduced is reduced again from the sale figure. That is the intended reading of
 * "50% off", and it means a 50% reward on a product already at 40% off costs 30%
 * of list — worth being sure of rather than assuming, since the two candidate
 * behaviours differ by real money.
 *
 * The prices are chosen so the right and wrong answers cannot be confused: a
 * regular 40.00 on sale at 20.00, halved, is 10.00; halving the regular price
 * instead would be 20.00, which is also the sale price and so would look
 * plausible in a total.
 *
 * Prints the products and both prices as JSON on the last line.
 *
 * @package BOGO_Select
 */

update_option( 'woocommerce_calc_taxes', 'no' );

/**
 * Create a virtual product, optionally on sale.
 *
 * @param string   $title   Product name.
 * @param int      $regular Regular price.
 * @param int|null $sale    Sale price, or null for none.
 * @return int Product ID.
 */
function bogo_sale_product( $title, $regular, $sale = null ) {
	$id = wp_insert_post(
		array(
			'post_type'   => 'product',
			'post_title'  => $title,
			'post_status' => 'publish',
		)
	);

	wp_set_object_terms( $id, 'simple', 'product_type' );
	update_post_meta( $id, '_regular_price', $regular );
	update_post_meta( $id, '_virtual', 'yes' );
	update_post_meta( $id, '_manage_stock', 'no' );
	update_post_meta( $id, '_stock_status', 'instock' );
	update_post_meta( $id, '_sku', 'sale-sku-' . $id );

	if ( null !== $sale ) {
		update_post_meta( $id, '_sale_price', $sale );
		update_post_meta( $id, '_price', $sale );
	} else {
		update_post_meta( $id, '_price', $regular );
	}

	return $id;
}

$paid   = bogo_sale_product( 'Sale Paid Thing', 30 );
$reward = bogo_sale_product( 'Sale Reward Thing', 40, 20 );

update_option(
	'bogo_select_settings',
	array(
		'enabled'            => 'yes',
		'offer_title'        => 'CHOOSER-HEADING-XYZ',
		'buy_qty'            => 1,
		'get_qty'            => 1,
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
		'paid'    => (int) $paid,
		'reward'  => (int) $reward,
		'regular' => 40,
		'sale'    => 20,
	)
) . "\n";
