<?php
/**
 * Regression cover for F-02: every eligible gift must be reachable.
 *
 * @package BOGO_Select
 */

namespace BOGO_Select\Tests;

use BOGO_Select_Engine;

/**
 * @covers BOGO_Select_Engine::get_choice_page
 */
class ChooserPagingTest extends TestCase {

	/**
	 * Build a catalogue of simple products named "Gift 01" … "Gift NN".
	 *
	 * @param int $count How many to create.
	 * @return int[] Their IDs, in name order.
	 */
	protected function catalogue( $count ) {
		$ids = array();

		for ( $i = 1; $i <= $count; $i++ ) {
			$id    = 100 + $i;
			$ids[] = $id;

			$this->product(
				$id,
				array(
					'name' => sprintf( 'Gift %02d', $i ),
					'sku'  => sprintf( 'SKU-%03d', $i ),
				)
			);
		}

		return $ids;
	}

	/**
	 * Walk every page of the chooser and collect the IDs it offers.
	 *
	 * @param string $search Optional search term.
	 * @return int[]
	 */
	protected function walk_all_pages( $search = '' ) {
		$first = BOGO_Select_Engine::get_choice_page( array( 'search' => $search ) );
		$seen  = $first['ids'];

		for ( $page = 2; $page <= $first['pages']; $page++ ) {
			$next = BOGO_Select_Engine::get_choice_page(
				array(
					'search' => $search,
					'page'   => $page,
				)
			);

			$seen = array_merge( $seen, $next['ids'] );
		}

		return $seen;
	}

	public function test_a_sixty_product_catalogue_is_fully_reachable() {
		$this->settings(
			array(
				'enabled'   => 'yes',
				'get_scope' => 'all',
			)
		);
		$ids = $this->catalogue( 60 );

		$first = BOGO_Select_Engine::get_choice_page();

		$this->assertSame( 60, $first['total'] );
		$this->assertSame( 24, count( $first['ids'] ), 'Default page size is 24.' );
		$this->assertSame( 3, $first['pages'] );

		$seen = $this->walk_all_pages();

		$this->assertSame( 60, count( $seen ) );
		$this->assertContains( $ids[0], $seen, 'The first product must be reachable.' );
		$this->assertContains( $ids[49], $seen, 'The fiftieth product must be reachable.' );
		$this->assertContains( $ids[59], $seen, 'The last product must be reachable.' );
	}

	public function test_products_beyond_the_old_fifty_item_cap_are_reachable() {
		$this->settings(
			array(
				'enabled'   => 'yes',
				'get_scope' => 'all',
			)
		);
		$ids = $this->catalogue( 60 );

		// "Gift 55" sat past the pre-1.1.0 hard limit of 50.
		$results = BOGO_Select_Engine::get_choice_page( array( 'search' => 'Gift 55' ) );

		$this->assertSame( array( $ids[54] ), $results['ids'] );
	}

	public function test_page_size_is_filterable() {
		$this->settings(
			array(
				'enabled'   => 'yes',
				'get_scope' => 'all',
			)
		);
		$this->catalogue( 60 );

		add_filter(
			'bogo_select_all_products_limit',
			function () {
				return 10;
			}
		);

		$first = BOGO_Select_Engine::get_choice_page();

		$this->assertSame( 10, count( $first['ids'] ) );
		$this->assertSame( 6, $first['pages'] );
		$this->assertSame( 60, count( $this->walk_all_pages() ) );
	}

	public function test_pages_past_the_end_clamp_to_the_last_page() {
		$this->settings(
			array(
				'enabled'   => 'yes',
				'get_scope' => 'all',
			)
		);
		$this->catalogue( 30 );

		$results = BOGO_Select_Engine::get_choice_page( array( 'page' => 99 ) );

		$this->assertSame( 2, $results['pages'] );
		$this->assertSame( 2, $results['page'] );
	}

	public function test_search_matches_name_or_sku() {
		$this->settings(
			array(
				'enabled'   => 'yes',
				'get_scope' => 'all',
			)
		);
		$ids = $this->catalogue( 60 );

		$by_sku = BOGO_Select_Engine::get_choice_page( array( 'search' => 'SKU-007' ) );

		$this->assertSame( array( $ids[6] ), $by_sku['ids'] );
	}

	public function test_search_with_no_matches_returns_an_empty_page() {
		$this->settings(
			array(
				'enabled'   => 'yes',
				'get_scope' => 'all',
			)
		);
		$this->catalogue( 10 );

		$results = BOGO_Select_Engine::get_choice_page( array( 'search' => 'nothing here' ) );

		$this->assertSame( array(), $results['ids'] );
		$this->assertSame( 0, $results['total'] );
		$this->assertSame( 1, $results['pages'] );
	}

	public function test_ineligible_products_are_dropped_from_a_page() {
		$this->settings(
			array(
				'enabled'   => 'yes',
				'get_scope' => 'all',
			)
		);
		$ids = $this->catalogue( 5 );

		wc_get_product( $ids[2] )->set( 'purchasable', false );

		$results = BOGO_Select_Engine::get_choice_page();

		$this->assertNotContains( $ids[2], $results['ids'] );
		$this->assertSame( 4, count( $results['ids'] ) );
	}

	public function test_select_scope_is_paged_too() {
		$ids = $this->catalogue( 30 );

		$this->settings(
			array(
				'enabled'      => 'yes',
				'get_scope'    => 'select',
				'get_products' => $ids,
			)
		);

		add_filter(
			'bogo_select_all_products_limit',
			function () {
				return 10;
			}
		);

		$first = BOGO_Select_Engine::get_choice_page();

		$this->assertSame( 10, count( $first['ids'] ) );
		$this->assertSame( 3, $first['pages'] );
		$this->assertSame( 30, $first['total'] );
		$this->assertSame( 30, count( $this->walk_all_pages() ) );
	}

	public function test_select_scope_is_searchable() {
		$ids = $this->catalogue( 30 );

		$this->settings(
			array(
				'enabled'      => 'yes',
				'get_scope'    => 'select',
				'get_products' => $ids,
			)
		);

		$results = BOGO_Select_Engine::get_choice_page( array( 'search' => 'SKU-021' ) );

		$this->assertSame( array( $ids[20] ), $results['ids'] );
	}

	public function test_get_choice_ids_returns_the_first_page() {
		$this->settings(
			array(
				'enabled'   => 'yes',
				'get_scope' => 'all',
			)
		);
		$this->catalogue( 60 );

		$this->assertSame( BOGO_Select_Engine::get_choice_page()['ids'], BOGO_Select_Engine::get_choice_ids() );
	}

	public function test_the_choice_filter_can_remove_products() {
		$ids = $this->catalogue( 5 );

		$this->settings(
			array(
				'enabled'      => 'yes',
				'get_scope'    => 'select',
				'get_products' => $ids,
			)
		);

		add_filter(
			'bogo_select_get_products',
			function ( $product_ids ) use ( $ids ) {
				return array_values( array_diff( $product_ids, array( $ids[1] ) ) );
			}
		);

		$this->assertNotContains( $ids[1], BOGO_Select_Engine::get_choice_page()['ids'] );
		$this->assertSame( 4, count( BOGO_Select_Engine::get_choice_page()['ids'] ) );
	}

	/**
	 * The filter runs before the eligibility gate, and that gate is the same one
	 * the choose endpoint applies. In "Select Products" scope eligibility means
	 * membership of the configured list, so a filter cannot smuggle in a product
	 * the server would then refuse to award.
	 */
	public function test_the_choice_filter_cannot_add_products_outside_the_configured_list() {
		$ids = $this->catalogue( 5 );

		$this->settings(
			array(
				'enabled'      => 'yes',
				'get_scope'    => 'select',
				'get_products' => array( $ids[0] ),
			)
		);

		add_filter(
			'bogo_select_get_products',
			function ( $product_ids ) use ( $ids ) {
				return array_merge( $product_ids, array( $ids[1] ) );
			}
		);

		$this->assertSame( array( $ids[0] ), BOGO_Select_Engine::get_choice_page()['ids'] );
		$this->assertFalse( BOGO_Select_Engine::is_get_eligible( $ids[1] ) );
	}

	/**
	 * C-04: the older filter says nothing about which page it is looking at,
	 * so a callback that wants to act on one page — or leave searches alone —
	 * had no way to tell. The page-aware filter carries that context.
	 */
	public function test_the_page_aware_filter_receives_its_context() {
		$this->settings(
			array(
				'enabled'   => 'yes',
				'get_scope' => 'all',
			)
		);
		$this->catalogue( 60 );

		$seen = array();

		add_filter(
			'bogo_select_choice_ids',
			function ( $product_ids, $context ) use ( &$seen ) {
				$seen[] = $context;

				return $product_ids;
			},
			10,
			2
		);

		BOGO_Select_Engine::get_choice_page( array( 'page' => 2 ) );

		$this->assertCount( 1, $seen );
		$this->assertSame( 'all', $seen[0]['scope'] );
		$this->assertSame( 2, $seen[0]['page'] );
		$this->assertSame( 24, $seen[0]['per_page'] );
		$this->assertSame( '', $seen[0]['search'] );
	}

	public function test_the_page_aware_filter_can_drop_products_from_one_page_only() {
		$this->settings(
			array(
				'enabled'   => 'yes',
				'get_scope' => 'all',
			)
		);
		$ids = $this->catalogue( 60 );

		add_filter(
			'bogo_select_choice_ids',
			function ( $product_ids, $context ) use ( $ids ) {
				return 1 === $context['page']
					? array_values( array_diff( $product_ids, array( $ids[0] ) ) )
					: $product_ids;
			},
			10,
			2
		);

		$this->assertNotContains( $ids[0], BOGO_Select_Engine::get_choice_page()['ids'] );
		$this->assertContains( $ids[24], BOGO_Select_Engine::get_choice_page( array( 'page' => 2 ) )['ids'] );
	}

	public function test_the_choice_filter_can_add_products_in_all_scope() {
		$ids = $this->catalogue( 5 );

		$this->settings(
			array(
				'enabled'   => 'yes',
				'get_scope' => 'all',
			)
		);

		add_filter(
			'bogo_select_get_products',
			function ( $product_ids ) use ( $ids ) {
				return array_values( array_diff( $product_ids, array( $ids[0] ) ) );
			}
		);

		$this->assertNotContains( $ids[0], BOGO_Select_Engine::get_choice_page()['ids'] );
	}
}
