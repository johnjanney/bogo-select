<?php
/**
 * Inspect the order that `order.test.mjs` placed.
 *
 * Run through `wp eval-file ... <order_id> <reward_id> <paid_id>`. Exits
 * non-zero and prints every failure, so the CI step fails loudly rather than
 * printing something nobody reads.
 *
 * This is where the plugin's order-side behaviour is checked for the first time
 * against real WooCommerce: the line item its checkout hook created, the meta it
 * wrote there, and the stock WooCommerce reduced afterwards.
 *
 * @package BOGO_Select
 */

list( $order_id, $reward_id, $paid_id ) = array_map( 'intval', array_pad( (array) $args, 3, 0 ) );

$expected_reward_qty   = isset( $args[3] ) ? (int) $args[3] : 2;
$expected_line_total   = isset( $args[4] ) ? (float) $args[4] : 20.0;
$expected_reward_stock = isset( $args[5] ) ? (int) $args[5] : 8;
$expected_paid_stock   = isset( $args[6] ) ? (int) $args[6] : 9;

/*
 * Accumulated through $GLOBALS rather than `global`, because WP-CLI includes an
 * eval-file inside a method: variables written at the top of this file are local
 * to that scope, so `global $checks` in a function below would bind to a
 * different, permanently empty variable. The first draft did exactly that and
 * reported "0/0 checks passed" while asserting nothing.
 */
$GLOBALS['bogo_failures'] = array();
$GLOBALS['bogo_checks']   = array();

/**
 * Record one assertion.
 *
 * @param string $name   What is being checked.
 * @param bool   $pass   Whether it held.
 * @param string $detail Shown when it did not.
 */
function bogo_check( $name, $pass, $detail = '' ) {
	$GLOBALS['bogo_checks'][] = array( $name, (bool) $pass, $detail );

	if ( ! $pass ) {
		$GLOBALS['bogo_failures'][] = $name . ( '' !== $detail ? ' — ' . $detail : '' );
	}
}

$order = wc_get_order( $order_id );

bogo_check( 'the order exists', (bool) $order, 'order ' . $order_id );

if ( $order ) {
	$reward_item = null;
	$paid_item   = null;

	foreach ( $order->get_items() as $item ) {
		if ( (int) $item->get_product_id() === $reward_id ) {
			$reward_item = $item;
		}

		if ( (int) $item->get_product_id() === $paid_id ) {
			$paid_item = $item;
		}
	}

	bogo_check( 'the paid line is on the order', (bool) $paid_item );
	bogo_check( 'the reward line is on the order', (bool) $reward_item );

	if ( $reward_item ) {
		bogo_check(
			'the reward line carries the earned quantity',
			(int) $reward_item->get_quantity() === $expected_reward_qty,
			'expected ' . $expected_reward_qty . ', got ' . $reward_item->get_quantity()
		);

		bogo_check(
			'the reward line was charged the discounted total',
			abs( (float) $reward_item->get_total() - $expected_line_total ) < 0.005,
			'expected ' . $expected_line_total . ', got ' . $reward_item->get_total()
		);

		// The hook under test: woocommerce_checkout_create_order_line_item.
		bogo_check(
			'the hidden reward flag was written',
			'yes' === $reward_item->get_meta( '_bogo_select_free' ),
			var_export( $reward_item->get_meta( '_bogo_select_free' ), true )
		);

		bogo_check(
			'the offer was recorded on the line',
			'percent:50' === $reward_item->get_meta( '_bogo_select_discount' ),
			var_export( $reward_item->get_meta( '_bogo_select_discount' ), true )
		);

		$visible = $reward_item->get_meta( 'Discounted item' );

		bogo_check(
			'the visible label names the discount',
			is_string( $visible ) && false !== strpos( $visible, '50% off' ),
			var_export( $visible, true )
		);
	}

	if ( $paid_item ) {
		bogo_check(
			'the paid line carries no reward flag',
			'' === (string) $paid_item->get_meta( '_bogo_select_free' ),
			var_export( $paid_item->get_meta( '_bogo_select_free' ), true )
		);
	}
}

// Stock is reduced when the order is placed, and by the quantity awarded rather
// than by one — which is the whole reason the fixture awards two.
$reward_stock = (int) get_post_meta( $reward_id, '_stock', true );
$paid_stock   = (int) get_post_meta( $paid_id, '_stock', true );

bogo_check(
	'stock fell by the reward quantity',
	$reward_stock === $expected_reward_stock,
	'expected ' . $expected_reward_stock . ', got ' . $reward_stock
);

bogo_check(
	'stock fell for the paid line too',
	$paid_stock === $expected_paid_stock,
	'expected ' . $expected_paid_stock . ', got ' . $paid_stock
);

echo "\nOrder assertions — WP-CLI\n\n";

$checks   = $GLOBALS['bogo_checks'];
$failures = $GLOBALS['bogo_failures'];

foreach ( $checks as $c ) {
	list( $name, $pass, $detail ) = $c;
	echo '  ' . ( $pass ? 'PASS' : 'FAIL' ) . '  ' . $name;
	echo ( ! $pass && '' !== $detail ) ? "\n          " . $detail : '';
	echo "\n";
}

$passed = count( array_filter( $checks, function ( $c ) { return $c[1]; } ) );

echo "\n" . $passed . '/' . count( $checks ) . " checks passed.\n";

if ( $failures ) {
	echo "\n" . count( $failures ) . " check(s) failed:\n";

	foreach ( $failures as $f ) {
		echo '  - ' . $f . "\n";
	}

	exit( 1 );
}
