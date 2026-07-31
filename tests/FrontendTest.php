<?php
/**
 * Where the chooser is printed, and what the script is told about it.
 *
 * @package BOGO_Select
 */

namespace BOGO_Select\Tests;

use BOGO_Select_Frontend;
use BOGO_Test_Env;

/**
 * @covers BOGO_Select_Frontend
 */
class FrontendTest extends TestCase {

	/**
	 * A cart that has earned one gift.
	 */
	protected function qualifying_cart() {
		$this->settings(
			array(
				'enabled'     => 'yes',
				'buy_scope'   => 'all',
				'get_scope'   => 'all',
				'buy_qty'     => 1,
				'get_qty'     => 1,
				'offer_title' => 'Pick a freebie',
			)
		);

		$this->product( 10, array( 'name' => 'Bought thing' ) );
		$this->product( 20, array( 'name' => 'Gift A' ) );

		$this->add_paid_item( 'paid', 10, 1 );
	}

	/**
	 * Capture what a chooser hook prints.
	 *
	 * @param string $method Frontend method to call.
	 * @return string
	 */
	protected function printed( $method ) {
		$frontend = new BOGO_Select_Frontend();

		ob_start();
		$frontend->$method();

		return (string) ob_get_clean();
	}

	public function test_the_cart_hook_prints_a_classic_slot() {
		$this->qualifying_cart();

		$this->assertStringContainsString( 'data-bogo-mode="classic"', $this->printed( 'render_chooser' ) );
	}

	public function test_the_checkout_hook_prints_a_checkout_slot() {
		$this->qualifying_cart();

		$output = $this->printed( 'render_checkout_chooser' );

		// The checkout mode is what keeps a half-filled form from being thrown
		// away when the customer changes their gift.
		$this->assertStringContainsString( 'data-bogo-mode="checkout"', $output );
		$this->assertStringContainsString( 'Pick a freebie', $output );
	}

	public function test_only_one_chooser_is_printed_per_page() {
		$this->qualifying_cart();

		$this->assertNotSame( '', $this->printed( 'render_chooser' ) );
		$this->assertSame( '', $this->printed( 'render_checkout_chooser' ) );
	}

	public function test_nothing_is_printed_while_the_offer_is_off() {
		$this->qualifying_cart();
		$this->settings( array( 'enabled' => 'no' ) );

		$this->assertSame( '', $this->printed( 'render_chooser' ) );
	}

	public function test_the_chooser_shows_the_gift_and_its_struck_through_price() {
		$this->qualifying_cart();

		$output = $this->printed( 'render_chooser' );

		$this->assertStringContainsString( 'Gift A', $output );
		$this->assertStringContainsString( 'data-product-id="20"', $output );
		$this->assertStringContainsString( '<del', $output );
	}

	public function test_the_chosen_gift_is_marked_as_selected() {
		$this->qualifying_cart();
		$this->add_gift_item( 'gift', 20, 1 );

		$output = $this->printed( 'render_chooser' );

		$this->assertStringContainsString( 'is-selected', $output );
		$this->assertStringContainsString( 'data-bogo-remove="1"', $output );
	}

	public function test_an_unavailable_gift_cannot_be_chosen() {
		$this->qualifying_cart();

		wc_get_product( 20 )->set( 'in_stock', false );

		$output = $this->printed( 'render_chooser' );

		$this->assertStringContainsString( 'is-unavailable', $output );
		$this->assertStringContainsString( 'data-permanently-disabled="1"', $output );
		$this->assertStringNotContainsString( 'data-product-id="20"', $output );
	}

	public function test_the_script_is_told_how_to_reach_the_endpoints() {
		BOGO_Select_Frontend::enqueue_assets();

		$data = BOGO_Test_Env::$localized['bogoSelect'];

		$this->assertSame( 'https://example.test/wp-admin/admin-ajax.php', $data['ajaxUrl'] );
		$this->assertSame( 'nonce-bogo-select', $data['nonce'] );
		$this->assertSame( 'bogo-select', $data['namespace'], 'The Store API extension namespace must match the server.' );
	}

	public function test_assets_are_enqueued_once() {
		BOGO_Select_Frontend::enqueue_assets();
		BOGO_Test_Env::$localized = array();
		BOGO_Select_Frontend::enqueue_assets();

		$this->assertSame( array(), BOGO_Test_Env::$localized );
	}
}
