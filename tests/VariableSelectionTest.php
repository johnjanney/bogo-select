<?php
/**
 * Awarding a variation as the reward.
 *
 * Exercises the path shared by the classic AJAX endpoint and the Store API
 * update callback, so both cart modes are covered by the same assertions.
 * See PLAN-VARIABLE.md §3.
 *
 * @package BOGO_Select
 */

namespace BOGO_Select\Tests;

use BOGO_Select_Ajax;
use BOGO_Select_Cart;
use BOGO_Select_Engine;

/**
 * @covers BOGO_Select_Ajax::select_gift
 * @covers BOGO_Select_Engine::reward_pair
 * @covers BOGO_Select_Engine::selected_variation_id
 */
class VariableSelectionTest extends TestCase {

	/**
	 * A qualifying cart offering one variable product with two variations.
	 */
	protected function qualifying_cart() {
		$this->settings(
			array(
				'enabled'      => 'yes',
				'buy_scope'    => 'all',
				'get_scope'    => 'select',
				'get_products' => array( 100 ),
				'buy_qty'      => 1,
				'get_qty'      => 1,
			)
		);

		$this->product( 10, array( 'name' => 'Bought thing' ) );
		$this->variable_product(
			100,
			array(
				101 => array( 'name' => 'Tee - Small', 'price' => 20.0 ),
				102 => array( 'name' => 'Tee - Large', 'price' => 25.0 ),
			),
			array( 'name' => 'Tee', 'price' => 20.0 )
		);

		$this->add_paid_item( 'paid', 10, 1 );
	}

	/**
	 * The reward line, or null.
	 *
	 * @return array|null
	 */
	protected function reward_line() {
		$key = BOGO_Select_Engine::find_reward_key( $this->cart() );

		return $key ? $this->cart()->get_cart_item( $key ) : null;
	}

	public function test_choosing_a_variation_builds_a_proper_cart_line() {
		$this->qualifying_cart();

		$this->assertSame( 1, BOGO_Select_Ajax::select_gift( $this->cart(), 100, 101 ) );

		$line = $this->reward_line();

		$this->assertNotNull( $line );
		$this->assertSame( 100, (int) $line['product_id'], 'the line must carry the parent' );
		$this->assertSame( 101, (int) $line['variation_id'], 'and the variation' );
	}

	public function test_a_bare_variation_id_is_resolved_to_its_parent() {
		// The chooser sends both halves, but a filter or an integration may name
		// the variation alone. It must not land as a product_id.
		$this->qualifying_cart();

		$this->assertSame( 1, BOGO_Select_Ajax::select_gift( $this->cart(), 101 ) );

		$line = $this->reward_line();

		$this->assertSame( 100, (int) $line['product_id'] );
		$this->assertSame( 101, (int) $line['variation_id'] );
	}

	public function test_the_engine_reports_both_halves_of_the_selection() {
		$this->qualifying_cart();
		BOGO_Select_Ajax::select_gift( $this->cart(), 100, 101 );

		$this->assertSame( 100, BOGO_Select_Engine::selected_product_id( $this->cart() ) );
		$this->assertSame( 101, BOGO_Select_Engine::selected_variation_id( $this->cart() ) );

		$state = BOGO_Select_Engine::state( $this->cart() );

		$this->assertSame( 100, $state['selected_product_id'] );
		$this->assertSame( 101, $state['selected_variation_id'] );
	}

	public function test_swapping_between_siblings_replaces_rather_than_duplicates() {
		// Both variations share a parent, so a comparison on product_id alone
		// would treat this as re-picking the same reward and change nothing.
		$this->qualifying_cart();

		BOGO_Select_Ajax::select_gift( $this->cart(), 100, 101 );
		BOGO_Select_Ajax::select_gift( $this->cart(), 100, 102 );

		$this->assertCount( 1, BOGO_Select_Engine::find_reward_keys( $this->cart() ) );
		$this->assertSame( 102, BOGO_Select_Engine::selected_variation_id( $this->cart() ) );
	}

	public function test_repicking_the_same_variation_is_a_no_op() {
		$this->qualifying_cart();

		BOGO_Select_Ajax::select_gift( $this->cart(), 100, 101 );
		$first = BOGO_Select_Engine::find_reward_key( $this->cart() );

		BOGO_Select_Ajax::select_gift( $this->cart(), 100, 101 );

		$this->assertSame( $first, BOGO_Select_Engine::find_reward_key( $this->cart() ) );
		$this->assertCount( 1, BOGO_Select_Engine::find_reward_keys( $this->cart() ) );
	}

	public function test_the_signature_changes_when_the_variation_changes() {
		// Two variations of one parent differ only in the variation ID, so a
		// signature blind to it would not re-render the chooser after a swap.
		$this->qualifying_cart();

		BOGO_Select_Ajax::select_gift( $this->cart(), 100, 101 );
		$before = BOGO_Select_Engine::state_signature( $this->cart() );

		BOGO_Select_Ajax::select_gift( $this->cart(), 100, 102 );

		$this->assertNotSame( $before, BOGO_Select_Engine::state_signature( $this->cart() ) );
	}

	public function test_a_variation_of_another_parent_is_refused() {
		$this->qualifying_cart();
		$this->variable_product( 200, array( 201 => array() ) );

		$result = BOGO_Select_Ajax::select_gift( $this->cart(), 100, 201 );

		$this->assertIsString( $result, 'a foreign variation was awarded through an in-scope parent' );
		$this->assertNull( $this->reward_line() );
	}

	public function test_the_variable_parent_cannot_be_awarded_on_its_own() {
		$this->qualifying_cart();

		$this->assertIsString( BOGO_Select_Ajax::select_gift( $this->cart(), 100 ) );
		$this->assertNull( $this->reward_line() );
	}

	public function test_the_reward_is_priced_from_the_chosen_variation() {
		$this->qualifying_cart();
		$this->settings(
			array(
				'enabled'            => 'yes',
				'buy_scope'          => 'all',
				'get_scope'          => 'select',
				'get_products'       => array( 100 ),
				'buy_qty'            => 1,
				'get_qty'            => 1,
				'get_discount_type'  => 'percent',
				'get_discount_value' => 50,
			)
		);

		BOGO_Select_Ajax::select_gift( $this->cart(), 100, 102 );

		$subject = new BOGO_Select_Cart();
		$subject->set_reward_price( $this->cart() );

		// The Large variation is 25.00, not the parent's 20.00.
		$this->assertSame( 12.5, (float) $this->reward_line()['data']->get_price() );
	}

	public function test_validation_keeps_a_variation_reward() {
		$this->qualifying_cart();
		BOGO_Select_Ajax::select_gift( $this->cart(), 100, 101 );

		$subject = new BOGO_Select_Cart();
		$subject->validate( $this->cart() );

		$this->assertNotNull( $this->reward_line(), 'validation dropped a valid variation reward' );
		$this->assertSame( 101, BOGO_Select_Engine::selected_variation_id( $this->cart() ) );
	}

	public function test_validation_drops_a_variation_that_leaves_the_offer() {
		$this->qualifying_cart();
		BOGO_Select_Ajax::select_gift( $this->cart(), 100, 101 );

		// The offer moves to a different product entirely.
		$this->product( 20 );
		$this->settings(
			array(
				'enabled'      => 'yes',
				'buy_scope'    => 'all',
				'get_scope'    => 'select',
				'get_products' => array( 20 ),
				'buy_qty'      => 1,
				'get_qty'      => 1,
			)
		);

		$subject = new BOGO_Select_Cart();
		$subject->validate( $this->cart() );

		$this->assertNull( $this->reward_line() );
	}
}
