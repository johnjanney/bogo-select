<?php
/**
 * What the settings screen accepts, refuses, and says.
 *
 * The sanitizer is where the settings screen's judgement lives, and it had no
 * test at all. That is how a schedule the screen called impossible went on being
 * saved anyway: add_settings_error() draws a message after WordPress has already
 * written the option, so a test that only reads the message proves nothing about
 * what was stored (`CODEX-REVIEW.md` M-01). Every test here asserts both.
 *
 * @package BOGO_Select
 */

namespace BOGO_Select\Tests;

use BOGO_Select_Admin;
use BOGO_Select_Settings;
use BOGO_Test_Env;

/**
 * Reaches the sanitizer's own helpers, which are protected because nothing in
 * the plugin calls them from outside.
 */
class Admin_Probe extends BOGO_Select_Admin {

	/**
	 * The offer summary sentence.
	 *
	 * @param array $settings Settings.
	 * @return string
	 */
	public function summary_of( array $settings ) {
		return $this->summary( array_merge( BOGO_Select_Settings::defaults(), $settings ) );
	}
}

/**
 * @covers BOGO_Select_Admin::sanitize
 * @covers BOGO_Select_Admin::keep_last_valid_schedule
 * @covers BOGO_Select_Admin::settings_capability
 * @covers BOGO_Select_Admin::summary
 */
class AdminSettingsTest extends TestCase {

	/**
	 * The admin object under test.
	 *
	 * @var Admin_Probe
	 */
	protected $admin;

	protected function setUp(): void {
		parent::setUp();

		$this->admin = new Admin_Probe();
	}

	/**
	 * Submit a form payload over the current settings.
	 *
	 * @param array $raw Raw form input.
	 * @return array The settings that would be stored.
	 */
	protected function submit( array $raw ) {
		return $this->admin->sanitize( array_merge( BOGO_Select_Settings::defaults(), $raw ) );
	}

	/**
	 * Every settings message raised so far.
	 *
	 * @return string[]
	 */
	protected function messages() {
		return array_map(
			function ( $error ) {
				return $error['message'];
			},
			BOGO_Test_Env::$settings_errors
		);
	}

	/**
	 * Assert that some settings message carries this code.
	 *
	 * @param string $code Message code.
	 */
	protected function assertRaised( $code ) {
		$codes = array_map(
			function ( $error ) {
				return $error['code'];
			},
			BOGO_Test_Env::$settings_errors
		);

		$this->assertContains( $code, $codes, 'expected a settings message coded ' . $code );
	}

	/**
	 * Assert that no settings message carries this code.
	 *
	 * @param string $code Message code.
	 */
	protected function assertNotRaised( $code ) {
		$codes = array_map(
			function ( $error ) {
				return $error['code'];
			},
			BOGO_Test_Env::$settings_errors
		);

		$this->assertNotContains( $code, $codes, 'expected no settings message coded ' . $code );
	}

	// --- The schedule -------------------------------------------------------

	public function test_a_valid_window_is_saved() {
		$clean = $this->submit(
			array(
				'start_date' => '2026-08-01',
				'end_date'   => '2026-08-07',
			)
		);

		$this->assertSame( '2026-08-01', $clean['start_date'] );
		$this->assertSame( '2026-08-07', $clean['end_date'] );
		$this->assertNotRaised( 'bogo_select_invalid_date' );
		$this->assertNotRaised( 'bogo_select_backwards_window' );
	}

	public function test_a_reversed_window_is_not_saved() {
		$this->settings(
			array(
				'start_date' => '2026-08-01',
				'end_date'   => '2026-08-07',
			)
		);

		$clean = $this->submit(
			array(
				'enabled'    => 'yes',
				'start_date' => '2026-08-20',
				'end_date'   => '2026-08-10',
			)
		);

		$this->assertRaised( 'bogo_select_backwards_window' );
		$this->assertSame( '2026-08-01', $clean['start_date'] );
		$this->assertSame( '2026-08-07', $clean['end_date'] );
	}

	public function test_a_reversed_window_is_refused_even_while_the_offer_is_off() {
		// The old check only ran for an enabled offer, so a window that could
		// never run could be parked on a disabled one and switched on later.
		$clean = $this->submit(
			array(
				'enabled'    => 'no',
				'start_date' => '2026-08-20',
				'end_date'   => '2026-08-10',
			)
		);

		$this->assertRaised( 'bogo_select_backwards_window' );
		$this->assertSame( '', $clean['start_date'] );
		$this->assertSame( '', $clean['end_date'] );
	}

	public function test_the_message_for_a_reversed_window_names_the_schedule_that_survived() {
		$this->settings(
			array(
				'start_date' => '2026-08-01',
				'end_date'   => '2026-08-07',
			)
		);

		$this->submit(
			array(
				'start_date' => '2026-08-20',
				'end_date'   => '2026-08-10',
			)
		);

		$this->assertStringContainsString( '2026-08-01 to 2026-08-07', implode( ' ', $this->messages() ) );
	}

	public function test_a_mistyped_date_keeps_the_one_already_stored() {
		$this->settings( array( 'start_date' => '2026-08-01' ) );

		$clean = $this->submit( array( 'start_date' => 'next tuesday' ) );

		$this->assertRaised( 'bogo_select_invalid_date' );
		$this->assertSame( '2026-08-01', $clean['start_date'] );
	}

	public function test_a_mistyped_date_with_nothing_stored_stays_unbounded_and_says_so() {
		$clean = $this->submit( array( 'end_date' => '2026-02-30' ) );

		$this->assertRaised( 'bogo_select_invalid_date' );
		$this->assertSame( '', $clean['end_date'] );
	}

	public function test_clearing_a_date_removes_the_bound_without_complaint() {
		$this->settings( array( 'end_date' => '2026-08-07' ) );

		$clean = $this->submit( array( 'end_date' => '' ) );

		$this->assertSame( '', $clean['end_date'] );
		$this->assertNotRaised( 'bogo_select_invalid_date' );
	}

	public function test_a_date_with_something_after_it_is_not_read_as_a_date() {
		// intval() stops at the first character it cannot read, so each part of
		// "2026-08-01junk" converted cleanly and the whole string was accepted
		// as the first of August.
		$this->settings( array( 'start_date' => '2026-01-01' ) );

		$clean = $this->submit( array( 'start_date' => '2026-08-01junk' ) );

		$this->assertRaised( 'bogo_select_invalid_date' );
		$this->assertSame( '2026-01-01', $clean['start_date'] );
	}

	public function test_an_unpadded_date_is_still_accepted() {
		$clean = $this->submit( array( 'start_date' => '2026-8-1' ) );

		$this->assertSame( '2026-08-01', $clean['start_date'] );
		$this->assertNotRaised( 'bogo_select_invalid_date' );
	}

	public function test_a_refused_schedule_does_not_take_the_rest_of_the_form_with_it() {
		// The schedule is one setting among many, and a visit that fixed the
		// Buy quantity and mistyped a date should keep the fix.
		$this->settings( array( 'start_date' => '2026-08-01' ) );

		$clean = $this->submit(
			array(
				'start_date' => 'whenever',
				'buy_qty'    => 4,
				'get_qty'    => 2,
			)
		);

		$this->assertSame( '2026-08-01', $clean['start_date'] );
		$this->assertSame( 4, $clean['buy_qty'] );
		$this->assertSame( 2, $clean['get_qty'] );
	}

	public function test_an_offer_that_has_already_ended_is_still_only_warned_about() {
		// A past window is a real schedule that says something true, unlike a
		// reversed one. It saves, and the screen explains what the storefront
		// will look like.
		BOGO_Test_Env::$now = '2026-09-01';

		$clean = $this->submit(
			array(
				'enabled'      => 'yes',
				'end_date'     => '2026-08-07',
				'get_products' => array( 20 ),
			)
		);

		$this->assertRaised( 'bogo_select_window_past' );
		$this->assertSame( '2026-08-07', $clean['end_date'] );
	}

	// --- Who may save -------------------------------------------------------

	public function test_the_option_group_is_saveable_by_whoever_can_open_the_page() {
		// The menu and renderer ask for manage_woocommerce; options.php asks for
		// manage_options unless it is told otherwise, which left a Shop Manager
		// able to open the screen and unable to save it.
		$this->assertSame(
			'manage_woocommerce',
			apply_filters( 'option_page_capability_' . BOGO_Select_Admin::GROUP, 'manage_options' )
		);
	}

	// --- The summary sentence -----------------------------------------------

	public function test_the_summary_counts_a_listed_variation_and_its_parent_once() {
		$this->variable_product(
			10,
			array(
				101 => array( 'attributes' => array( 'size' => 'small' ) ),
				102 => array( 'attributes' => array( 'size' => 'large' ) ),
			)
		);

		$summary = $this->admin->summary_of(
			array(
				'enabled'      => 'yes',
				'buy_scope'    => 'select',
				'buy_products' => array( 10, 101 ),
				'get_scope'    => 'all',
			)
		);

		$this->assertStringContainsString( '1 selected product', $summary );
	}

	public function test_the_summary_counts_siblings_separately() {
		$this->variable_product(
			10,
			array(
				101 => array( 'attributes' => array( 'size' => 'small' ) ),
				102 => array( 'attributes' => array( 'size' => 'large' ) ),
			)
		);

		$summary = $this->admin->summary_of(
			array(
				'enabled'      => 'yes',
				'buy_scope'    => 'select',
				'buy_products' => array( 101, 102 ),
				'get_scope'    => 'all',
			)
		);

		$this->assertStringContainsString( '2 selected products', $summary );
	}

	public function test_the_summary_counts_unrelated_products_separately() {
		$this->product( 10 );
		$this->product( 11 );

		$summary = $this->admin->summary_of(
			array(
				'enabled'      => 'yes',
				'buy_scope'    => 'select',
				'buy_products' => array( 10, 11 ),
				'get_scope'    => 'all',
			)
		);

		$this->assertStringContainsString( '2 selected products', $summary );
	}

	// --- The gift list ------------------------------------------------------

	public function test_a_gift_that_could_never_be_given_is_removed_and_named() {
		$this->product(
			30,
			array(
				'type' => 'external',
				'name' => 'Affiliate Tee',
			)
		);
		$this->product( 20 );

		$clean = $this->submit(
			array(
				'enabled'      => 'yes',
				'get_scope'    => 'select',
				'get_products' => array( 20, 30 ),
			)
		);

		$this->assertSame( array( 20 ), $clean['get_products'] );
		$this->assertRaised( 'bogo_select_unofferable_gift' );
		$this->assertStringContainsString( 'Affiliate Tee', implode( ' ', $this->messages() ) );
	}
}
