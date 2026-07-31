<?php
/**
 * The reward's discounted price.
 *
 * @package BOGO_Select
 */

namespace BOGO_Select\Tests;

use BOGO_Select_Cart;
use BOGO_Select_Engine;
use BOGO_Select_Settings;

/**
 * @covers BOGO_Select_Engine::discount_factor
 * @covers BOGO_Select_Engine::reward_price
 * @covers BOGO_Select_Engine::is_free_reward
 * @covers BOGO_Select_Cart::set_reward_price
 */
class DiscountPricingTest extends TestCase {

	/**
	 * Price a reward line the way WooCommerce would, the given number of times.
	 *
	 * @param int $passes How many times totals are recalculated.
	 * @return float The line's unit price afterwards.
	 */
	protected function price_after_passes( $passes ) {
		$subject = new BOGO_Select_Cart();

		for ( $i = 0; $i < $passes; $i++ ) {
			$subject->set_reward_price( $this->cart() );
		}

		return (float) $this->cart()->get_cart_item( 'gift' )['data']->get_price();
	}

	public function test_an_unconfigured_store_gives_the_reward_away() {
		// The default, and what every install upgrading into this feature gets.
		$this->assertSame( 'free', BOGO_Select_Settings::get( 'get_discount_type' ) );
		$this->assertSame( 0.0, BOGO_Select_Engine::discount_factor() );
		$this->assertSame( 0.0, BOGO_Select_Engine::reward_price( 100.0 ) );
		$this->assertTrue( BOGO_Select_Engine::is_free_reward() );
	}

	public function test_a_percentage_takes_that_share_off() {
		$this->settings(
			array(
				'get_discount_type'  => 'percent',
				'get_discount_value' => 50,
			)
		);

		$this->assertSame( 0.5, BOGO_Select_Engine::discount_factor() );
		$this->assertSame( 50.0, BOGO_Select_Engine::reward_price( 100.0 ) );
		$this->assertFalse( BOGO_Select_Engine::is_free_reward() );
	}

	public function test_a_hundred_percent_is_free() {
		$this->settings(
			array(
				'get_discount_type'  => 'percent',
				'get_discount_value' => 100,
			)
		);

		$this->assertSame( 0.0, BOGO_Select_Engine::reward_price( 100.0 ) );
		$this->assertTrue( BOGO_Select_Engine::is_free_reward() );
	}

	public function test_zero_percent_charges_full_price() {
		// A pointless offer, but it must not silently become a free gift. The
		// admin warns about it rather than the engine second-guessing it.
		$this->settings(
			array(
				'get_discount_type'  => 'percent',
				'get_discount_value' => 0,
			)
		);

		$this->assertSame( 100.0, BOGO_Select_Engine::reward_price( 100.0 ) );
		$this->assertFalse( BOGO_Select_Engine::is_free_reward() );
	}

	public function test_the_discount_value_is_ignored_while_the_type_is_free() {
		$this->settings(
			array(
				'get_discount_type'  => 'free',
				'get_discount_value' => 25,
			)
		);

		$this->assertSame( 0.0, BOGO_Select_Engine::reward_price( 100.0 ) );
	}

	public function test_percentages_outside_the_range_are_clamped() {
		$this->settings(
			array(
				'get_discount_type'  => 'percent',
				'get_discount_value' => 150,
			)
		);

		$this->assertSame( 0.0, BOGO_Select_Engine::reward_price( 100.0 ) );

		$this->settings(
			array(
				'get_discount_type'  => 'percent',
				'get_discount_value' => -20,
			)
		);

		$this->assertSame( 100.0, BOGO_Select_Engine::reward_price( 100.0 ) );
	}

	public function test_an_unknown_discount_type_gives_the_reward_away() {
		$this->settings( array( 'get_discount_type' => 'amount' ) );

		$this->assertSame( 'free', BOGO_Select_Settings::get( 'get_discount_type' ) );
		$this->assertSame( 0.0, BOGO_Select_Engine::reward_price( 100.0 ) );
	}

	public function test_the_unit_price_is_rounded_to_the_stores_precision() {
		$this->settings(
			array(
				'get_discount_type'  => 'percent',
				'get_discount_value' => 33,
			)
		);

		// 9.99 × 0.67 = 6.6933, which must not reach the cart at four decimals.
		$this->assertSame( 6.69, BOGO_Select_Engine::reward_price( 9.99 ) );
	}

	public function test_rounding_happens_once_per_unit_not_once_per_line() {
		$this->settings(
			array(
				'get_discount_type'  => 'percent',
				'get_discount_value' => 33,
			)
		);

		// The rounded unit price multiplied out is what WooCommerce will charge,
		// so that is the figure the display has to agree with — not the line
		// total discounted and rounded as a whole, which would be 20.08.
		$unit = BOGO_Select_Engine::reward_price( 9.99 );

		$this->assertSame( 20.07, round( $unit * 3, 2 ) );
	}

	public function test_a_zero_priced_product_stays_at_zero() {
		$this->settings(
			array(
				'get_discount_type'  => 'percent',
				'get_discount_value' => 50,
			)
		);

		$this->assertSame( 0.0, BOGO_Select_Engine::reward_price( 0.0 ) );
	}

	public function test_the_cart_prices_a_free_reward_at_nothing() {
		$this->product( 20, array( 'price' => 100.0 ) );
		$this->add_gift_item( 'gift', 20 );

		$this->assertSame( 0.0, $this->price_after_passes( 1 ) );
	}

	public function test_the_cart_prices_a_discounted_reward() {
		$this->settings(
			array(
				'get_discount_type'  => 'percent',
				'get_discount_value' => 50,
			)
		);
		$this->product( 20, array( 'price' => 100.0 ) );
		$this->add_gift_item( 'gift', 20 );

		$this->assertSame( 50.0, $this->price_after_passes( 1 ) );
	}

	public function test_repeated_pricing_passes_do_not_compound_the_discount() {
		// WooCommerce recalculates totals more than once in some requests. Setting
		// a price to zero survives that; taking half off does not, unless the base
		// price is re-read rather than taken from the line this code just wrote to.
		// Three passes, because compounding twice is the shape the bug takes.
		$this->settings(
			array(
				'get_discount_type'  => 'percent',
				'get_discount_value' => 50,
			)
		);
		$this->product( 20, array( 'price' => 100.0 ) );
		$this->add_gift_item( 'gift', 20 );

		$this->assertSame( 50.0, $this->price_after_passes( 1 ) );
		$this->assertSame( 50.0, $this->price_after_passes( 3 ) );
		$this->assertSame( 50.0, $this->price_after_passes( 10 ) );
	}

	public function test_pricing_a_reward_does_not_disturb_the_catalogue() {
		$this->settings(
			array(
				'get_discount_type'  => 'percent',
				'get_discount_value' => 50,
			)
		);
		$this->product( 20, array( 'price' => 100.0 ) );
		$this->add_gift_item( 'gift', 20 );

		$this->price_after_passes( 3 );

		$this->assertSame( 100.0, (float) wc_get_product( 20 )->get_price() );
	}

	public function test_a_paid_line_of_the_same_product_keeps_its_price() {
		// The reward is one line; the same product bought outright is another, and
		// discounting the first must not touch the second (Q-006).
		$this->settings(
			array(
				'get_discount_type'  => 'percent',
				'get_discount_value' => 50,
			)
		);
		$this->product( 20, array( 'price' => 100.0 ) );
		$this->add_paid_item( 'paid', 20, 1 );
		$this->add_gift_item( 'gift', 20 );

		$this->price_after_passes( 3 );

		$this->assertSame( 100.0, (float) $this->cart()->get_cart_item( 'paid' )['data']->get_price() );
		$this->assertSame( 50.0, (float) $this->cart()->get_cart_item( 'gift' )['data']->get_price() );
	}

	public function test_a_reward_line_with_no_product_is_skipped_rather_than_fatal() {
		// No product 20 in the catalogue, so the line's 'data' is false. Pricing
		// must step over it and leave it for the validation pass to remove; the
		// isset() guard this replaced was true for false and called a method on it.
		$this->settings(
			array(
				'get_discount_type'  => 'percent',
				'get_discount_value' => 50,
			)
		);
		$this->add_gift_item( 'gift', 20 );

		$subject = new BOGO_Select_Cart();
		$subject->set_reward_price( $this->cart() );

		$this->assertFalse( $this->cart()->get_cart_item( 'gift' )['data'] );
	}
}
