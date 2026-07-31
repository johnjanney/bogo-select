<?php
/**
 * The fake catalogue's variable products.
 *
 * The stub is not the thing under test anywhere else in this suite, but the
 * variable-product work in PLAN-VARIABLE.md rests entirely on it: eligibility,
 * the chooser, and cart validation all read a parent's children and a
 * variation's parent. If those relationships are modelled wrongly here, every
 * test built on them measures something WooCommerce does not do.
 *
 * The cart-line clone fix earned that caution — the stub shared one product
 * object between the catalogue and the cart line, which no real store does.
 *
 * @package BOGO_Select
 */

namespace BOGO_Select\Tests;

use WC_Product;

/**
 * @covers ::wc_get_product
 */
class VariableProductStubTest extends TestCase {

	public function test_a_parent_knows_its_children() {
		$parent = $this->variable_product(
			100,
			array(
				101 => array( 'attributes' => array( 'size' => 'small' ) ),
				102 => array( 'attributes' => array( 'size' => 'large' ) ),
			)
		);

		$this->assertTrue( $parent->is_type( 'variable' ) );
		$this->assertSame( array( 101, 102 ), $parent->get_children() );
		$this->assertSame( 0, $parent->get_parent_id() );
	}

	public function test_a_variation_knows_its_parent() {
		$this->variable_product( 100, array( 101 => array() ) );

		$variation = wc_get_product( 101 );

		$this->assertInstanceOf( WC_Product::class, $variation );
		$this->assertTrue( $variation->is_type( 'variation' ) );
		$this->assertSame( 100, $variation->get_parent_id() );
		$this->assertSame( array(), $variation->get_children() );
	}

	public function test_a_simple_product_has_neither() {
		$product = $this->product( 20 );

		$this->assertTrue( $product->is_type( 'simple' ) );
		$this->assertSame( array(), $product->get_children() );
		$this->assertSame( 0, $product->get_parent_id() );
	}

	public function test_variations_carry_their_own_price_and_stock() {
		// The parent's price is the low end of a range in WooCommerce and is not
		// any variation's price. Each variation answers for itself.
		$this->variable_product(
			100,
			array(
				101 => array( 'price' => 20.0 ),
				102 => array(
					'price'          => 35.0,
					'managing_stock' => true,
					'stock_quantity' => 2,
				),
			),
			array( 'price' => 20.0 )
		);

		$this->assertSame( 20.0, wc_get_product( 101 )->get_price() );
		$this->assertSame( 35.0, wc_get_product( 102 )->get_price() );

		$this->assertTrue( wc_get_product( 102 )->has_enough_stock( 2 ) );
		$this->assertFalse( wc_get_product( 102 )->has_enough_stock( 3 ) );

		// 101 does not track stock, so it never runs out.
		$this->assertTrue( wc_get_product( 101 )->has_enough_stock( 99 ) );
	}

	public function test_an_any_attribute_is_an_empty_value() {
		// WooCommerce spells "any size" as an empty string rather than by leaving
		// the attribute out, so code that only counts attributes cannot tell the
		// difference. PLAN-VARIABLE.md §1 excludes these from being offerable.
		$this->variable_product(
			100,
			array(
				101 => array( 'attributes' => array( 'size' => 'small' ) ),
				102 => array( 'attributes' => array( 'size' => '' ) ),
			)
		);

		$this->assertSame( array( 'size' => 'small' ), wc_get_product( 101 )->get_variation_attributes() );
		$this->assertSame( array( 'size' => '' ), wc_get_product( 102 )->get_variation_attributes() );

		$this->assertNotContains( '', wc_get_product( 101 )->get_variation_attributes() );
		$this->assertContains( '', wc_get_product( 102 )->get_variation_attributes() );
	}

	public function test_variations_may_share_the_parents_stock_pool() {
		// Inheriting stock is expressed by pointing every variation's stock record
		// at the parent, which is what BOGO_Select_Engine::stock_demand() matches
		// on. Choosing one variation can therefore exhaust another.
		$this->variable_product(
			100,
			array(
				101 => array( 'stock_managed_by' => 100 ),
				102 => array( 'stock_managed_by' => 100 ),
			)
		);

		$this->assertSame( 100, wc_get_product( 101 )->get_stock_managed_by_id() );
		$this->assertSame( 100, wc_get_product( 102 )->get_stock_managed_by_id() );

		// Where stock is tracked per variation, each answers for itself.
		$this->variable_product( 200, array( 201 => array() ) );

		$this->assertSame( 201, wc_get_product( 201 )->get_stock_managed_by_id() );
	}

	public function test_a_cart_line_takes_the_variations_product_not_the_parents() {
		// WooCommerce puts the variation on the line, because that is what carries
		// the price. BOGO_Select_Cart::line_product() relies on this.
		$this->variable_product(
			100,
			array(
				101 => array( 'price' => 20.0 ),
			),
			array( 'price' => 5.0 )
		);

		$this->cart()->add_item(
			'line',
			array(
				'product_id'   => 100,
				'variation_id' => 101,
				'quantity'     => 1,
			)
		);

		$data = $this->cart()->get_cart_item( 'line' )['data'];

		$this->assertSame( 101, $data->get_id() );
		$this->assertSame( 20.0, $data->get_price() );
	}

	public function test_a_cart_line_still_gets_its_own_product_object() {
		// The clone that keeps repeated pricing passes from compounding has to
		// hold for variations too, not only for the simple products it was
		// written against.
		$this->variable_product( 100, array( 101 => array( 'price' => 20.0 ) ) );

		$this->cart()->add_item(
			'line',
			array(
				'product_id'   => 100,
				'variation_id' => 101,
				'quantity'     => 1,
			)
		);

		$this->cart()->get_cart_item( 'line' )['data']->set_price( 0 );

		$this->assertSame( 20.0, wc_get_product( 101 )->get_price(), 'the catalogue variation was disturbed' );
	}
}
