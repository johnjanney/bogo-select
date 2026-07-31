<?php
/**
 * The reward's discounted price.
 *
 * @package BOGO_Select
 */

namespace BOGO_Select\Tests;

use BOGO_Select_Engine;
use BOGO_Select_Settings;

/**
 * @covers BOGO_Select_Engine::discount_factor
 * @covers BOGO_Select_Engine::reward_price
 * @covers BOGO_Select_Engine::is_free_reward
 */
class DiscountPricingTest extends TestCase {

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
}
