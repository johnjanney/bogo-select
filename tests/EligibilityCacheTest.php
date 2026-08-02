<?php
/**
 * Cover for C-03: a long curated gift list must not be hydrated per request.
 *
 * @package BOGO_Select
 */

namespace BOGO_Select\Tests;

use BOGO_Select_Engine;
use BOGO_Test_Env;

/**
 * @covers BOGO_Select_Engine::eligibility_map
 * @covers BOGO_Select_Engine::flush_choice_cache
 */
class EligibilityCacheTest extends TestCase {

	/**
	 * Configure a curated gift list of the given size.
	 *
	 * @param int $count How many gifts.
	 * @return int[] Product IDs.
	 */
	protected function curated( $count ) {
		$ids = array();

		for ( $i = 1; $i <= $count; $i++ ) {
			$ids[] = 400 + $i;
			$this->product( 400 + $i, array( 'name' => sprintf( 'Curated %02d', $i ) ) );
		}

		$this->settings(
			array(
				'enabled'      => 'yes',
				'get_scope'    => 'select',
				'get_products' => $ids,
			)
		);

		return $ids;
	}

	public function test_eligibility_is_cached_for_the_configured_list() {
		$this->curated( 5 );

		BOGO_Select_Engine::get_choice_page();

		$this->assertCount( 1, BOGO_Test_Env::$transients, 'The eligible list is cached between requests.' );
	}

	public function test_the_cache_is_read_back_on_the_next_request() {
		$ids = $this->curated( 5 );

		BOGO_Select_Engine::get_choice_page();

		// Stand in for a fresh request: the per-request memo is gone, the
		// stored cache is not. Doctoring one entry proves the stored cache is
		// what answers, rather than every product being loaded again.
		$stored = BOGO_Test_Env::$transients;
		BOGO_Select_Engine::flush_choice_cache();
		BOGO_Test_Env::$transients = $stored;

		$key = array_keys( $stored )[0];

		BOGO_Test_Env::$transients[ $key ][ $ids[1] ] = false;

		$this->assertNotContains( $ids[1], BOGO_Select_Engine::get_choice_page()['ids'] );
		$this->assertCount( 4, BOGO_Select_Engine::get_choice_page()['ids'] );
	}

	public function test_saving_a_product_clears_the_cache() {
		$ids = $this->curated( 3 );

		$this->assertSame( $ids, BOGO_Select_Engine::get_choice_page()['ids'] );

		wc_get_product( $ids[1] )->set( 'purchasable', false );

		// Without the flush the answer is the cached one; with it, the gift
		// that can no longer be sold leaves the chooser.
		BOGO_Select_Engine::flush_choice_cache();

		$page = BOGO_Select_Engine::get_choice_page();

		$this->assertNotContains( $ids[1], $page['ids'] );
		$this->assertSame( 2, $page['total'] );
	}

	public function test_the_all_products_scope_is_not_cached() {
		$this->settings(
			array(
				'enabled'   => 'yes',
				'get_scope' => 'all',
			)
		);
		$this->product( 500 );

		BOGO_Select_Engine::get_choice_page();

		$this->assertSame( array(), BOGO_Test_Env::$transients, 'Catalogue pages are already bounded by the query.' );
	}

	public function test_a_filter_adding_an_unknown_id_is_still_checked_live() {
		$ids = $this->curated( 2 );

		BOGO_Select_Engine::get_choice_page();

		$this->product( 900, array( 'name' => 'Smuggled' ) );

		add_filter(
			'bogo_select_get_products',
			function ( $product_ids ) {
				return array_merge( $product_ids, array( 900 ) );
			}
		);

		// 900 is not in the cached map, so it is judged on the spot — and in
		// "Select Products" scope, eligibility means being on the list.
		$this->assertSame( $ids, BOGO_Select_Engine::get_choice_page()['ids'] );
	}
}
