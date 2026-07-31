<?php
/**
 * Regression cover for C-01: searching gifts by SKU must really search SKUs.
 *
 * The 1.1.0 implementation passed the term to wc_get_products() as `s`, which
 * WordPress resolves against the title, excerpt, and content — never the SKU.
 * The stubs here follow those semantics, so a return to `s` alone fails.
 *
 * @package BOGO_Select
 */

namespace BOGO_Select\Tests;

use BOGO_Select_Engine;
use BOGO_Test_Env;

/**
 * @covers BOGO_Select_Engine::search_product_ids
 * @covers BOGO_Select_Engine::get_choice_page
 */
class ChooserSearchTest extends TestCase {

	/**
	 * A product whose search term lives only in its SKU.
	 *
	 * @return int[] Product IDs: the SKU-only one first.
	 */
	protected function catalogue() {
		$this->product(
			201,
			array(
				'name' => 'Enamel Mug',
				'sku'  => 'WIDGET-9',
			)
		);

		$this->product(
			202,
			array(
				'name' => 'Widget Poster',
				'sku'  => 'MUG-1',
			)
		);

		$this->product(
			203,
			array(
				'name' => 'Tote Bag',
				'sku'  => 'BAG-3',
			)
		);

		return array( 201, 202, 203 );
	}

	public function test_all_products_search_finds_a_product_by_sku_alone() {
		$this->settings( array( 'enabled' => 'yes', 'get_scope' => 'all' ) );
		$this->catalogue();

		$results = BOGO_Select_Engine::get_choice_page( array( 'search' => 'WIDGET-9' ) );

		$this->assertSame( array( 201 ), $results['ids'], 'The SKU-only match must be found.' );
		$this->assertSame( 1, $results['total'] );
	}

	public function test_all_products_search_still_finds_a_product_by_name() {
		$this->settings( array( 'enabled' => 'yes', 'get_scope' => 'all' ) );
		$this->catalogue();

		$results = BOGO_Select_Engine::get_choice_page( array( 'search' => 'Enamel' ) );

		$this->assertSame( array( 201 ), $results['ids'] );
	}

	public function test_a_term_in_both_a_name_and_another_sku_finds_both() {
		$this->settings( array( 'enabled' => 'yes', 'get_scope' => 'all' ) );
		$this->catalogue();

		$results = BOGO_Select_Engine::get_choice_page( array( 'search' => 'WIDGET' ) );

		$this->assertSame( array( 201, 202 ), $results['ids'], 'Name and SKU matches are merged, in name order.' );
		$this->assertSame( 2, $results['total'] );
	}

	public function test_sku_search_works_without_the_product_data_store() {
		$this->settings( array( 'enabled' => 'yes', 'get_scope' => 'all' ) );
		$this->catalogue();

		// No data store: the engine falls back to product queries, which need
		// both a keyword query and an sku query to cover the same ground.
		BOGO_Test_Env::$data_store = false;

		$results = BOGO_Select_Engine::get_choice_page( array( 'search' => 'WIDGET-9' ) );

		$this->assertSame( array( 201 ), $results['ids'] );
	}

	public function test_search_totals_count_only_products_that_can_be_offered() {
		$this->settings( array( 'enabled' => 'yes', 'get_scope' => 'all' ) );
		$this->catalogue();

		wc_get_product( 202 )->set( 'purchasable', false );

		$results = BOGO_Select_Engine::get_choice_page( array( 'search' => 'WIDGET' ) );

		$this->assertSame( array( 201 ), $results['ids'] );
		$this->assertSame( 1, $results['total'], 'A search total must not promise unavailable gifts.' );
	}

	public function test_select_scope_search_is_constrained_to_the_configured_list() {
		$this->catalogue();

		$this->settings(
			array(
				'enabled'      => 'yes',
				'get_scope'    => 'select',
				'get_products' => array( 202 ),
			)
		);

		$results = BOGO_Select_Engine::get_choice_page( array( 'search' => 'WIDGET' ) );

		$this->assertSame( array( 202 ), $results['ids'], 'Product 201 matches, but is not on the gift list.' );

		$searches = BOGO_Test_Env::$store_searches;

		$this->assertNotEmpty( $searches );
		$this->assertSame(
			array( 202 ),
			$searches[0]['include'],
			'The database does the narrowing, rather than every configured product being loaded.'
		);
	}

	public function test_search_stops_at_the_filterable_limit() {
		$this->settings( array( 'enabled' => 'yes', 'get_scope' => 'all' ) );

		for ( $i = 1; $i <= 30; $i++ ) {
			$this->product(
				300 + $i,
				array(
					'name' => sprintf( 'Sample %02d', $i ),
					'sku'  => sprintf( 'SAMPLE-%02d', $i ),
				)
			);
		}

		add_filter(
			'bogo_select_search_limit',
			function () {
				return 5;
			}
		);

		$results = BOGO_Select_Engine::get_choice_page( array( 'search' => 'SAMPLE' ) );

		$this->assertSame( 5, $results['total'] );
		$this->assertSame( 5, (int) BOGO_Test_Env::$store_searches[0]['limit'] );
	}

	public function test_an_empty_search_term_matches_nothing_by_search() {
		$this->settings( array( 'enabled' => 'yes', 'get_scope' => 'all' ) );
		$this->catalogue();

		$this->assertSame( array(), BOGO_Select_Engine::search_product_ids( '   ', 10 ) );
	}
}
