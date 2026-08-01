<?php
/**
 * What it costs to render a page of variable cards.
 *
 * Rendering asked for the same variation through four separate code paths — one
 * pass to decide the parent could be a card, another to enumerate its selector,
 * a third to price each option, and a fourth to quote the card. On a full page
 * that was four times the necessary product loads (`CODEX-REVIEW.md` M-02).
 *
 * Product loads stand in for data-store lookups. Object caching makes a repeat
 * load cheaper than the first, but not free, and it does nothing about the
 * repeated stock checks and price formatting hanging off each one.
 *
 * @package BOGO_Select
 */

namespace BOGO_Select\Tests;

use BOGO_Select_Engine;
use BOGO_Select_Frontend;
use BOGO_Test_Env;

/**
 * @covers BOGO_Select_Engine::offerable_variations
 * @covers BOGO_Select_Frontend::render_choices
 */
class VariableRenderCostTest extends TestCase {

	/**
	 * Build a catalogue of variable parents and offer all of them.
	 *
	 * @param int $parents    How many variable products.
	 * @param int $variations How many variations each.
	 * @return int[] The parent IDs.
	 */
	protected function catalogue( $parents, $variations ) {
		$ids  = array();
		$next = 1000;

		for ( $p = 0; $p < $parents; $p++ ) {
			$parent   = 100 + $p;
			$children = array();

			for ( $v = 0; $v < $variations; $v++ ) {
				$children[ $next ] = array( 'attributes' => array( 'size' => 's' . $v ) );
				++$next;
			}

			$this->variable_product( $parent, $children );
			$ids[] = $parent;
		}

		$this->product( 10, array( 'name' => 'Bought thing' ) );

		$this->settings(
			array(
				'enabled'      => 'yes',
				'buy_scope'    => 'all',
				'get_scope'    => 'select',
				'get_products' => $ids,
				'buy_qty'      => 1,
				'get_qty'      => 1,
			)
		);

		$this->add_paid_item( 'paid', 10, 1 );

		return $ids;
	}

	public function test_a_page_of_variable_cards_loads_each_product_about_once() {
		$parents    = 6;
		$variations = 5;

		$this->catalogue( $parents, $variations );

		BOGO_Test_Env::$product_loads = 0;

		$html = BOGO_Select_Frontend::render_choices(
			BOGO_Select_Engine::get_choice_page()['ids'],
			1
		);

		$this->assertSame( $parents, substr_count( $html, '<li class=' ) );
		$this->assertSame( $parents * $variations, substr_count( $html, '<option' ) );

		/*
		 * Expressed as a ratio rather than an exact count, so it holds a real
		 * bound without breaking on an incidental extra lookup. Each product is
		 * loaded a little over once on average — the parents twice, once to judge
		 * them a card and once to render them. Before this was addressed the same
		 * render cost four times the distinct products, so twice is a ceiling
		 * that catches the regression with room to spare.
		 */
		$distinct = $parents + ( $parents * $variations );
		$ceiling  = $distinct * 2;

		$this->assertLessThanOrEqual(
			$ceiling,
			BOGO_Test_Env::$product_loads,
			sprintf(
				'rendering %d cards over %d distinct products cost %d loads, above the %d ceiling',
				$parents,
				$distinct,
				BOGO_Test_Env::$product_loads,
				$ceiling
			)
		);
	}

	public function test_enumerating_a_parents_variations_twice_costs_one_pass() {
		$this->catalogue( 1, 8 );

		$parent = wc_get_product( 100 );

		BOGO_Test_Env::$product_loads = 0;
		BOGO_Select_Engine::offerable_variations( $parent );
		$first = BOGO_Test_Env::$product_loads;

		BOGO_Test_Env::$product_loads = 0;
		BOGO_Select_Engine::offerable_variations( $parent );
		$second = BOGO_Test_Env::$product_loads;

		$this->assertSame( 8, $first, 'the first pass loads every child' );
		$this->assertSame( 0, $second, 'the second is answered from the request memo' );
	}

	public function test_the_memo_does_not_outlive_a_settings_change() {
		// It is a per-request memo, not a cache with a lifetime. Anything that
		// clears the choice cache must clear it too, or a variation that stopped
		// being offerable would keep being offered for the rest of the request.
		$this->catalogue( 1, 3 );

		$parent = wc_get_product( 100 );

		$this->assertCount( 3, BOGO_Select_Engine::offerable_variations( $parent ) );

		wc_get_product( 1001 )->set( 'purchasable', false );
		BOGO_Select_Engine::flush_choice_cache();

		$this->assertCount( 2, BOGO_Select_Engine::offerable_variations( $parent ) );
	}
}
