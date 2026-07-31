<?php
/**
 * Self-healing cart behaviour, including regression cover for F-01 and F-04.
 *
 * @package BOGO_Select
 */

namespace BOGO_Select\Tests;

use BOGO_Select_Cart;
use BOGO_Select_Engine;

/**
 * @covers BOGO_Select_Cart::validate
 */
class CartValidationTest extends TestCase {

	/**
	 * Subject under test.
	 *
	 * @var BOGO_Select_Cart
	 */
	protected $subject;

	/**
	 * Build a Buy 2 / Get 2 offer with one gift option.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->settings(
			array(
				'enabled'      => 'yes',
				'buy_qty'      => 2,
				'get_qty'      => 2,
				'buy_scope'    => 'all',
				'get_scope'    => 'select',
				'get_products' => array( 20 ),
			)
		);

		$this->subject = new BOGO_Select_Cart();
	}

	/**
	 * Run a validation pass over the session cart.
	 */
	protected function validate() {
		$this->subject->validate( $this->cart() );
	}

	/**
	 * Whether a cart line still exists.
	 *
	 * @param string $key Cart item key.
	 * @return bool
	 */
	protected function has_item( $key ) {
		return array_key_exists( $key, $this->cart()->get_cart() );
	}

	public function test_a_healthy_gift_survives_validation() {
		$this->product( 10 );
		$this->product( 20 );
		$this->add_paid_item( 'paid', 10, 2 );
		$this->add_gift_item( 'gift', 20, 2 );

		$this->validate();

		$this->assertTrue( $this->has_item( 'gift' ) );
		$this->assertSame( array(), $this->notices() );
	}

	/**
	 * F-01: stock can fall away underneath a cart whose earned quantity has not
	 * moved. Before 1.1.0 availability was only rechecked when the quantity
	 * changed, so the gift stayed put until checkout failed.
	 */
	public function test_gift_is_removed_when_stock_drops_though_the_earned_quantity_is_unchanged() {
		$this->product( 10 );
		$gift = $this->product(
			20,
			array(
				'managing_stock' => true,
				'stock_quantity' => 5,
			)
		);

		$this->add_paid_item( 'paid', 10, 2 );
		$this->add_gift_item( 'gift', 20, 2 );

		$this->validate();
		$this->assertTrue( $this->has_item( 'gift' ), 'Five in stock covers two free units.' );

		$gift->set( 'stock_quantity', 1 );
		$this->validate();

		$this->assertFalse( $this->has_item( 'gift' ) );
		$this->assertNoticeContains( 'Not enough stock' );
	}

	public function test_gift_is_removed_when_it_goes_out_of_stock_entirely() {
		$this->product( 10 );
		$gift = $this->product( 20 );

		$this->add_paid_item( 'paid', 10, 2 );
		$this->add_gift_item( 'gift', 20, 2 );

		$gift->set( 'in_stock', false );
		$this->validate();

		$this->assertFalse( $this->has_item( 'gift' ) );
		$this->assertNoticeContains( 'Out of stock' );
	}

	/**
	 * F-01: paid and free copies of the same product draw on one stock pool.
	 */
	public function test_paid_and_free_copies_of_one_product_are_counted_together() {
		$this->product(
			20,
			array(
				'managing_stock' => true,
				'stock_quantity' => 3,
			)
		);

		$this->add_paid_item( 'paid', 20, 2 );
		$this->add_gift_item( 'gift', 20, 2 );

		$this->validate();

		$this->assertFalse( $this->has_item( 'gift' ), 'Two paid plus two free exceeds a stock of three.' );
		$this->assertNoticeContains( 'already in your cart' );
	}

	public function test_backorders_keep_the_gift_despite_short_stock() {
		$this->product( 10 );
		$this->product(
			20,
			array(
				'managing_stock' => true,
				'stock_quantity' => 0,
				'backorders'     => true,
			)
		);

		$this->add_paid_item( 'paid', 10, 2 );
		$this->add_gift_item( 'gift', 20, 2 );

		$this->validate();

		$this->assertTrue( $this->has_item( 'gift' ) );
	}

	/**
	 * F-04: only one gift line may survive a pass.
	 */
	public function test_duplicate_gift_lines_are_reduced_to_one() {
		$this->product( 10 );
		$this->product( 20 );

		$this->add_paid_item( 'paid', 10, 2 );
		$this->add_gift_item( 'gift-a', 20, 2 );
		$this->add_gift_item( 'gift-b', 20, 2 );

		$this->validate();

		$this->assertSame( array( 'gift-a' ), BOGO_Select_Engine::find_reward_keys( $this->cart() ) );
		$this->assertNoticeContains( 'Duplicate free gift lines' );
	}

	/**
	 * F-04: a duplicate must not shield an otherwise invalid gift from removal.
	 */
	public function test_duplicates_are_dropped_and_the_survivor_is_still_judged() {
		$this->product( 10 );
		$this->product( 20, array( 'in_stock' => false ) );

		$this->add_paid_item( 'paid', 10, 2 );
		$this->add_gift_item( 'gift-a', 20, 2 );
		$this->add_gift_item( 'gift-b', 20, 2 );

		$this->validate();

		$this->assertSame( array(), BOGO_Select_Engine::find_reward_keys( $this->cart() ) );
	}

	public function test_gift_quantity_follows_the_earned_quantity() {
		$this->settings(
			array(
				'enabled'      => 'yes',
				'buy_qty'      => 2,
				'get_qty'      => 1,
				'repeat'       => 'yes',
				'get_scope'    => 'select',
				'get_products' => array( 20 ),
			)
		);

		$this->product( 10 );
		$this->product( 20 );

		$this->add_paid_item( 'paid', 10, 6 );
		$this->add_gift_item( 'gift', 20, 1 );

		$this->validate();

		$this->assertSame( 3, (int) $this->cart()->get_cart_item( 'gift' )['quantity'] );
		$this->assertNoticeContains( 'quantity was updated' );
	}

	public function test_gift_is_removed_when_the_cart_stops_qualifying() {
		$this->product( 10 );
		$this->product( 20 );

		$this->add_paid_item( 'paid', 10, 1 );
		$this->add_gift_item( 'gift', 20, 2 );

		$this->validate();

		$this->assertFalse( $this->has_item( 'gift' ) );
		$this->assertNoticeContains( 'no longer qualifies' );
	}

	public function test_gift_is_removed_when_the_offer_is_switched_off() {
		$this->product( 10 );
		$this->product( 20 );

		$this->add_paid_item( 'paid', 10, 2 );
		$this->add_gift_item( 'gift', 20, 2 );

		$this->settings( array( 'enabled' => 'no' ) );
		$this->validate();

		$this->assertFalse( $this->has_item( 'gift' ) );
		$this->assertNoticeContains( 'no longer running' );
	}

	public function test_gift_is_removed_when_it_leaves_the_gift_list() {
		$this->product( 10 );
		$this->product( 20 );
		$this->product( 21 );

		$this->add_paid_item( 'paid', 10, 2 );
		$this->add_gift_item( 'gift', 20, 2 );

		$this->settings(
			array(
				'enabled'      => 'yes',
				'buy_qty'      => 2,
				'get_qty'      => 2,
				'get_scope'    => 'select',
				'get_products' => array( 21 ),
			)
		);
		$this->validate();

		$this->assertFalse( $this->has_item( 'gift' ) );
		$this->assertNoticeContains( 'no longer part of the promotion' );
	}

	public function test_a_deleted_gift_product_is_removed() {
		$this->product( 10 );
		$this->add_paid_item( 'paid', 10, 2 );
		$this->add_gift_item( 'gift', 20, 2 );

		$this->validate();

		$this->assertFalse( $this->has_item( 'gift' ) );
	}

	public function test_a_cart_with_no_gift_is_left_alone() {
		$this->product( 10 );
		$this->add_paid_item( 'paid', 10, 2 );

		$this->validate();

		$this->assertTrue( $this->has_item( 'paid' ) );
		$this->assertSame( array(), $this->notices() );
	}

	/**
	 * F-03: the choose endpoint holds two gift lines while it swaps them, so
	 * validation must stand down for that moment.
	 */
	public function test_validation_can_be_suspended_and_resumed() {
		$this->product( 10 );
		$this->product( 20 );

		$this->add_paid_item( 'paid', 10, 1 );
		$this->add_gift_item( 'gift', 20, 2 );

		BOGO_Select_Cart::suspend();
		$this->validate();
		$this->assertTrue( $this->has_item( 'gift' ), 'A suspended pass must change nothing.' );

		BOGO_Select_Cart::resume();
		$this->validate();
		$this->assertFalse( $this->has_item( 'gift' ) );
	}

	public function test_gift_price_is_forced_to_zero() {
		$this->product( 10 );
		$this->product( 20, array( 'price' => 25.0 ) );

		$this->add_paid_item( 'paid', 10, 2 );
		$this->add_gift_item( 'gift', 20, 2 );

		$this->subject->set_reward_price( $this->cart() );

		$this->assertSame( 0.0, $this->cart()->get_cart_item( 'gift' )['data']->get_price() );
		$this->assertSame( 10.0, $this->cart()->get_cart_item( 'paid' )['data']->get_price() );
	}

	/**
	 * F-07: the subtotal column covers the whole line, not one unit.
	 */
	public function test_subtotal_strikes_through_the_whole_line() {
		$this->product( 20, array( 'price' => 10.0 ) );
		$this->add_gift_item( 'gift', 20, 8 );

		$item = $this->cart()->get_cart_item( 'gift' );

		$this->assertStringContainsString( '80.00', $this->subject->label_subtotal( '', $item, 'gift' ) );
		$this->assertStringContainsString( '10.00', $this->subject->label_price( '', $item, 'gift' ) );
	}

	public function test_price_labels_leave_paid_lines_alone() {
		$this->product( 10, array( 'price' => 10.0 ) );
		$this->add_paid_item( 'paid', 10, 2 );

		$item = $this->cart()->get_cart_item( 'paid' );

		$this->assertSame( 'untouched', $this->subject->label_price( 'untouched', $item, 'paid' ) );
		$this->assertSame( 'untouched', $this->subject->label_subtotal( 'untouched', $item, 'paid' ) );
		$this->assertSame( 'untouched', $this->subject->label_name( 'untouched', $item, 'paid' ) );
		$this->assertSame( 'untouched', $this->subject->lock_quantity( 'untouched', 'paid', $item ) );
	}

	public function test_gift_quantity_input_is_replaced_with_static_text() {
		$this->product( 20 );
		$this->add_gift_item( 'gift', 20, 3 );

		$html = $this->subject->lock_quantity( '<input />', 'gift', $this->cart()->get_cart_item( 'gift' ) );

		$this->assertStringNotContainsString( '<input', $html );
		$this->assertStringContainsString( '3', $html );
	}
}
