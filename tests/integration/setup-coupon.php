<?php
/**
 * Coupons for the stacking scenario.
 *
 * Run through `wp eval-file <reward_id>`. Creates two percentage coupons: one
 * that applies to everything, and one that excludes the reward product. The pair
 * is what makes "eligible coupons stack" testable in both directions — that a
 * coupon which may apply does, and that one which may not is honoured.
 *
 * Prints the codes as JSON on the last line.
 *
 * @package BOGO_Select
 */

$reward_id = isset( $args[0] ) ? (int) $args[0] : 0;

/**
 * Create or replace a percentage coupon.
 *
 * @param string $code     Coupon code.
 * @param int    $percent  Discount percentage.
 * @param int[]  $excluded Product IDs the coupon must not touch.
 * @return string The code.
 */
function bogo_fixture_coupon( $code, $percent, $excluded = array() ) {
	$existing = wc_get_coupon_id_by_code( $code );

	if ( $existing ) {
		wp_delete_post( $existing, true );
	}

	$coupon = new WC_Coupon();
	$coupon->set_code( $code );
	$coupon->set_discount_type( 'percent' );
	$coupon->set_amount( $percent );

	if ( $excluded ) {
		$coupon->set_excluded_product_ids( array_map( 'intval', $excluded ) );
	}

	$coupon->save();

	return $coupon->get_code();
}

$stacking  = bogo_fixture_coupon( 'stack20', 20 );
$excluding = bogo_fixture_coupon( 'notreward20', 20, array( $reward_id ) );

echo wp_json_encode(
	array(
		'stacking'  => $stacking,
		'excluding' => $excluding,
	)
) . "\n";
