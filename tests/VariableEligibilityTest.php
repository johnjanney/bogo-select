<?php
/**
 * Which products may be offered, and which may actually be awarded.
 *
 * The two questions are separate once variations exist: a variable product is
 * offered but never awarded as itself. See PLAN-VARIABLE.md §4.
 *
 * @package BOGO_Select
 */

namespace BOGO_Select\Tests;

use BOGO_Select_Engine;

/**
 * @covers BOGO_Select_Engine::is_choice
 * @covers BOGO_Select_Engine::is_awardable
 * @covers BOGO_Select_Engine::offerable_variation_ids
 */
class VariableEligibilityTest extends TestCase {

	/**
	 * A curated offer listing the given IDs.
	 *
	 * @param array $ids Get product IDs.
	 */
	protected function offering( array $ids ) {
		$this->settings(
			array(
				'enabled'      => 'yes',
				'get_scope'    => 'select',
				'get_products' => $ids,
			)
		);
	}

	public function test_a_variable_parent_is_a_choice_but_never_awardable_as_itself() {
		$this->variable_product( 100, array( 101 => array(), 102 => array() ) );
		$this->offering( array( 100 ) );

		$this->assertTrue( BOGO_Select_Engine::is_choice( 100 ) );
		$this->assertFalse( BOGO_Select_Engine::is_awardable( 100 ) );
	}

	public function test_listing_the_parent_makes_its_variations_awardable() {
		$this->variable_product( 100, array( 101 => array(), 102 => array() ) );
		$this->offering( array( 100 ) );

		$this->assertTrue( BOGO_Select_Engine::is_awardable( 100, 101 ) );
		$this->assertTrue( BOGO_Select_Engine::is_awardable( 100, 102 ) );
	}

	public function test_a_pinned_variation_is_a_choice_and_awardable() {
		$this->variable_product( 100, array( 101 => array(), 102 => array() ) );
		$this->offering( array( 101 ) );

		$this->assertTrue( BOGO_Select_Engine::is_choice( 101 ) );
		$this->assertTrue( BOGO_Select_Engine::is_awardable( 100, 101 ) );

		// Its sibling was not listed, and its parent was not listed either.
		$this->assertFalse( BOGO_Select_Engine::is_choice( 102 ) );
		$this->assertFalse( BOGO_Select_Engine::is_awardable( 100, 102 ) );
		$this->assertFalse( BOGO_Select_Engine::is_choice( 100 ) );
	}

	public function test_a_variation_may_be_named_without_its_parent() {
		// The cart and the settings both hold bare IDs in places.
		$this->variable_product( 100, array( 101 => array() ) );
		$this->offering( array( 101 ) );

		$this->assertTrue( BOGO_Select_Engine::is_awardable( 101 ) );
	}

	public function test_a_variation_of_an_unlisted_parent_is_refused() {
		$this->variable_product( 100, array( 101 => array() ) );
		$this->variable_product( 200, array( 201 => array() ) );
		$this->offering( array( 100 ) );

		$this->assertFalse( BOGO_Select_Engine::is_awardable( 200, 201 ) );
	}

	public function test_a_variation_must_really_belong_to_the_parent_it_claims() {
		// The browser sends both halves of the pair. Without this check, naming an
		// in-scope parent alongside any variation ID in the catalogue would award
		// that variation.
		$this->variable_product( 100, array( 101 => array() ) );
		$this->variable_product( 200, array( 201 => array() ) );
		$this->offering( array( 100 ) );

		$this->assertFalse(
			BOGO_Select_Engine::is_awardable( 100, 201 ),
			'a variation of another parent was awarded through an in-scope parent'
		);
	}

	public function test_a_variation_leaving_an_attribute_open_is_not_offerable() {
		$this->variable_product(
			100,
			array(
				101 => array( 'attributes' => array( 'size' => 'small' ) ),
				102 => array( 'attributes' => array( 'size' => '' ) ),
			)
		);
		$this->offering( array( 100, 102 ) );

		$this->assertTrue( BOGO_Select_Engine::is_awardable( 100, 101 ) );
		$this->assertFalse( BOGO_Select_Engine::is_awardable( 100, 102 ) );
		$this->assertFalse( BOGO_Select_Engine::is_choice( 102 ) );
		$this->assertSame( array( 101 ), BOGO_Select_Engine::offerable_variation_ids( wc_get_product( 100 ) ) );
	}

	public function test_a_parent_with_nothing_offerable_is_not_a_choice() {
		$this->variable_product(
			100,
			array(
				101 => array( 'purchasable' => false ),
				102 => array( 'attributes' => array( 'size' => '' ) ),
			)
		);
		$this->offering( array( 100 ) );

		$this->assertSame( array(), BOGO_Select_Engine::offerable_variation_ids( wc_get_product( 100 ) ) );
		$this->assertFalse( BOGO_Select_Engine::is_choice( 100 ) );
	}

	public function test_an_unpurchasable_variation_is_refused() {
		$this->variable_product(
			100,
			array(
				101 => array(),
				102 => array( 'purchasable' => false ),
			)
		);
		$this->offering( array( 100 ) );

		$this->assertTrue( BOGO_Select_Engine::is_awardable( 100, 101 ) );
		$this->assertFalse( BOGO_Select_Engine::is_awardable( 100, 102 ) );
	}

	public function test_grouped_and_external_products_are_still_refused() {
		$this->product( 30, array( 'type' => 'grouped' ) );
		$this->product( 31, array( 'type' => 'external' ) );
		$this->offering( array( 30, 31 ) );

		foreach ( array( 30, 31 ) as $id ) {
			$this->assertFalse( BOGO_Select_Engine::is_choice( $id ) );
			$this->assertFalse( BOGO_Select_Engine::is_awardable( $id ) );
		}
	}

	public function test_simple_products_behave_exactly_as_before() {
		$this->product( 20 );
		$this->offering( array( 20 ) );

		$this->assertTrue( BOGO_Select_Engine::is_choice( 20 ) );
		$this->assertTrue( BOGO_Select_Engine::is_awardable( 20 ) );
		$this->assertTrue( BOGO_Select_Engine::is_get_eligible( 20 ) );

		$this->product( 21 );

		$this->assertFalse( BOGO_Select_Engine::is_choice( 21 ) );
		$this->assertFalse( BOGO_Select_Engine::is_awardable( 21 ) );
	}

	public function test_the_legacy_entry_point_still_refuses_a_lone_variation() {
		// Transitional, and removed in PLAN-VARIABLE.md step 3. The chooser filter
		// and the selection endpoint still call is_get_eligible() with one
		// argument and have nowhere to put a variation, so it must keep saying no
		// until they carry the pair — even though is_awardable() rightly says yes.
		$this->variable_product( 100, array( 101 => array() ) );
		$this->offering( array( 101 ) );

		$this->assertTrue( BOGO_Select_Engine::is_awardable( 101 ) );
		$this->assertFalse( BOGO_Select_Engine::is_get_eligible( 101 ) );

		// Named as a pair it is fine, because the caller has both halves.
		$this->assertTrue( BOGO_Select_Engine::is_get_eligible( 100, 101 ) );
	}

	public function test_all_products_scope_offers_variable_parents_but_not_variations() {
		$this->settings(
			array(
				'enabled'   => 'yes',
				'get_scope' => 'all',
			)
		);
		$this->product( 20 );
		$this->variable_product( 100, array( 101 => array() ) );

		$this->assertTrue( BOGO_Select_Engine::is_choice( 20 ) );
		$this->assertTrue( BOGO_Select_Engine::is_choice( 100 ) );

		// Never enumerated as a card of its own, but awardable through its parent.
		$this->assertFalse( BOGO_Select_Engine::is_choice( 101 ) );
		$this->assertTrue( BOGO_Select_Engine::is_awardable( 100, 101 ) );
	}

	public function test_an_unpublished_parent_is_out_of_scope_in_all_products() {
		$this->settings(
			array(
				'enabled'   => 'yes',
				'get_scope' => 'all',
			)
		);
		$this->variable_product( 100, array( 101 => array() ), array( 'status' => 'draft' ) );

		$this->assertFalse( BOGO_Select_Engine::is_choice( 100 ) );
		$this->assertFalse( BOGO_Select_Engine::is_awardable( 100, 101 ) );
	}
}
