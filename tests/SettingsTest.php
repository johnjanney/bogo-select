<?php
/**
 * Settings normalization.
 *
 * @package BOGO_Select
 */

namespace BOGO_Select\Tests;

use BOGO_Select_Settings;
use BOGO_Test_Env;

/**
 * @covers BOGO_Select_Settings
 */
class SettingsTest extends TestCase {

	public function test_defaults_apply_when_nothing_is_stored() {
		$this->assertSame( 'no', BOGO_Select_Settings::get( 'enabled' ) );
		$this->assertSame( 1, BOGO_Select_Settings::get( 'buy_qty' ) );
		$this->assertSame( 1, BOGO_Select_Settings::get( 'get_qty' ) );
		$this->assertSame( 'all', BOGO_Select_Settings::get( 'buy_scope' ) );
		$this->assertSame( 'select', BOGO_Select_Settings::get( 'get_scope' ) );
		$this->assertSame( array(), BOGO_Select_Settings::get( 'get_products' ) );
		$this->assertFalse( BOGO_Select_Settings::is_enabled() );
		$this->assertFalse( BOGO_Select_Settings::is_repeating() );
	}

	public function test_quantities_are_clamped_to_at_least_one() {
		$this->settings(
			array(
				'buy_qty' => 0,
				'get_qty' => 'not a number',
			)
		);

		$this->assertSame( 1, BOGO_Select_Settings::get( 'buy_qty' ) );
		$this->assertSame( 1, BOGO_Select_Settings::get( 'get_qty' ) );
	}

	public function test_negative_quantities_take_their_absolute_value() {
		// absint() is WordPress's abs( intval() ), so -5 normalises to 5 rather
		// than to the minimum. The admin field is a number input with min="1",
		// so this only ever matters for hand-edited option rows.
		$this->settings( array( 'buy_qty' => -5 ) );

		$this->assertSame( 5, BOGO_Select_Settings::get( 'buy_qty' ) );
	}

	public function test_unknown_scope_values_fall_back_to_all() {
		$this->settings(
			array(
				'buy_scope' => 'category',
				'get_scope' => 'select',
			)
		);

		$this->assertSame( 'all', BOGO_Select_Settings::get( 'buy_scope' ) );
		$this->assertSame( 'select', BOGO_Select_Settings::get( 'get_scope' ) );
	}

	public function test_product_lists_accept_csv_and_drop_junk() {
		$this->settings(
			array(
				'get_products' => '12, 34, 0, abc, 34',
			)
		);

		$this->assertSame( array( 12, 34 ), BOGO_Select_Settings::get( 'get_products' ) );
	}

	public function test_boolean_settings_normalise_to_yes_or_no() {
		$this->settings(
			array(
				'enabled'     => '1',
				'repeat'      => true,
				'show_notice' => 'off',
			)
		);

		$this->assertSame( 'yes', BOGO_Select_Settings::get( 'enabled' ) );
		$this->assertSame( 'yes', BOGO_Select_Settings::get( 'repeat' ) );
		$this->assertSame( 'no', BOGO_Select_Settings::get( 'show_notice' ) );
	}

	public function test_a_corrupt_option_falls_back_to_defaults() {
		update_option( BOGO_Select_Settings::OPTION, 'not-an-array' );
		BOGO_Select_Settings::flush();

		$this->assertSame( 'no', BOGO_Select_Settings::get( 'enabled' ) );
		$this->assertSame( 1, BOGO_Select_Settings::get( 'buy_qty' ) );
	}

	public function test_sanitize_rejects_unchecked_boxes_and_blank_titles() {
		$clean = BOGO_Select_Settings::sanitize(
			array(
				'buy_qty'      => '4',
				'get_qty'      => '8',
				'offer_title'  => '   ',
				'buy_scope'    => 'select',
				'buy_products' => array( '12', 'x', '12' ),
			)
		);

		$this->assertSame( 'no', $clean['enabled'] );
		$this->assertSame( 'no', $clean['repeat'] );
		$this->assertSame( 'no', $clean['show_notice'] );
		$this->assertSame( 4, $clean['buy_qty'] );
		$this->assertSame( 8, $clean['get_qty'] );
		$this->assertSame( array( 12 ), $clean['buy_products'] );
		$this->assertSame( BOGO_Select_Settings::defaults()['offer_title'], $clean['offer_title'] );
	}

	public function test_sanitize_flushes_the_runtime_cache() {
		$this->settings( array( 'buy_qty' => 5 ) );
		$this->assertSame( 5, BOGO_Select_Settings::get( 'buy_qty' ) );

		$clean = BOGO_Select_Settings::sanitize( array( 'buy_qty' => 9 ) );
		update_option( BOGO_Select_Settings::OPTION, $clean );

		$this->assertSame( 9, BOGO_Select_Settings::get( 'buy_qty' ) );
	}
}
