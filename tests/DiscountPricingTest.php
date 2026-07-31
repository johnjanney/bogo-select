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

	/**
	 * Configure a percentage offer.
	 *
	 * @param float $percent Discount.
	 */
	protected function discount_of( $percent ) {
		$this->settings(
			array(
				'get_discount_type'  => 'percent',
				'get_discount_value' => $percent,
			)
		);
	}

	public function test_the_free_wording_is_unchanged() {
		// These strings shipped before discounts existed and are asserted verbatim
		// by BlocksTest and the block integration job. An unconfigured store must
		// read exactly as it always did.
		$this->assertSame( 'Free', BOGO_Select_Engine::reward_label() );
		$this->assertSame( 'free', BOGO_Select_Engine::reward_phrase() );
		$this->assertSame( 'free gift', BOGO_Select_Engine::reward_noun() );
		$this->assertSame(
			array(
				'label' => 'Free gift',
				'value' => 'BOGO promotion',
			),
			BOGO_Select_Engine::reward_meta()
		);
	}

	public function test_a_discount_is_worded_as_a_percentage() {
		$this->discount_of( 50 );

		$this->assertSame( '50% off', BOGO_Select_Engine::reward_label() );
		$this->assertSame( 'at 50% off', BOGO_Select_Engine::reward_phrase() );
		$this->assertSame( 'discounted item', BOGO_Select_Engine::reward_noun() );
		$this->assertSame(
			array(
				'label' => 'Discounted item',
				'value' => '50% off — BOGO promotion',
			),
			BOGO_Select_Engine::reward_meta()
		);
	}

	public function test_percentages_carry_only_the_decimals_they_need() {
		$this->discount_of( 50 );
		$this->assertSame( '50% off', BOGO_Select_Engine::reward_label() );

		$this->discount_of( 12.5 );
		$this->assertSame( '12.5% off', BOGO_Select_Engine::reward_label() );

		$this->discount_of( 33.33 );
		$this->assertSame( '33.33% off', BOGO_Select_Engine::reward_label() );
	}

	public function test_the_stock_message_describes_the_units_correctly() {
		$product = $this->product(
			20,
			array(
				'managing_stock' => true,
				'stock_quantity' => 5,
			)
		);

		$this->assertSame(
			'Not enough stock for 6 free units',
			BOGO_Select_Engine::unavailable_reason( $product, 6 )
		);

		$this->discount_of( 50 );

		$this->assertSame(
			'Not enough stock for 6 discounted units',
			BOGO_Select_Engine::unavailable_reason( $product, 6 )
		);
	}

	public function test_the_cart_badge_names_what_the_reward_costs() {
		$this->product( 20, array( 'price' => 100.0 ) );
		$this->add_gift_item( 'gift', 20 );

		$subject   = new BOGO_Select_Cart();
		$cart_item = $this->cart()->get_cart_item( 'gift' );

		$this->assertStringContainsString( 'Free (BOGO)', $subject->label_name( 'Apple', $cart_item ) );

		$this->discount_of( 50 );

		$this->assertStringContainsString( '50% off (BOGO)', $subject->label_name( 'Apple', $cart_item ) );
	}

	public function test_the_cart_shows_the_discounted_price_rather_than_the_word_free() {
		$this->discount_of( 50 );
		$this->product( 20, array( 'price' => 100.0 ) );
		$this->add_gift_item( 'gift', 20, 3 );

		$subject   = new BOGO_Select_Cart();
		$cart_item = $this->cart()->get_cart_item( 'gift' );

		$unit = $subject->label_price( '', $cart_item );
		$line = $subject->label_subtotal( '', $cart_item );

		$this->assertStringNotContainsString( 'Free', $unit );
		$this->assertStringContainsString( '50.00', $unit );
		$this->assertStringContainsString( '100.00', $unit, 'the undiscounted unit price is struck through' );

		// Three units, so the line is 150.00 against a struck-through 300.00.
		$this->assertStringContainsString( '150.00', $line );
		$this->assertStringContainsString( '300.00', $line );
	}

	public function test_a_free_reward_still_says_free_in_the_cart() {
		$this->product( 20, array( 'price' => 100.0 ) );
		$this->add_gift_item( 'gift', 20 );

		$subject = new BOGO_Select_Cart();

		$this->assertStringContainsString(
			'Free',
			$subject->label_price( '', $this->cart()->get_cart_item( 'gift' ) )
		);
	}

	public function test_the_order_line_records_what_the_offer_was() {
		$this->product( 20, array( 'price' => 100.0 ) );
		$this->add_gift_item( 'gift', 20 );

		$subject = new BOGO_Select_Cart();
		$values  = $this->cart()->get_cart_item( 'gift' );

		$free = new \WC_Order_Item_Product();
		$subject->add_order_item_meta( $free, 'gift', $values, null );

		$this->assertSame( 'yes', $free->get_meta( '_bogo_select_free' ) );
		$this->assertSame( 'free', $free->get_meta( '_bogo_select_discount' ) );
		$this->assertSame( 'BOGO promotion', $free->get_meta( 'Free gift' ) );

		$this->discount_of( 12.5 );

		$discounted = new \WC_Order_Item_Product();
		$subject->add_order_item_meta( $discounted, 'gift', $values, null );

		// The hidden flag keeps its name and value: existing reports query it.
		$this->assertSame( 'yes', $discounted->get_meta( '_bogo_select_free' ) );
		$this->assertSame( 'percent:12.5', $discounted->get_meta( '_bogo_select_discount' ) );
		$this->assertSame( '12.5% off — BOGO promotion', $discounted->get_meta( 'Discounted item' ) );
	}

	public function test_an_explicit_full_discount_is_recorded_as_a_percentage() {
		// A 100% campaign reads as "Free" to the customer, but it is not the Free
		// mode and reporting has to be able to tell them apart. The snapshot asks
		// the configured type, not the wording (`CODEX-REVIEW.md` L-01).
		$this->discount_of( 100 );

		$this->assertTrue( BOGO_Select_Engine::is_free_reward() );
		$this->assertSame( 'Free', BOGO_Select_Engine::reward_label() );
		$this->assertSame( 'percent:100', BOGO_Select_Engine::discount_snapshot() );

		$this->settings( array( 'get_discount_type' => 'free' ) );

		$this->assertSame( 'free', BOGO_Select_Engine::discount_snapshot() );
	}

	public function test_a_zero_discount_is_still_recorded_as_a_percentage() {
		$this->discount_of( 0 );

		$this->assertSame( 'percent:0', BOGO_Select_Engine::discount_snapshot() );
	}

	public function test_a_paid_line_records_nothing() {
		$this->product( 20, array( 'price' => 100.0 ) );
		$this->add_paid_item( 'paid', 20 );

		$subject = new BOGO_Select_Cart();
		$item    = new \WC_Order_Item_Product();

		$subject->add_order_item_meta( $item, 'paid', $this->cart()->get_cart_item( 'paid' ), null );

		$this->assertSame( array(), $item->meta );
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
