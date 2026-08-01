<?php
/**
 * Turn taxes on and offer a discounted reward, in one display mode or the other.
 *
 * Run through `wp eval-file ... <excl|incl>`. Tax is the setting where a wrong
 * answer costs a store money: the reward must be taxed on what the customer
 * actually pays, not on the price it was discounted from, and the figure shown
 * has to follow the store's display mode. Neither had been exercised
 * (`CODEX-REVIEW.md` M-03).
 *
 * Tax is based on the store base and the rate applies to every country, so the
 * result does not depend on a customer address the cart may not have yet.
 *
 * Prints the products, the rate, and the mode as JSON on the last line.
 *
 * @package BOGO_Select
 */

$mode     = isset( $args[0] ) && 'incl' === $args[0] ? 'incl' : 'excl';
$rate     = 10;
$includes = ( 'incl' === $mode );

update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_prices_include_tax', $includes ? 'yes' : 'no' );
update_option( 'woocommerce_tax_based_on', 'base' );
update_option( 'woocommerce_default_country', 'US:CA' );
update_option( 'woocommerce_tax_display_cart', $includes ? 'incl' : 'excl' );
update_option( 'woocommerce_tax_display_shop', $includes ? 'incl' : 'excl' );

// Replace any rate a previous run left behind, so the total is the rate under
// test rather than the sum of every run so far.
global $wpdb;
$existing = $wpdb->get_col( "SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates" );

foreach ( $existing as $rate_id ) {
	WC_Tax::_delete_tax_rate( (int) $rate_id );
}

WC_Tax::_insert_tax_rate(
	array(
		'tax_rate_country'  => '',
		'tax_rate_state'    => '',
		'tax_rate'          => number_format( $rate, 4, '.', '' ),
		'tax_rate_name'     => 'Test Tax',
		'tax_rate_priority' => 1,
		'tax_rate_compound' => 0,
		'tax_rate_shipping' => 0,
		'tax_rate_order'    => 0,
		'tax_rate_class'    => '',
	)
);

/**
 * Create a taxable virtual product.
 *
 * @param string $title Product name.
 * @param int    $price Price, in whichever sense the store is configured for.
 * @return int Product ID.
 */
function bogo_tax_product( $title, $price ) {
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
	update_post_meta( $id, '_manage_stock', 'no' );
	update_post_meta( $id, '_stock_status', 'instock' );
	update_post_meta( $id, '_tax_status', 'taxable' );
	update_post_meta( $id, '_tax_class', '' );
	update_post_meta( $id, '_sku', 'tax-sku-' . $id . '-' . wp_rand( 100, 999 ) );

	return $id;
}

$paid   = bogo_tax_product( 'Tax Paid Thing ' . $mode, 50 );
$reward = bogo_tax_product( 'Tax Reward Thing ' . $mode, 20 );

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
		'mode'         => $mode,
		'rate'         => $rate,
		'paid'         => (int) $paid,
		'reward'       => (int) $reward,
		'reward_price' => 20,
	)
) . "\n";
