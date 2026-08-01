<?php
/**
 * A store with weight-bearing products and a free-shipping threshold.
 *
 * Run through `wp eval-file ... <free|percent>`. `OPEN-QUESTIONS.md` Q-004 states
 * how a reward behaves against shipping — that a free one adds weight but not
 * order value, and a discounted one adds both — and nothing had ever checked it.
 * Every other fixture in this suite uses virtual products precisely so that
 * shipping stays out of the way, which is why this gap survived.
 *
 * The figures are chosen so the two possible behaviours give different answers:
 * the paid item alone is below the free-shipping threshold, and the reward's
 * undiscounted value alone would carry it over.
 *
 * Prints the products, the threshold, and the mode as JSON on the last line.
 *
 * @package BOGO_Select
 */

$mode = isset( $args[0] ) && 'percent' === $args[0] ? 'percent' : 'free';

$threshold  = 50;
$paid_price = 45;
$reward_val = 20;

update_option( 'woocommerce_calc_taxes', 'no' );
update_option( 'woocommerce_enable_shipping_calc', 'yes' );
update_option( 'woocommerce_ship_to_countries', 'all' );
update_option( 'woocommerce_default_country', 'US:CA' );
update_option( 'woocommerce_weight_unit', 'kg' );

/**
 * Create a physical product that has weight.
 *
 * @param string $title  Product name.
 * @param int    $price  Price.
 * @param float  $weight Weight in the store's unit.
 * @return int Product ID.
 */
function bogo_shipping_product( $title, $price, $weight ) {
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
	// Deliberately not virtual: this is the one fixture that needs shipping.
	update_post_meta( $id, '_virtual', 'no' );
	update_post_meta( $id, '_weight', $weight );
	update_post_meta( $id, '_manage_stock', 'no' );
	update_post_meta( $id, '_stock_status', 'instock' );
	update_post_meta( $id, '_sku', 'ship-sku-' . $id );

	return $id;
}

$paid   = bogo_shipping_product( 'Shipping Paid Thing', $paid_price, 1 );
$reward = bogo_shipping_product( 'Shipping Reward Thing', $reward_val, 5 );

// One zone covering everywhere, with a free-shipping method that unlocks above
// the threshold and a flat rate that is always available for contrast.
global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_shipping_zone_methods" );

$zone = new WC_Shipping_Zone( 0 );

$flat = $zone->add_shipping_method( 'flat_rate' );
$free = $zone->add_shipping_method( 'free_shipping' );

$zone->save();

update_option(
	'woocommerce_flat_rate_' . $flat . '_settings',
	array(
		'title'      => 'Flat rate',
		'tax_status' => 'none',
		// Per item rather than per order, so the rate itself reports whether the
		// reward joined the shipping package. Flat rate cannot express weight,
		// and a reward that is in the package is a reward whose weight reaches a
		// weight-based method.
		'cost'       => '5 * [qty]',
	)
);

update_option(
	'woocommerce_free_shipping_' . $free . '_settings',
	array(
		'title'      => 'Free shipping',
		'requires'   => 'min_amount',
		'min_amount' => (string) $threshold,
	)
);

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
		'get_discount_type'  => 'percent' === $mode ? 'percent' : 'free',
		'get_discount_value' => 'percent' === $mode ? 50 : 0,
		'repeat'             => 'no',
		'show_notice'        => 'yes',
	)
);

echo wp_json_encode(
	array(
		'mode'         => $mode,
		'paid'         => (int) $paid,
		'reward'       => (int) $reward,
		'paid_price'   => $paid_price,
		'reward_value' => $reward_val,
		'threshold'    => $threshold,
	)
) . "\n";
