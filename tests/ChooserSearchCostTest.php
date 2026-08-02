<?php
/**
 * What it costs to answer one gift search.
 *
 * A search in the All Products scope inspects up to `search_limit()` matches
 * (200 by default) and renders one page of 24. It asked about each candidate
 * twice: once to decide the product may be offered, and again for the name it
 * sorts by. Every candidate was therefore loaded twice before anything was
 * rendered — 400 product loads to answer a 200-match search
 * (`CODEX-REVIEW.md` M-03).
 *
 * Product loads stand in for data-store lookups, as in VariableRenderCostTest.
 * A repeat load is cheaper than the first under an object cache, but it is not
 * free, and the search endpoint is public to any customer whose cart qualifies.
 *
 * @package BOGO_Select
 */

namespace BOGO_Select\Tests;

use BOGO_Select_Engine;
use BOGO_Test_Env;

/**
 * @covers BOGO_Select_Engine::choice_product
 * @covers BOGO_Select_Engine::sort_by_name
 */
class ChooserSearchCostTest extends TestCase {

	/**
	 * Publish a catalogue of matching gifts and offer all of them.
	 *
	 * @param int $count How many products.
	 * @return int[] Their IDs.
	 */
	protected function catalogue( $count ) {
		$ids = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$id = 200 + $i;

			// Named back to front so sorting has real work to do.
			$this->product( $id, array( 'name' => sprintf( 'Gift %03d', $count - $i ) ) );
			$ids[] = $id;
		}

		$this->settings(
			array(
				'enabled'   => 'yes',
				'buy_scope' => 'all',
				'get_scope' => 'all',
			)
		);

		return $ids;
	}

	public function test_a_search_loads_each_candidate_about_once() {
		$candidates = 60;

		$this->catalogue( $candidates );

		BOGO_Test_Env::$product_loads = 0;

		$page = BOGO_Select_Engine::get_choice_page(
			array(
				'search'   => 'Gift',
				'per_page' => 24,
			)
		);

		$this->assertCount( 24, $page['ids'] );
		$this->assertSame( $candidates, $page['total'] );

		// One load each, plus a little slack for the page the caller goes on to
		// render. Twice the candidate count is the regression this holds shut.
		$this->assertLessThanOrEqual(
			$candidates + 10,
			BOGO_Test_Env::$product_loads,
			sprintf( '%d product loads for %d candidates', BOGO_Test_Env::$product_loads, $candidates )
		);
	}

	public function test_a_search_still_returns_its_page_in_name_order() {
		// The memo must not change the answer, only what it costs to get.
		$this->catalogue( 30 );

		$page  = BOGO_Select_Engine::get_choice_page(
			array(
				'search'   => 'Gift',
				'per_page' => 5,
			)
		);
		$names = array();

		foreach ( $page['ids'] as $id ) {
			$names[] = wc_get_product( $id )->get_name();
		}

		$sorted = $names;
		sort( $sorted, SORT_NATURAL | SORT_FLAG_CASE );

		$this->assertSame( $sorted, $names );
		$this->assertSame( 'Gift 001', $names[0] );
	}

	public function test_the_memo_does_not_survive_a_cache_flush() {
		// Product state changes between requests, and the memo is a per-request
		// convenience. The same hooks that drop the eligibility cache drop this.
		$this->catalogue( 5 );

		BOGO_Select_Engine::get_choice_page( array( 'search' => 'Gift' ) );

		BOGO_Select_Engine::flush_choice_cache();
		BOGO_Test_Env::$product_loads = 0;

		BOGO_Select_Engine::get_choice_page( array( 'search' => 'Gift' ) );

		$this->assertGreaterThan( 0, BOGO_Test_Env::$product_loads );
	}
}
