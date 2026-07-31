<?php
/**
 * Gift eligibility and availability.
 *
 * @package BOGO_Select
 */

namespace BOGO_Select\Tests;

use BOGO_Select_Engine;

/**
 * @covers BOGO_Select_Engine
 */
class AvailabilityTest extends TestCase {

	public function test_select_scope_admits_only_listed_products() {
		$this->settings(
			array(
				'enabled'      => 'yes',
				'get_scope'    => 'select',
				'get_products' => array( 20 ),
			)
		);
		$this->product( 20 );
		$this->product( 21 );

		$this->assertTrue( BOGO_Select_Engine::is_get_eligible( 20 ) );
		$this->assertFalse( BOGO_Select_Engine::is_get_eligible( 21 ) );
	}

	public function test_all_scope_admits_any_published_purchasable_product() {
		$this->settings( array( 'enabled' => 'yes', 'get_scope' => 'all' ) );
		$this->product( 20 );
		$this->product( 21, array( 'status' => 'draft' ) );
		$this->product( 22, array( 'purchasable' => false ) );

		$this->assertTrue( BOGO_Select_Engine::is_get_eligible( 20 ) );
		$this->assertFalse( BOGO_Select_Engine::is_get_eligible( 21 ) );
		$this->assertFalse( BOGO_Select_Engine::is_get_eligible( 22 ) );
	}

	public function test_unknown_products_are_never_eligible() {
		$this->settings( array( 'enabled' => 'yes', 'get_scope' => 'all' ) );

		$this->assertFalse( BOGO_Select_Engine::is_get_eligible( 999 ) );
		$this->assertFalse( BOGO_Select_Engine::is_get_eligible( 0 ) );
	}

	/**
	 * @dataProvider ineligible_types
	 *
	 * @param string $type Product type.
	 */
	public function test_ambiguous_product_types_cannot_be_gifts( $type ) {
		$this->settings(
			array(
				'enabled'      => 'yes',
				'get_scope'    => 'select',
				'get_products' => array( 20 ),
			)
		);
		$this->product( 20, array( 'type' => $type ) );

		$this->assertFalse( BOGO_Select_Engine::is_get_eligible( 20 ) );
	}

	/**
	 * Product types that must never be offered as a gift (DECISION.md D-006).
	 *
	 * @return array[]
	 */
	public function ineligible_types() {
		return array(
			'variable'  => array( 'variable' ),
			'variation' => array( 'variation' ),
			'grouped'   => array( 'grouped' ),
			'external'  => array( 'external' ),
		);
	}

	public function test_a_missing_product_is_unavailable() {
		$this->assertNotSame( '', BOGO_Select_Engine::unavailable_reason( null, 1 ) );
	}

	public function test_out_of_stock_products_are_unavailable() {
		$product = $this->product( 20, array( 'in_stock' => false ) );

		$this->assertSame( 'Out of stock', BOGO_Select_Engine::unavailable_reason( $product, 1 ) );
	}

	public function test_untracked_stock_is_always_enough() {
		$product = $this->product( 20, array( 'managing_stock' => false ) );

		$this->assertSame( '', BOGO_Select_Engine::unavailable_reason( $product, 500 ) );
	}

	public function test_tracked_stock_must_cover_the_whole_gift() {
		$product = $this->product(
			20,
			array(
				'managing_stock' => true,
				'stock_quantity' => 5,
			)
		);

		$this->assertSame( '', BOGO_Select_Engine::unavailable_reason( $product, 5 ) );
		$this->assertStringContainsString( 'Not enough stock', BOGO_Select_Engine::unavailable_reason( $product, 6 ) );
	}

	public function test_backorders_bypass_the_stock_check() {
		$product = $this->product(
			20,
			array(
				'managing_stock' => true,
				'stock_quantity' => 1,
				'backorders'     => true,
			)
		);

		$this->assertSame( '', BOGO_Select_Engine::unavailable_reason( $product, 8 ) );
	}

	public function test_units_already_in_the_cart_count_against_stock() {
		$product = $this->product(
			20,
			array(
				'managing_stock' => true,
				'stock_quantity' => 5,
			)
		);

		$this->assertSame( '', BOGO_Select_Engine::unavailable_reason( $product, 2, 3 ) );

		$reason = BOGO_Select_Engine::unavailable_reason( $product, 2, 4 );

		$this->assertStringContainsString( 'Not enough stock', $reason );
		$this->assertStringContainsString( 'already in your cart', $reason );
	}

	public function test_sold_individually_products_cannot_be_multi_unit_gifts() {
		$product = $this->product( 20, array( 'sold_individually' => true ) );

		$this->assertSame( '', BOGO_Select_Engine::unavailable_reason( $product, 1 ) );
		$this->assertSame( 'Limited to one per order', BOGO_Select_Engine::unavailable_reason( $product, 2 ) );
		$this->assertSame( 'Limited to one per order', BOGO_Select_Engine::unavailable_reason( $product, 1, 1 ) );
	}

	public function test_stock_demand_sums_lines_sharing_a_stock_record() {
		$this->product( 20 );
		$this->product( 30, array( 'stock_managed_by' => 20 ) );
		$this->product( 40 );

		$this->add_paid_item( 'paid', 20, 3 );
		$this->add_paid_item( 'variation', 30, 2 );
		$this->add_paid_item( 'other', 40, 7 );
		$this->add_gift_item( 'gift', 20, 4 );

		$product = wc_get_product( 20 );

		$this->assertSame( 9, BOGO_Select_Engine::stock_demand( $this->cart(), $product ) );
		$this->assertSame( 5, BOGO_Select_Engine::stock_demand( $this->cart(), $product, 'gift' ) );
	}

	public function test_stock_demand_is_zero_without_a_cart_or_product() {
		$this->assertSame( 0, BOGO_Select_Engine::stock_demand( null, null ) );
	}
}
