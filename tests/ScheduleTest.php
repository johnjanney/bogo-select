<?php
/**
 * The offer's start and end dates.
 *
 * Answers `OPEN-QUESTIONS.md` Q-005. Both bounds are inclusive and both are
 * optional, and the schedule only ever narrows an offer — it cannot switch on
 * one whose Enable box is unticked.
 *
 * @package BOGO_Select
 */

namespace BOGO_Select\Tests;

use BOGO_Select_Engine;
use BOGO_Select_Settings;
use BOGO_Test_Env;

/**
 * @covers BOGO_Select_Engine::is_scheduled_now
 * @covers BOGO_Select_Settings::sanitize
 */
class ScheduleTest extends TestCase {

	/**
	 * A running offer, optionally scheduled.
	 *
	 * @param array $window start_date and end_date overrides.
	 */
	protected function offer( array $window = array() ) {
		$this->product( 20 );

		$this->settings(
			array_merge(
				array(
					'enabled'      => 'yes',
					'buy_scope'    => 'all',
					'get_scope'    => 'select',
					'get_products' => array( 20 ),
				),
				$window
			)
		);
	}

	/**
	 * Fix the site-local date the schedule sees.
	 *
	 * @param string $date `Y-m-d`.
	 */
	protected function today( $date ) {
		BOGO_Test_Env::$now = $date;
	}

	public function test_an_unscheduled_offer_runs() {
		$this->offer();

		$this->assertTrue( BOGO_Select_Engine::is_scheduled_now() );
		$this->assertTrue( BOGO_Select_Engine::is_active() );
	}

	public function test_an_offer_does_not_run_before_its_start_date() {
		$this->offer( array( 'start_date' => '2026-08-01' ) );
		$this->today( '2026-07-31' );

		$this->assertFalse( BOGO_Select_Engine::is_scheduled_now() );
		$this->assertFalse( BOGO_Select_Engine::is_active() );
	}

	public function test_an_offer_runs_on_its_start_date() {
		// Inclusive: an offer that "starts on the 1st" is live on the 1st.
		$this->offer( array( 'start_date' => '2026-08-01' ) );
		$this->today( '2026-08-01' );

		$this->assertTrue( BOGO_Select_Engine::is_active() );
	}

	public function test_an_offer_runs_on_its_end_date() {
		// Inclusive at this end too, which is the half people get wrong: an offer
		// running "until the 7th" is still live on the 7th, not dead at midnight
		// as it begins.
		$this->offer( array( 'end_date' => '2026-08-07' ) );
		$this->today( '2026-08-07' );

		$this->assertTrue( BOGO_Select_Engine::is_active() );
	}

	public function test_an_offer_does_not_run_after_its_end_date() {
		$this->offer( array( 'end_date' => '2026-08-07' ) );
		$this->today( '2026-08-08' );

		$this->assertFalse( BOGO_Select_Engine::is_active() );
	}

	public function test_a_window_runs_only_inside_itself() {
		$this->offer(
			array(
				'start_date' => '2026-08-01',
				'end_date'   => '2026-08-07',
			)
		);

		foreach ( array( '2026-07-31', '2026-08-08', '2026-09-01', '2025-08-03' ) as $outside ) {
			$this->today( $outside );
			$this->assertFalse( BOGO_Select_Engine::is_active(), $outside . ' should be outside the window' );
		}

		foreach ( array( '2026-08-01', '2026-08-04', '2026-08-07' ) as $inside ) {
			$this->today( $inside );
			$this->assertTrue( BOGO_Select_Engine::is_active(), $inside . ' should be inside the window' );
		}
	}

	public function test_one_sided_windows_are_open_at_the_other_end() {
		$this->offer( array( 'start_date' => '2026-08-01' ) );
		$this->today( '2099-01-01' );
		$this->assertTrue( BOGO_Select_Engine::is_active(), 'no end date means it keeps running' );

		$this->offer( array( 'end_date' => '2026-08-07' ) );
		$this->today( '1999-01-01' );
		$this->assertTrue( BOGO_Select_Engine::is_active(), 'no start date means it has always been running' );
	}

	public function test_the_schedule_cannot_switch_on_a_disabled_offer() {
		// The schedule narrows; it never widens. A store that unticks Enable has
		// switched the promotion off, whatever the dates say.
		$this->offer(
			array(
				'enabled'    => 'no',
				'start_date' => '2026-08-01',
				'end_date'   => '2026-08-07',
			)
		);
		$this->today( '2026-08-04' );

		$this->assertTrue( BOGO_Select_Engine::is_scheduled_now() );
		$this->assertFalse( BOGO_Select_Engine::is_active() );
	}

	public function test_dates_are_stored_normalised_and_nonsense_is_dropped() {
		$clean = BOGO_Select_Settings::sanitize(
			array(
				'start_date' => '2026-8-1',
				'end_date'   => '  2026-12-31  ',
			)
		);

		$this->assertSame( '2026-08-01', $clean['start_date'] );
		$this->assertSame( '2026-12-31', $clean['end_date'] );

		$rubbish = BOGO_Select_Settings::sanitize(
			array(
				'start_date' => 'next tuesday',
				'end_date'   => '2026-02-30',
			)
		);

		// A day that does not exist is refused rather than rolled into March: a
		// schedule quietly moving by a day is worse than one that ignores input.
		$this->assertSame( '', $rubbish['start_date'] );
		$this->assertSame( '', $rubbish['end_date'] );
	}

	public function test_an_option_row_saved_before_scheduling_is_unscheduled() {
		update_option(
			BOGO_Select_Settings::OPTION,
			array(
				'enabled'      => 'yes',
				'buy_scope'    => 'all',
				'get_scope'    => 'select',
				'get_products' => array( 20 ),
			)
		);
		BOGO_Select_Settings::flush();
		$this->product( 20 );

		$this->assertSame( '', BOGO_Select_Settings::get( 'start_date' ) );
		$this->assertSame( '', BOGO_Select_Settings::get( 'end_date' ) );
		$this->assertTrue( BOGO_Select_Engine::is_active() );
	}

	public function test_an_expired_offer_awards_nothing() {
		// The whole point: is_active() gates qualification, so an expired offer
		// stops rewarding without anyone having to untick a box.
		$this->offer( array( 'end_date' => '2026-08-07' ) );
		$this->add_paid_item( 'paid', 20, 5 );

		$this->today( '2026-08-07' );
		$this->assertTrue( BOGO_Select_Engine::qualifies() );

		$this->today( '2026-08-08' );
		$this->assertFalse( BOGO_Select_Engine::qualifies() );
		$this->assertSame( 0, BOGO_Select_Engine::reward_quantity_for_cart() );
	}
}
