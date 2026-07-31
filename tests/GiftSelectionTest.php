<?php
/**
 * Selecting, replacing, and removing the gift.
 *
 * Exercises the code path shared by the classic AJAX endpoint and the Store
 * API update callback, so both cart modes are covered by the same assertions.
 *
 * @package BOGO_Select
 */

namespace BOGO_Select\Tests;

use BOGO_Select_Ajax;
use BOGO_Select_Engine;
use BOGO_Test_Env;

/**
 * @covers BOGO_Select_Ajax::select_gift
 * @covers BOGO_Select_Ajax::clear_gift
 */
class GiftSelectionTest extends TestCase {

	/**
	 * A qualifying cart with two products available as gifts.
	 *
	 * @return int[] Gift product IDs.
	 */
	protected function qualifying_cart() {
		$this->settings(
			array(
				'enabled'   => 'yes',
				'buy_scope' => 'all',
				'get_scope' => 'all',
				'buy_qty'   => 1,
				'get_qty'   => 1,
			)
		);

		$this->product( 10, array( 'name' => 'Bought thing' ) );
		$this->product( 20, array( 'name' => 'Gift A' ) );
		$this->product( 30, array( 'name' => 'Gift B' ) );

		$this->add_paid_item( 'paid', 10, 1 );

		return array( 20, 30 );
	}

	public function test_choosing_a_gift_adds_a_flagged_line_at_the_earned_quantity() {
		$this->qualifying_cart();

		$result = BOGO_Select_Ajax::select_gift( $this->cart(), 20 );

		$this->assertSame( 1, $result );

		$keys = BOGO_Select_Engine::find_reward_keys( $this->cart() );

		$this->assertCount( 1, $keys );
		$this->assertSame( 20, BOGO_Select_Engine::selected_product_id( $this->cart() ) );
	}

	public function test_choosing_a_different_gift_replaces_the_first_one() {
		$this->qualifying_cart();

		BOGO_Select_Ajax::select_gift( $this->cart(), 20 );
		BOGO_Select_Ajax::select_gift( $this->cart(), 30 );

		$this->assertCount( 1, BOGO_Select_Engine::find_reward_keys( $this->cart() ) );
		$this->assertSame( 30, BOGO_Select_Engine::selected_product_id( $this->cart() ) );
	}

	public function test_a_refused_replacement_leaves_the_original_gift_alone() {
		$this->qualifying_cart();

		BOGO_Select_Ajax::select_gift( $this->cart(), 20 );

		// Core stock validation, or a third-party validation callback, says no.
		BOGO_Test_Env::$reject_add_to_cart = 'Sorry, that cannot be purchased.';

		$result = BOGO_Select_Ajax::select_gift( $this->cart(), 30 );

		$this->assertSame( 'Sorry, that cannot be purchased.', $result );
		$this->assertSame( 20, BOGO_Select_Engine::selected_product_id( $this->cart() ), 'The customer keeps the gift they had.' );
	}

	public function test_re_picking_the_current_gift_only_corrects_its_quantity() {
		$this->settings(
			array(
				'enabled'   => 'yes',
				'buy_scope' => 'all',
				'get_scope' => 'all',
				'buy_qty'   => 1,
				'get_qty'   => 2,
			)
		);

		$this->product( 10 );
		$this->product( 20 );
		$this->add_paid_item( 'paid', 10, 1 );
		$this->add_gift_item( 'gift', 20, 1 );

		$result = BOGO_Select_Ajax::select_gift( $this->cart(), 20 );

		$this->assertSame( 2, $result );
		$this->assertCount( 1, BOGO_Select_Engine::find_reward_keys( $this->cart() ) );
		$this->assertSame( 2, (int) $this->cart()->get_cart_item( 'gift' )['quantity'] );
	}

	public function test_a_cart_that_does_not_qualify_is_refused() {
		$this->settings(
			array(
				'enabled'   => 'yes',
				'buy_scope' => 'all',
				'get_scope' => 'all',
				'buy_qty'   => 3,
			)
		);

		$this->product( 10 );
		$this->product( 20 );
		$this->add_paid_item( 'paid', 10, 1 );

		$this->assertIsString( BOGO_Select_Ajax::select_gift( $this->cart(), 20 ) );
		$this->assertSame( array(), BOGO_Select_Engine::find_reward_keys( $this->cart() ) );
	}

	public function test_a_product_outside_the_gift_list_is_refused() {
		$this->product( 10 );
		$this->product( 20 );
		$this->product( 30 );

		$this->settings(
			array(
				'enabled'      => 'yes',
				'buy_scope'    => 'all',
				'get_scope'    => 'select',
				'get_products' => array( 20 ),
			)
		);

		$this->add_paid_item( 'paid', 10, 1 );

		$this->assertIsString( BOGO_Select_Ajax::select_gift( $this->cart(), 30 ) );
		$this->assertSame( 1, BOGO_Select_Ajax::select_gift( $this->cart(), 20 ) );
	}

	public function test_a_gift_without_the_stock_to_cover_it_is_refused() {
		$this->settings(
			array(
				'enabled'   => 'yes',
				'buy_scope' => 'all',
				'get_scope' => 'all',
				'buy_qty'   => 1,
				'get_qty'   => 3,
			)
		);

		$this->product( 10 );
		$this->product(
			20,
			array(
				'managing_stock' => true,
				'stock_quantity' => 2,
			)
		);

		$this->add_paid_item( 'paid', 10, 1 );

		$this->assertIsString( BOGO_Select_Ajax::select_gift( $this->cart(), 20 ) );
	}

	public function test_clearing_removes_every_gift_line() {
		$this->qualifying_cart();

		$this->add_gift_item( 'gift-1', 20, 1 );
		$this->add_gift_item( 'gift-2', 30, 1 );

		$this->assertTrue( BOGO_Select_Ajax::clear_gift( $this->cart() ) );
		$this->assertSame( array(), BOGO_Select_Engine::find_reward_keys( $this->cart() ) );
		$this->assertFalse( BOGO_Select_Ajax::clear_gift( $this->cart() ) );
	}

	public function test_validation_is_never_left_suspended() {
		$this->qualifying_cart();

		BOGO_Test_Env::$reject_add_to_cart = 'No.';
		BOGO_Select_Ajax::select_gift( $this->cart(), 20 );
		BOGO_Test_Env::$reject_add_to_cart = '';

		// A suspended validator would ignore this drifted cart; a resumed one
		// culls the duplicate gift line.
		$this->add_gift_item( 'gift-1', 20, 1 );
		$this->add_gift_item( 'gift-2', 30, 1 );

		( new \BOGO_Select_Cart() )->validate( $this->cart() );

		$this->assertCount( 1, BOGO_Select_Engine::find_reward_keys( $this->cart() ) );
	}
}
