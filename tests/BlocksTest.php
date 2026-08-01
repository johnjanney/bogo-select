<?php
/**
 * Cart and Checkout Blocks support.
 *
 * @package BOGO_Select
 */

namespace BOGO_Select\Tests;

use BOGO_Select_Blocks;
use BOGO_Select_Engine;
use BOGO_Select_Frontend;
use Exception;
use WP_REST_Request;

/**
 * @covers BOGO_Select_Blocks
 */
class BlocksTest extends TestCase {

	/**
	 * The class under test.
	 *
	 * @var BOGO_Select_Blocks
	 */
	protected $blocks;

	/**
	 * Build the integration without registering its hooks twice per test.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->blocks = new BOGO_Select_Blocks();
	}

	/**
	 * A cart that has earned one gift, with two products to choose from.
	 */
	protected function qualifying_cart() {
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
		$this->product( 20, array( 'name' => 'Gift A' ) );
		$this->product( 30, array( 'name' => 'Gift B' ) );

		$this->add_paid_item( 'paid', 10, 1 );
	}

	/**
	 * Pretend this request is being served to the Store API.
	 */
	protected function as_store_api_request() {
		$_SERVER['REQUEST_URI'] = '/wp-json/wc/store/v1/cart';
	}

	/**
	 * Run something the way WooCommerce preloads a block cart into the page:
	 * the Store API response is built, but the request is for /cart/.
	 *
	 * @param callable $callback What to run inside the hydration scope.
	 * @return mixed
	 */
	protected function during_hydration( callable $callback ) {
		$_SERVER['REQUEST_URI'] = '/cart/';

		apply_filters( 'woocommerce_hydration_dispatch_request', null, null, '/wc/store/v1/cart', array() );

		$result = $callback();

		apply_filters( 'woocommerce_hydration_request_after_callbacks', null, array(), null );

		return $result;
	}

	/**
	 * Run something inside a dispatched REST request for the given route.
	 *
	 * @param string   $route    REST route.
	 * @param callable $callback What to run inside the request.
	 * @return mixed
	 */
	protected function during_rest_request( $route, callable $callback ) {
		$_SERVER['REQUEST_URI'] = '/index.php';

		$request = new WP_REST_Request( $route );

		apply_filters( 'rest_request_before_callbacks', null, array(), $request );

		$result = $callback();

		apply_filters( 'rest_request_after_callbacks', null, array(), $request );

		return $result;
	}

	/**
	 * The text the Cart and Checkout blocks put on screen for one item-data
	 * row: label from `key` or `name`, text from `display` or `value`.
	 *
	 * @param array $row One item-data entry.
	 * @return string
	 */
	protected function as_blocks_would_render( array $row ) {
		$label = '' !== ( isset( $row['key'] ) ? $row['key'] : '' ) ? $row['key'] : ( isset( $row['name'] ) ? $row['name'] : '' );
		$text  = '' !== ( isset( $row['display'] ) ? $row['display'] : '' ) ? $row['display'] : ( isset( $row['value'] ) ? $row['value'] : '' );

		return '' === $label ? $text : $label . ': ' . $text;
	}

	// --- Rendering ----------------------------------------------------------

	public function test_the_chooser_is_injected_ahead_of_the_cart_block() {
		$this->qualifying_cart();

		$output = $this->blocks->inject_chooser( '<div class="wp-block-woocommerce-cart"></div>', array( 'blockName' => 'woocommerce/cart' ) );

		$this->assertStringContainsString( 'data-bogo-slot="1"', $output );
		$this->assertStringContainsString( 'data-bogo-mode="block"', $output );
		$this->assertStringContainsString( 'id="bogo-select"', $output );
		$this->assertStringStartsWith( '<div class="bogo-select-slot"', $output, 'The chooser goes above the block, as it does above the cart table.' );
		$this->assertTrue( \wp_script_is( 'bogo-select', 'enqueued' ), 'A block cart is rendered too late for wp_enqueue_scripts to have decided this.' );
	}

	public function test_the_chooser_is_injected_ahead_of_the_checkout_block() {
		$this->qualifying_cart();

		$output = $this->blocks->inject_chooser( 'CHECKOUT', array( 'blockName' => 'woocommerce/checkout' ) );

		$this->assertStringContainsString( 'data-bogo-slot="1"', $output );
		$this->assertStringEndsWith( 'CHECKOUT', $output );
	}

	public function test_other_blocks_are_left_alone() {
		$this->qualifying_cart();

		$this->assertSame( 'PARAGRAPH', $this->blocks->inject_chooser( 'PARAGRAPH', array( 'blockName' => 'core/paragraph' ) ) );
		$this->assertSame( 'NAMELESS', $this->blocks->inject_chooser( 'NAMELESS', array() ) );
	}

	public function test_nothing_is_injected_when_the_offer_is_switched_off() {
		$this->qualifying_cart();
		$this->settings( array( 'enabled' => 'no' ) );

		$this->assertSame( 'CART', $this->blocks->inject_chooser( 'CART', array( 'blockName' => 'woocommerce/cart' ) ) );
	}

	public function test_the_slot_is_injected_only_once_per_page() {
		$this->qualifying_cart();

		$first  = $this->blocks->inject_chooser( 'CART', array( 'blockName' => 'woocommerce/cart' ) );
		$second = $this->blocks->inject_chooser( 'CHECKOUT', array( 'blockName' => 'woocommerce/checkout' ) );

		$this->assertStringContainsString( 'data-bogo-slot', $first );
		$this->assertSame( 'CHECKOUT', $second );
	}

	public function test_the_slot_is_still_rendered_when_the_cart_does_not_yet_qualify() {
		$this->settings(
			array(
				'enabled'   => 'yes',
				'buy_scope' => 'all',
				'get_scope' => 'all',
				'buy_qty'   => 5,
			)
		);

		$this->product( 10 );
		$this->add_paid_item( 'paid', 10, 1 );

		$output = $this->blocks->inject_chooser( 'CART', array( 'blockName' => 'woocommerce/cart' ) );

		// An empty slot is the mount point the script fills the moment the
		// customer crosses the threshold without reloading the page.
		$this->assertStringContainsString( 'data-bogo-slot="1"', $output );
		$this->assertStringNotContainsString( 'id="bogo-select"', $output );
	}

	/**
	 * Stand in for WooCommerce's BlockTypesController::add_data_attributes().
	 *
	 * The real one is a priority-10 `render_block` filter that walks to the
	 * first opening tag of the content it is handed — WP_HTML_Tag_Processor's
	 * next_tag() — and writes `data-block-name` there. That is the whole
	 * mechanism this plugin has to stay clear of, so the stand-in reproduces
	 * exactly it: first tag, whatever that tag happens to be.
	 */
	protected function as_woocommerce_stamps_block_names() {
		add_filter(
			'render_block',
			function ( $content, $block ) {
				$name = isset( $block['blockName'] ) ? $block['blockName'] : '';

				if ( 0 !== strpos( (string) $name, 'woocommerce/' ) ) {
					return $content;
				}

				return preg_replace( '/<([a-zA-Z0-9-]+)/', '<$1 data-block-name="' . $name . '"', $content, 1 );
			},
			10,
			2
		);
	}

	/**
	 * Every opening `div` tag in some markup, as raw strings.
	 *
	 * @param string $html Markup.
	 * @return string[]
	 */
	protected function div_tags( $html ) {
		preg_match_all( '/<div[^>]*>/', $html, $matches );

		return $matches[0];
	}

	/**
	 * The one opening tag containing a marker.
	 *
	 * @param string $html   Markup.
	 * @param string $marker Substring identifying the tag.
	 * @return string
	 */
	protected function tag_containing( $html, $marker ) {
		foreach ( $this->div_tags( $html ) as $tag ) {
			if ( false !== strpos( $tag, $marker ) ) {
				return $tag;
			}
		}

		$this->fail( sprintf( 'No opening tag containing "%s" in: %s', $marker, $html ) );
	}

	/**
	 * The regression behind CODEX-REVIEW.md H-01.
	 *
	 * Injecting at priority 10 was a coin toss decided by plugin load order.
	 * Losing it meant WooCommerce branded the BOGO slot with the Checkout
	 * block's name and left the real Checkout root unbranded, so the Checkout
	 * frontend mounted against an empty div and never rendered. This goes
	 * through the filter chain rather than calling inject_chooser() directly,
	 * because the ordering is the entire point — a direct call cannot see it.
	 *
	 * @dataProvider block_names
	 * @param string $block_name Block being rendered.
	 * @param string $root_class Class on that block's root element.
	 */
	public function test_woocommerce_stamps_the_real_block_root_and_not_the_chooser_slot( $block_name, $root_class ) {
		$this->qualifying_cart();
		$this->as_woocommerce_stamps_block_names();

		$output = apply_filters(
			'render_block',
			sprintf( '<div class="%s is-loading"></div>', $root_class ),
			array( 'blockName' => $block_name )
		);

		$slot = $this->tag_containing( $output, 'bogo-select-slot' );
		$root = $this->tag_containing( $output, $root_class );

		$this->assertStringNotContainsString(
			'data-block-name',
			$slot,
			'The chooser slot must never carry a WooCommerce block name; the block frontend would mount against it.'
		);
		$this->assertStringContainsString(
			sprintf( 'data-block-name="%s"', $block_name ),
			$root,
			'The real block root must keep its own identity, or its frontend never mounts.'
		);
	}

	/**
	 * @return array<string, string[]>
	 */
	public static function block_names() {
		return array(
			'checkout' => array( 'woocommerce/checkout', 'wp-block-woocommerce-checkout' ),
			'cart'     => array( 'woocommerce/cart', 'wp-block-woocommerce-cart' ),
		);
	}

	public function test_the_chooser_still_precedes_the_block_through_the_filter_chain() {
		$this->qualifying_cart();
		$this->as_woocommerce_stamps_block_names();

		$output = apply_filters(
			'render_block',
			'<div class="wp-block-woocommerce-cart"></div>',
			array( 'blockName' => 'woocommerce/cart' )
		);

		// Running late must not cost the chooser its position above the block.
		$this->assertStringStartsWith( '<div class="bogo-select-slot"', $output );
	}

	// --- Store API state ----------------------------------------------------

	public function test_the_cart_response_carries_the_offer_state() {
		$this->qualifying_cart();
		$this->add_gift_item( 'gift', 20, 1 );

		$data = BOGO_Select_Blocks::store_api_data();

		$this->assertTrue( $data['active'] );
		$this->assertTrue( $data['qualifies'] );
		$this->assertSame( 1, $data['reward_quantity'] );
		$this->assertSame( 20, $data['selected_product_id'] );
		$this->assertNotSame( '', $data['signature'] );
	}

	public function test_the_signature_changes_when_the_chooser_would_render_differently() {
		$this->qualifying_cart();

		$before = BOGO_Select_Engine::state_signature();

		$this->add_gift_item( 'gift', 20, 1 );

		$this->assertNotSame( $before, BOGO_Select_Engine::state_signature() );
	}

	public function test_the_schema_describes_every_state_field() {
		$this->assertSame(
			array_keys( BOGO_Select_Blocks::store_api_data() ),
			array_keys( BOGO_Select_Blocks::store_api_schema() )
		);
	}

	// --- Store API updates --------------------------------------------------

	public function test_choosing_through_the_store_api_adds_the_gift() {
		$this->qualifying_cart();

		BOGO_Select_Blocks::store_api_update(
			array(
				'action'     => 'choose',
				'product_id' => 20,
			)
		);

		$this->assertSame( 20, BOGO_Select_Engine::selected_product_id( $this->cart() ) );
	}

	public function test_removing_through_the_store_api_clears_the_gift() {
		$this->qualifying_cart();
		$this->add_gift_item( 'gift', 20, 1 );

		BOGO_Select_Blocks::store_api_update( array( 'action' => 'remove' ) );

		$this->assertSame( array(), BOGO_Select_Engine::find_reward_keys( $this->cart() ) );
	}

	public function test_a_refused_choice_throws_so_the_blocks_can_show_why() {
		$this->qualifying_cart();

		$this->expectException( Exception::class );

		BOGO_Select_Blocks::store_api_update(
			array(
				'action'     => 'choose',
				'product_id' => 999,
			)
		);
	}

	public function test_an_unknown_action_is_rejected() {
		$this->qualifying_cart();

		$this->expectException( Exception::class );

		BOGO_Select_Blocks::store_api_update( array( 'action' => 'something-else' ) );
	}

	// --- Block presentation -------------------------------------------------

	public function test_the_gift_line_is_labelled_for_the_blocks() {
		$this->as_store_api_request();

		$item_data = $this->blocks->item_data( array(), array( BOGO_Select_Engine::FLAG => true ) );

		$this->assertCount( 1, $item_data );
		$this->assertSame( 'Free gift: BOGO promotion', $this->as_blocks_would_render( $item_data[0] ) );
	}

	/**
	 * The regression behind CODEX-REVIEW M-01.
	 *
	 * A block cart renders its first frame from a cart response WooCommerce
	 * builds during the page request, where REQUEST_URI is the cart page and
	 * not a Store API route. The label has to survive that.
	 */
	public function test_the_gift_line_is_labelled_in_the_preloaded_block_cart() {
		$item_data = $this->during_hydration(
			function () {
				return $this->blocks->item_data( array(), array( BOGO_Select_Engine::FLAG => true ) );
			}
		);

		$this->assertCount( 1, $item_data );
		$this->assertSame( 'Free gift: BOGO promotion', $this->as_blocks_would_render( $item_data[0] ) );
	}

	public function test_the_gift_line_is_labelled_in_a_dispatched_store_api_response() {
		$item_data = $this->during_rest_request(
			'/wc/store/v1/cart',
			function () {
				return $this->blocks->item_data( array(), array( BOGO_Select_Engine::FLAG => true ) );
			}
		);

		$this->assertCount( 1, $item_data );
		$this->assertSame( 'Free gift: BOGO promotion', $this->as_blocks_would_render( $item_data[0] ) );
	}

	public function test_other_rest_routes_do_not_open_the_block_label() {
		$item_data = $this->during_rest_request(
			'/wp/v2/posts',
			function () {
				return $this->blocks->item_data( array(), array( BOGO_Select_Engine::FLAG => true ) );
			}
		);

		$this->assertSame( array(), $item_data );
	}

	public function test_ordinary_lines_are_not_labelled() {
		$this->as_store_api_request();

		$this->assertSame( array(), $this->blocks->item_data( array(), array( 'product_id' => 10 ) ) );
	}

	public function test_the_classic_cart_does_not_get_the_block_label_as_well() {
		// No Store API response being built: the classic cart already badges
		// the name, and saying it twice reads as a bug.
		$this->assertSame( array(), $this->blocks->item_data( array(), array( BOGO_Select_Engine::FLAG => true ) ) );
	}

	public function test_the_block_label_closes_again_after_the_response_is_built() {
		$this->during_hydration( function () {} );

		$this->assertSame( array(), $this->blocks->item_data( array(), array( BOGO_Select_Engine::FLAG => true ) ) );
	}

	public function test_the_gift_quantity_cannot_be_edited_in_a_block_cart() {
		$gift = array(
			BOGO_Select_Engine::FLAG => true,
			'quantity'               => 3,
		);

		$this->assertFalse( $this->blocks->quantity_editable( true, null, $gift ) );
		$this->assertTrue( $this->blocks->quantity_editable( true, null, array( 'quantity' => 3 ) ) );
	}

	public function test_the_gift_quantity_is_pinned_to_the_units_earned() {
		$gift = array(
			BOGO_Select_Engine::FLAG => true,
			'quantity'               => 3,
		);

		$this->assertSame( 3, $this->blocks->quantity_bound( 1, null, $gift ) );
		$this->assertSame( 3, $this->blocks->quantity_bound( 9999, null, $gift ) );
		$this->assertSame( 99, $this->blocks->quantity_bound( 99, null, array( 'quantity' => 3 ) ) );
	}

	// --- Shared rendering ---------------------------------------------------

	public function test_the_classic_and_block_slots_hold_identical_choosers() {
		$this->qualifying_cart();

		$block = $this->blocks->inject_chooser( '', array( 'blockName' => 'woocommerce/cart' ) );

		BOGO_Select_Frontend::forget_slot();

		$classic = BOGO_Select_Frontend::slot_html( 'classic' );

		$this->assertSame(
			str_replace( 'data-bogo-mode="block"', '', $block ),
			str_replace( 'data-bogo-mode="classic"', '', $classic ),
			'Only the mode differs; the customer sees the same chooser either way.'
		);
	}
}
