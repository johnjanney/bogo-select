<?php
/**
 * Cart counting, qualification, and repeat mode.
 *
 * @package BOGO_Select
 */

namespace BOGO_Select\Tests;

use BOGO_Select_Engine;

/**
 * @covers BOGO_Select_Engine
 */
class QualificationTest extends TestCase {

	public function test_offer_is_inactive_when_disabled() {
		$this->settings( array( 'enabled' => 'no' ) );

		$this->assertFalse( BOGO_Select_Engine::is_active() );
	}

	public function test_offer_is_inactive_when_a_select_list_is_empty() {
		$this->settings(
			array(
				'enabled'      => 'yes',
				'buy_scope'    => 'select',
				'buy_products' => array(),
				'get_scope'    => 'select',
				'get_products' => array( 20 ),
			)
		);

		$this->assertFalse( BOGO_Select_Engine::is_active() );

		$this->settings(
			array(
				'enabled'      => 'yes',
				'buy_scope'    => 'all',
				'get_scope'    => 'select',
				'get_products' => array(),
			)
		);

		$this->assertFalse( BOGO_Select_Engine::is_active() );
	}

	public function test_buy_count_sums_quantities_across_the_whole_cart() {
		$this->settings( array( 'enabled' => 'yes', 'get_products' => array( 20 ) ) );
		$this->product( 10 );
		$this->product( 11 );

		$this->add_paid_item( 'a', 10, 1 );
		$this->add_paid_item( 'b', 11, 2 );

		$this->assertSame( 3, BOGO_Select_Engine::count_buy_units( $this->cart() ) );
	}

	public function test_gift_lines_never_count_toward_qualification() {
		$this->settings( array( 'enabled' => 'yes', 'get_products' => array( 20 ) ) );
		$this->product( 10 );
		$this->product( 20 );

		$this->add_paid_item( 'a', 10, 2 );
		$this->add_gift_item( 'gift', 20, 8 );

		$this->assertSame( 2, BOGO_Select_Engine::count_buy_units( $this->cart() ) );
	}

	public function test_select_buy_scope_only_counts_listed_products() {
		$this->settings(
			array(
				'enabled'      => 'yes',
				'buy_scope'    => 'select',
				'buy_products' => array( 10 ),
				'get_products' => array( 20 ),
			)
		);
		$this->product( 10 );
		$this->product( 11 );

		$this->add_paid_item( 'a', 10, 2 );
		$this->add_paid_item( 'b', 11, 5 );

		$this->assertSame( 2, BOGO_Select_Engine::count_buy_units( $this->cart() ) );
	}

	public function test_a_buy_list_naming_a_parent_counts_every_variation() {
		$this->settings(
			array(
				'enabled'      => 'yes',
				'buy_scope'    => 'select',
				'buy_products' => array( 10 ),
				'get_products' => array( 20 ),
			)
		);
		$this->variable_product(
			10,
			array(
				101 => array( 'attributes' => array( 'size' => 'small' ) ),
				102 => array( 'attributes' => array( 'size' => 'large' ) ),
			)
		);

		$this->add_paid_item( 'a', 10, 2, 101 );
		$this->add_paid_item( 'b', 10, 3, 102 );

		$this->assertSame( 5, BOGO_Select_Engine::count_buy_units( $this->cart() ) );
	}

	public function test_a_buy_list_naming_one_variation_counts_only_that_variation() {
		// The settings screen offers variations on the Buy side, so an offer can
		// turn on one size rather than every size. The parent's ID is on every
		// variation's cart line, so the list has to be read as naming a variation
		// exactly and not the product behind it.
		$this->settings(
			array(
				'enabled'      => 'yes',
				'buy_scope'    => 'select',
				'buy_products' => array( 102 ),
				'get_products' => array( 20 ),
			)
		);
		$this->variable_product(
			10,
			array(
				101 => array( 'attributes' => array( 'size' => 'small' ) ),
				102 => array( 'attributes' => array( 'size' => 'large' ) ),
			)
		);

		$this->add_paid_item( 'a', 10, 4, 101 );
		$this->add_paid_item( 'b', 10, 3, 102 );

		$this->assertSame( 3, BOGO_Select_Engine::count_buy_units( $this->cart() ) );
	}

	public function test_mixed_products_from_the_buy_list_qualify_together() {
		// D-003: quantities are summed across the whole cart, so two different
		// listed products satisfy a Buy 2 offer between them. Answering
		// OPEN-QUESTIONS.md Q-002 fixed this as the behaviour rather than an
		// assumption, so it is worth a test that says so.
		$this->settings(
			array(
				'enabled'      => 'yes',
				'buy_qty'      => 2,
				'buy_scope'    => 'select',
				'buy_products' => array( 10, 11 ),
				'get_scope'    => 'select',
				'get_products' => array( 20 ),
			)
		);

		$this->product( 10 );
		$this->product( 11 );
		$this->product( 20 );

		$this->add_paid_item( 'a', 10, 1 );
		$this->add_paid_item( 'b', 11, 1 );

		$this->assertSame( 2, BOGO_Select_Engine::count_buy_units() );
		$this->assertTrue( BOGO_Select_Engine::qualifies() );
	}

	public function test_a_one_product_buy_list_requires_that_product() {
		// This is how a store expresses "buy 2 of the same thing" — it needs no
		// per-product counting mode, only a Buy list of one. Q-002 asked whether
		// such a mode was needed; this is why the answer is no for the single
		// product case, so it is held by a test rather than left as advice.
		$this->settings(
			array(
				'enabled'      => 'yes',
				'buy_qty'      => 2,
				'buy_scope'    => 'select',
				'buy_products' => array( 10 ),
				'get_scope'    => 'select',
				'get_products' => array( 20 ),
			)
		);

		$this->product( 10 );
		$this->product( 11 );
		$this->product( 20 );

		$this->add_paid_item( 'a', 10, 1 );
		$this->add_paid_item( 'b', 11, 1 );

		// The second line is not on the list, so it contributes nothing.
		$this->assertSame( 1, BOGO_Select_Engine::count_buy_units() );
		$this->assertFalse( BOGO_Select_Engine::qualifies() );

		$this->cart()->set_quantity( 'a', 2 );

		$this->assertSame( 2, BOGO_Select_Engine::count_buy_units() );
		$this->assertTrue( BOGO_Select_Engine::qualifies() );
	}

	public function test_a_variation_qualifies_through_its_parent_id() {
		$this->settings(
			array(
				'enabled'      => 'yes',
				'buy_scope'    => 'select',
				'buy_products' => array( 10 ),
				'get_products' => array( 20 ),
			)
		);
		$this->product( 10 );

		$this->add_paid_item( 'a', 10, 3, 99 );

		$this->assertSame( 3, BOGO_Select_Engine::count_buy_units( $this->cart() ) );
	}

	public function test_a_variation_qualifies_through_its_own_id() {
		$this->settings(
			array(
				'enabled'      => 'yes',
				'buy_scope'    => 'select',
				'buy_products' => array( 99 ),
				'get_products' => array( 20 ),
			)
		);
		$this->product( 10 );

		$this->add_paid_item( 'a', 10, 3, 99 );

		$this->assertSame( 3, BOGO_Select_Engine::count_buy_units( $this->cart() ) );
	}

	public function test_reward_quantity_without_repeat_awards_one_set() {
		$this->settings(
			array(
				'enabled'      => 'yes',
				'buy_qty'      => 2,
				'get_qty'      => 2,
				'repeat'       => 'no',
				'get_products' => array( 20 ),
			)
		);

		$this->assertSame( 0, BOGO_Select_Engine::reward_quantity( 1 ) );
		$this->assertSame( 2, BOGO_Select_Engine::reward_quantity( 2 ) );
		$this->assertSame( 2, BOGO_Select_Engine::reward_quantity( 6 ) );
	}

	public function test_reward_quantity_with_repeat_awards_a_set_per_multiple() {
		$this->settings(
			array(
				'enabled'      => 'yes',
				'buy_qty'      => 2,
				'get_qty'      => 1,
				'repeat'       => 'yes',
				'get_products' => array( 20 ),
			)
		);

		$this->assertSame( 0, BOGO_Select_Engine::reward_quantity( 1 ) );
		$this->assertSame( 1, BOGO_Select_Engine::reward_quantity( 2 ) );
		$this->assertSame( 3, BOGO_Select_Engine::reward_quantity( 6 ) );
		$this->assertSame( 3, BOGO_Select_Engine::reward_quantity( 7 ) );
	}

	public function test_buy_four_get_eight() {
		$this->settings(
			array(
				'enabled'      => 'yes',
				'buy_qty'      => 4,
				'get_qty'      => 8,
				'get_products' => array( 20 ),
			)
		);

		$this->assertSame( 8, BOGO_Select_Engine::reward_quantity( 4 ) );
	}

	public function test_qualification_can_be_overridden_by_filter() {
		$this->settings( array( 'enabled' => 'yes', 'buy_qty' => 5, 'get_qty' => 1, 'get_products' => array( 20 ) ) );

		add_filter(
			'bogo_select_qualifies',
			function ( $qualifies, $buy_count ) {
				return $buy_count >= 1;
			},
			10,
			2
		);

		$this->assertSame( 1, BOGO_Select_Engine::reward_quantity( 1 ) );
	}

	public function test_reward_quantity_can_be_overridden_by_filter() {
		$this->settings( array( 'enabled' => 'yes', 'buy_qty' => 1, 'get_qty' => 1, 'get_products' => array( 20 ) ) );

		add_filter(
			'bogo_select_reward_quantity',
			function ( $qty ) {
				return $qty * 10;
			}
		);

		$this->assertSame( 10, BOGO_Select_Engine::reward_quantity( 1 ) );
	}

	public function test_qualifies_reflects_the_cart() {
		$this->settings( array( 'enabled' => 'yes', 'buy_qty' => 2, 'get_qty' => 1, 'get_products' => array( 20 ) ) );
		$this->product( 10 );

		$this->add_paid_item( 'a', 10, 1 );
		$this->assertFalse( BOGO_Select_Engine::qualifies( $this->cart() ) );

		$this->cart()->set_quantity( 'a', 2 );
		$this->assertTrue( BOGO_Select_Engine::qualifies( $this->cart() ) );
	}

	public function test_reward_keys_are_enumerated_and_the_first_is_canonical() {
		$this->settings( array( 'enabled' => 'yes', 'get_products' => array( 20 ) ) );
		$this->product( 10 );
		$this->product( 20 );
		$this->product( 21 );

		$this->add_paid_item( 'paid', 10, 1 );
		$this->add_gift_item( 'gift-a', 20, 1 );
		$this->add_gift_item( 'gift-b', 21, 1 );

		$this->assertSame( array( 'gift-a', 'gift-b' ), BOGO_Select_Engine::find_reward_keys( $this->cart() ) );
		$this->assertSame( 'gift-a', BOGO_Select_Engine::find_reward_key( $this->cart() ) );
		$this->assertSame( 20, BOGO_Select_Engine::selected_product_id( $this->cart() ) );
	}

	public function test_no_reward_keys_in_an_ordinary_cart() {
		$this->product( 10 );
		$this->add_paid_item( 'paid', 10, 1 );

		$this->assertSame( array(), BOGO_Select_Engine::find_reward_keys( $this->cart() ) );
		$this->assertSame( '', BOGO_Select_Engine::find_reward_key( $this->cart() ) );
		$this->assertSame( 0, BOGO_Select_Engine::selected_product_id( $this->cart() ) );
	}
}
