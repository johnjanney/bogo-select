<?php
/**
 * How a variable product renders in the chooser.
 *
 * See PLAN-VARIABLE.md §5: one card per parent, a flat selector of its
 * variations, and a card that is unavailable only when none of them can be
 * given.
 *
 * @package BOGO_Select
 */

namespace BOGO_Select\Tests;

use BOGO_Select_Ajax;
use BOGO_Select_Engine;
use BOGO_Select_Frontend;

/**
 * @covers BOGO_Select_Frontend::render_choices
 */
class VariableChooserTest extends TestCase {

	/**
	 * A qualifying cart offering one variable product.
	 *
	 * @param array $variations Variation ID => overrides.
	 * @param array $extra      Settings overrides.
	 */
	protected function offering( array $variations, array $extra = array() ) {
		$this->settings(
			array_merge(
				array(
					'enabled'      => 'yes',
					'buy_scope'    => 'all',
					'get_scope'    => 'select',
					'get_products' => array( 100 ),
					'buy_qty'      => 1,
					'get_qty'      => 1,
				),
				$extra
			)
		);

		$this->product( 10, array( 'name' => 'Bought thing' ) );
		$this->variable_product(
			100,
			$variations,
			array(
				'name'  => 'Tee',
				'price' => 20.0,
			)
		);
		$this->add_paid_item( 'paid', 10, 1 );
	}

	/**
	 * Render the chooser grid as it stands.
	 *
	 * @return string
	 */
	protected function grid() {
		$qty = BOGO_Select_Engine::reward_quantity_for_cart();

		return BOGO_Select_Frontend::render_choices(
			BOGO_Select_Engine::get_choice_page()['ids'],
			$qty
		);
	}

	public function test_a_variable_product_renders_one_card_with_a_selector() {
		$this->offering(
			array(
				101 => array( 'name' => 'Tee - Small' ),
				102 => array( 'name' => 'Tee - Large' ),
			)
		);

		$html = $this->grid();

		$this->assertSame( 1, substr_count( $html, '<li class=' ), 'a variable product must be one card' );
		$this->assertSame( 1, substr_count( $html, 'data-bogo-variation' ) );
		$this->assertStringContainsString( 'Tee - Small', $html );
		$this->assertStringContainsString( 'Tee - Large', $html );

		// The card names the parent; the selector supplies the variation.
		$this->assertStringContainsString( 'data-product-id="100"', $html );
	}

	public function test_a_simple_product_renders_no_selector() {
		$this->settings(
			array(
				'enabled'      => 'yes',
				'buy_scope'    => 'all',
				'get_scope'    => 'select',
				'get_products' => array( 20 ),
				'buy_qty'      => 1,
				'get_qty'      => 1,
			)
		);
		$this->product( 10 );
		$this->product( 20 );
		$this->add_paid_item( 'paid', 10, 1 );

		$html = $this->grid();

		$this->assertStringNotContainsString( 'data-bogo-variation', $html );
		$this->assertStringContainsString( 'data-variation-id="0"', $html );
	}

	public function test_a_pinned_variation_renders_as_itself_with_its_parent() {
		$this->offering( array( 101 => array( 'name' => 'Tee - Small' ) ), array( 'get_products' => array( 101 ) ) );

		$html = $this->grid();

		$this->assertStringNotContainsString( 'data-bogo-variation', $html, 'a pinned variation offers no choice' );
		$this->assertStringContainsString( 'data-product-id="100"', $html );
		$this->assertStringContainsString( 'data-variation-id="101"', $html );
	}

	public function test_an_unavailable_variation_is_offered_but_disabled() {
		$this->offering(
			array(
				101 => array( 'name' => 'Tee - Small' ),
				102 => array(
					'name'     => 'Tee - Large',
					'in_stock' => false,
				),
			)
		);

		$html = $this->grid();

		// The card stands, because one option can still be given.
		$this->assertStringNotContainsString( 'is-unavailable', $html );
		$this->assertStringContainsString( 'bogo-select__choose', $html );

		// The option that cannot is offered with its reason, and not selectable.
		$this->assertStringContainsString( 'Out of stock', $html );
		$this->assertSame( 1, substr_count( $html, 'disabled="disabled"' ) );
	}

	public function test_a_card_is_unavailable_only_when_no_variation_can_be_given() {
		$this->offering(
			array(
				101 => array( 'in_stock' => false ),
				102 => array( 'in_stock' => false ),
			)
		);

		$html = $this->grid();

		$this->assertStringContainsString( 'is-unavailable', $html );
		$this->assertStringNotContainsString( 'bogo-select__choose', $html );
	}

	public function test_the_card_quotes_a_variation_rather_than_the_parents_range() {
		// The parent is 20.00, which WooCommerce reports as the low end of a
		// range. The first available variation is 35.00 and is what is quoted.
		$this->offering(
			array(
				101 => array(
					'price'    => 20.0,
					'in_stock' => false,
				),
				102 => array( 'price' => 35.0 ),
			)
		);

		$html = $this->grid();

		$this->assertStringContainsString( '35.00', $html );
	}

	public function test_every_option_carries_its_own_price_for_the_browser() {
		$this->offering(
			array(
				101 => array( 'price' => 20.0 ),
				102 => array( 'price' => 35.0 ),
			)
		);

		$html = $this->grid();

		// The selector moves the quoted figure without a round trip, so each
		// option has to carry its own.
		$this->assertSame( 2, substr_count( $html, 'data-bogo-price-for=' ) );
		$this->assertStringContainsString( '20.00', $html );
		$this->assertStringContainsString( '35.00', $html );

		// Only the figure being quoted is on show; the script reveals another by
		// unhiding it rather than by writing markup back into the card.
		$this->assertSame( 1, substr_count( $html, 'hidden>' ) );
	}

	public function test_no_option_carries_price_markup_for_the_script_to_reinstate() {
		$this->offering(
			array(
				101 => array( 'price' => 20.0 ),
				102 => array( 'price' => 35.0 ),
			)
		);

		$html = $this->grid();

		// Price markup handed over on an attribute would be parsed back into HTML
		// by the browser, past the filter the server ran it through
		// (CodeQL js/xss-through-dom).
		$this->assertStringNotContainsString( 'data-price=', $html );
	}

	public function test_the_chosen_variation_is_preselected_and_can_be_changed() {
		$this->offering(
			array(
				101 => array( 'name' => 'Tee - Small' ),
				102 => array( 'name' => 'Tee - Large' ),
			)
		);

		BOGO_Select_Ajax::select_gift( $this->cart(), 100, 102 );

		$html = $this->grid();

		$this->assertStringContainsString( 'is-selected', $html );

		// Whitespace-tolerant: the attributes are rendered across several lines.
		$this->assertMatchesRegularExpression( '/value="102"[^>]*selected="selected"/s', $html );
		$this->assertDoesNotMatchRegularExpression( '/value="101"[^>]*selected="selected"/s', $html );

		// A selected variable card must still offer a way to switch siblings,
		// which a card showing only "Selected" and "Remove" would not.
		$this->assertStringContainsString( 'Change option', $html );
		$this->assertStringContainsString( 'bogo-select__choose', $html );
	}

	public function test_a_selected_simple_product_offers_no_change_button() {
		$this->settings(
			array(
				'enabled'      => 'yes',
				'buy_scope'    => 'all',
				'get_scope'    => 'select',
				'get_products' => array( 20 ),
				'buy_qty'      => 1,
				'get_qty'      => 1,
			)
		);
		$this->product( 10 );
		$this->product( 20 );
		$this->add_paid_item( 'paid', 10, 1 );

		BOGO_Select_Ajax::select_gift( $this->cart(), 20 );

		$html = $this->grid();

		$this->assertStringContainsString( 'is-selected', $html );
		$this->assertStringNotContainsString( 'Change option', $html );
	}

	/**
	 * Two variations of one parent, each pinned as its own card.
	 *
	 * @param int $chosen Variation the customer picks.
	 * @param int $other  Its sibling.
	 */
	protected function two_pinned_siblings( $chosen, $other ) {
		$this->offering(
			array(
				101 => array(
					'name'  => 'Tee - Small',
					'price' => 20.0,
				),
				102 => array(
					'name'  => 'Tee - Large',
					'price' => 25.0,
				),
			),
			array( 'get_products' => array( 101, 102 ) )
		);

		BOGO_Select_Ajax::select_gift( $this->cart(), 100, $chosen );

		return $this->grid();
	}

	public function test_only_the_chosen_one_of_two_pinned_siblings_is_selected() {
		// Both cards share a parent, so a selected-state comparison that looks
		// only at the parent ID marks both — and the customer is then told they
		// have chosen two things (`CODEX-REVIEW.md` M-01).
		$html = $this->two_pinned_siblings( 101, 102 );

		$this->assertSame( 2, substr_count( $html, '<li class=' ) );
		$this->assertSame( 1, substr_count( $html, 'is-selected' ), 'exactly one card may be selected' );
	}

	public function test_the_unchosen_pinned_sibling_can_still_be_chosen() {
		// The impact of M-01 was not only a wrong label: a selected pinned card
		// shows "Selected" and "Remove gift" and nothing else, so marking both
		// left no control anywhere on the page for switching between them.
		$html = $this->two_pinned_siblings( 101, 102 );

		$this->assertStringContainsString( 'Choose this instead', $html );
		$this->assertSame( 1, substr_count( $html, 'bogo-select__choose' ) );
		$this->assertStringContainsString( 'data-variation-id="102"', $html );
	}

	public function test_a_pinned_child_owns_the_selection_over_its_parent_card() {
		// The Get list may hold a parent and one of its own variations. Both
		// cards can claim the same reward; the more specific one wins, so the
		// count stays at one either way.
		$this->offering(
			array(
				101 => array( 'name' => 'Tee - Small' ),
				102 => array( 'name' => 'Tee - Large' ),
			),
			array( 'get_products' => array( 100, 101 ) )
		);

		BOGO_Select_Ajax::select_gift( $this->cart(), 100, 101 );

		$html = $this->grid();

		$this->assertSame( 2, substr_count( $html, '<li class=' ) );
		$this->assertSame( 1, substr_count( $html, 'is-selected' ) );

		// The parent card is the unselected one, so it still offers its dropdown.
		$this->assertSame( 1, substr_count( $html, 'data-bogo-variation' ) );
		$this->assertStringContainsString( 'Choose this instead', $html );
	}

	public function test_a_variable_parent_is_selected_when_its_variation_is_not_pinned() {
		// Nothing else in the list claims variation 102, so the parent card owns
		// the selection and keeps its Change option control.
		$this->offering(
			array(
				101 => array( 'name' => 'Tee - Small' ),
				102 => array( 'name' => 'Tee - Large' ),
			),
			array( 'get_products' => array( 100 ) )
		);

		BOGO_Select_Ajax::select_gift( $this->cart(), 100, 102 );

		$html = $this->grid();

		$this->assertSame( 1, substr_count( $html, 'is-selected' ) );
		$this->assertStringContainsString( 'Change option', $html );
	}

	public function test_variations_leaving_an_attribute_open_are_not_offered() {
		$this->offering(
			array(
				101 => array( 'attributes' => array( 'size' => 'small' ) ),
				102 => array( 'attributes' => array( 'size' => '' ) ),
			)
		);

		$html = $this->grid();

		$this->assertSame( 1, substr_count( $html, '<option' ) );
	}

	public function test_all_products_scope_lists_variable_parents_as_one_card_each() {
		$this->settings(
			array(
				'enabled'   => 'yes',
				'buy_scope' => 'all',
				'get_scope' => 'all',
				'buy_qty'   => 1,
				'get_qty'   => 1,
			)
		);
		$this->product( 10, array( 'name' => 'Bought thing' ) );
		$this->product( 20, array( 'name' => 'Mug' ) );
		$this->variable_product(
			100,
			array(
				101 => array( 'name' => 'Tee - Small' ),
				102 => array( 'name' => 'Tee - Large' ),
			),
			array( 'name' => 'Tee' )
		);
		$this->add_paid_item( 'paid', 10, 1 );

		$ids = BOGO_Select_Engine::get_choice_page()['ids'];

		// The parent is listed; its variations are not cards of their own.
		$this->assertContains( 100, $ids );
		$this->assertContains( 20, $ids );
		$this->assertNotContains( 101, $ids );
		$this->assertNotContains( 102, $ids );

		$html = $this->grid();

		// Three cards: the bought product is itself a valid reward in this scope,
		// alongside the mug and the one card standing for the whole tee.
		$this->assertSame( 3, substr_count( $html, '<li class=' ) );
		$this->assertSame( 1, substr_count( $html, 'data-bogo-variation' ) );
	}

	public function test_a_pinned_variation_can_be_found_by_searching_for_it() {
		// The search is constrained to the curated list, which is the only way a
		// variation reaches the chooser at all, so it has to look inside it.
		$this->offering( array( 101 => array( 'name' => 'Tee - Small' ) ), array( 'get_products' => array( 101 ) ) );

		$page = BOGO_Select_Engine::get_choice_page( array( 'search' => 'Small' ) );

		$this->assertContains( 101, $page['ids'] );
	}

	public function test_the_discount_applies_to_each_option() {
		$this->offering(
			array(
				101 => array( 'price' => 20.0 ),
			),
			array(
				'get_discount_type'  => 'percent',
				'get_discount_value' => 50,
			)
		);

		$html = $this->grid();

		$this->assertStringContainsString( '10.00', $html );
		$this->assertStringNotContainsString( 'Free', $html );
	}
}
