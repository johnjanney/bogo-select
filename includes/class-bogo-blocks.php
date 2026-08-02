<?php
/**
 * Cart and Checkout Blocks support.
 *
 * The block cart and block checkout render themselves from the Store API in
 * the browser, so none of the classic template hooks fire inside them. This
 * class supplies the three things the blocks need instead:
 *
 * 1. A mount point. The chooser is injected ahead of the Cart and Checkout
 *    block markup, where the classic templates would have printed it.
 * 2. State and mutations over the Store API. The cart response carries the
 *    offer state, and gift selection goes through a registered update callback
 *    so the blocks re-render from the response they already trust.
 * 3. Block-side presentation. The gift line is labelled and its quantity is
 *    locked through the Store API's own quantity limits, because the classic
 *    quantity and name filters never run.
 *
 * @package BOGO_Select
 */

defined( 'ABSPATH' ) || exit;

/**
 * Everything that only matters when the cart or checkout is a block.
 */
class BOGO_Select_Blocks {

	/**
	 * Store API namespace for this plugin's extension data and updates.
	 */
	const NAMESPACE_KEY = 'bogo-select';

	/**
	 * Block names the chooser is injected ahead of.
	 *
	 * @var string[]
	 */
	protected static $target_blocks = array(
		'woocommerce/cart',
		'woocommerce/checkout',
	);

	/**
	 * Whether the Store API registration has already run.
	 *
	 * @var bool
	 */
	protected static $store_api_registered = false;

	/**
	 * How many Store API responses are being built right now.
	 *
	 * Nested rather than boolean because a hydrated page can build more than
	 * one Store API response while rendering, and because the closing filter
	 * must never switch off a scope its own opening filter did not start.
	 *
	 * @var int
	 */
	protected static $store_api_depth = 0;

	/**
	 * Register hooks.
	 */
	public function __construct() {
		// Priority 20 is load-bearing, not a default. WooCommerce's own
		// BlockTypesController::add_data_attributes() is a priority-10
		// `render_block` filter that walks to the *first tag* of the content it
		// is handed and stamps `data-block-name` on it. Prepending the chooser
		// at priority 10 is a coin toss decided by which plugin registered
		// first: lose it, and WooCommerce brands the BOGO slot
		// `data-block-name="woocommerce/checkout"` and leaves the real checkout
		// root unbranded, so the Checkout frontend mounts against an empty div
		// and the customer gets a permanent loading shell instead of a
		// checkout. Running after WooCommerce has decorated the original root
		// makes the ordering explicit rather than incidental.
		add_filter( 'render_block', array( $this, 'inject_chooser' ), 20, 2 );

		// Store API registration has to wait until WooCommerce Blocks is up.
		// The second hook is a backstop for the case where that action has
		// already fired, or does not fire at all.
		add_action( 'woocommerce_blocks_loaded', array( $this, 'register_store_api' ) );
		add_action( 'init', array( $this, 'register_store_api' ), 20 );

		// The block cart paints its first frame from a cart response that
		// WooCommerce builds *inside the page request* and preloads into the
		// markup, not from an HTTP call to /wc/store/. During that build
		// REQUEST_URI still says /cart/, so asking WooCommerce whether this is
		// a Store API request answers no and the gift line loses its label
		// until something else happens to refetch the cart. These filters
		// bracket the response build itself, which is the only signal that is
		// true for both the preloaded and the fetched cart.
		//
		// Each pair is balanced on every normal path, including the ones where
		// a callback short-circuits or returns an error. It is not a guarantee:
		// an uncaught fatal between the two filters would leave the depth
		// raised. That costs nothing, because such a request is already over —
		// the counter is per-request static state, not persisted — and the
		// depth is floored at zero on the way down so a stray closing filter
		// can never push it negative and disable the label.
		add_filter( 'rest_request_before_callbacks', array( $this, 'open_rest_scope' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( $this, 'close_rest_scope' ), 10, 3 );
		add_filter( 'woocommerce_hydration_dispatch_request', array( $this, 'open_hydration_scope' ), 10, 4 );
		add_filter( 'woocommerce_hydration_request_after_callbacks', array( $this, 'close_hydration_scope' ), 10, 3 );

		// Presentation of the gift line inside the blocks.
		add_filter( 'woocommerce_get_item_data', array( $this, 'item_data' ), 10, 2 );
		add_filter( 'woocommerce_store_api_product_quantity_editable', array( $this, 'quantity_editable' ), 10, 3 );
		add_filter( 'woocommerce_store_api_product_quantity_minimum', array( $this, 'quantity_bound' ), 10, 3 );
		add_filter( 'woocommerce_store_api_product_quantity_maximum', array( $this, 'quantity_bound' ), 10, 3 );
	}

	/**
	 * Put the chooser slot in front of the Cart or Checkout block.
	 *
	 * @param string              $content Rendered block HTML.
	 * @param array<string,mixed> $block   Parsed block.
	 * @return string
	 */
	public function inject_chooser( $content, $block ) {
		if ( is_admin() || wp_doing_ajax() || self::is_store_api_request() ) {
			return $content;
		}

		$name = isset( $block['blockName'] ) ? $block['blockName'] : '';

		if ( ! in_array( $name, self::$target_blocks, true ) ) {
			return $content;
		}

		if ( ! BOGO_Select_Engine::is_active() || BOGO_Select_Frontend::slot_rendered() ) {
			return $content;
		}

		// The assets come with the slot; see BOGO_Select_Frontend::slot_html().
		return BOGO_Select_Frontend::slot_html( 'block' ) . $content;
	}

	/**
	 * Register the plugin's Store API extension data and update callback.
	 *
	 * @return void
	 */
	public function register_store_api() {
		if ( self::$store_api_registered ) {
			return;
		}

		self::$store_api_registered = true;

		if ( function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			woocommerce_store_api_register_endpoint_data(
				array(
					'endpoint'        => 'cart',
					'namespace'       => self::NAMESPACE_KEY,
					'data_callback'   => array( __CLASS__, 'store_api_data' ),
					'schema_callback' => array( __CLASS__, 'store_api_schema' ),
					'schema_type'     => ARRAY_A,
				)
			);
		}

		if ( function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
			woocommerce_store_api_register_update_callback(
				array(
					'namespace' => self::NAMESPACE_KEY,
					'callback'  => array( __CLASS__, 'store_api_update' ),
				)
			);
		}
	}

	/**
	 * Offer state carried on every Store API cart response.
	 *
	 * @return array<string,mixed>
	 */
	public static function store_api_data() {
		$state = BOGO_Select_Engine::state();

		return array(
			'active'                => (bool) $state['active'],
			'qualifies'             => (bool) $state['qualifies'],
			'reward_quantity'       => (int) $state['reward_quantity'],
			'selected_product_id'   => (int) $state['selected_product_id'],
			'selected_variation_id' => (int) $state['selected_variation_id'],
			'signature'             => (string) $state['signature'],
		);
	}

	/**
	 * Schema for the data above.
	 *
	 * @return array<string,mixed>
	 */
	public static function store_api_schema() {
		return array(
			'active'                => array(
				'description' => __( 'Whether the BOGO offer is running.', 'bogo-select' ),
				'type'        => 'boolean',
				'readonly'    => true,
			),
			'qualifies'             => array(
				'description' => __( 'Whether this cart has earned a reward.', 'bogo-select' ),
				'type'        => 'boolean',
				'readonly'    => true,
			),
			'reward_quantity'       => array(
				'description' => __( 'Number of reward units earned.', 'bogo-select' ),
				'type'        => 'integer',
				'readonly'    => true,
			),
			'selected_product_id'   => array(
				'description' => __( 'Product ID of the chosen reward, or 0. The parent, when a variation was chosen.', 'bogo-select' ),
				'type'        => 'integer',
				'readonly'    => true,
			),
			'selected_variation_id' => array(
				'description' => __( 'Variation ID of the chosen reward, or 0 when it is not a variation.', 'bogo-select' ),
				'type'        => 'integer',
				'readonly'    => true,
			),
			'signature'             => array(
				'description' => __( 'Changes whenever the gift chooser would render differently.', 'bogo-select' ),
				'type'        => 'string',
				'readonly'    => true,
			),
		);
	}

	/**
	 * Handle a gift change requested by the blocks.
	 *
	 * Called from the browser through wc.blocksCheckout.extensionCartUpdate(),
	 * inside the Store API's own cart request: the response the blocks receive
	 * already reflects the new cart, so no reload and no second fetch is
	 * needed. Selection itself goes through the same code the classic AJAX
	 * endpoint uses, so both modes obey the same rules.
	 *
	 * @param array<string,mixed> $data Posted data: action, and product_id for a choice.
	 * @throws Exception When the gift cannot be given.
	 * @return void
	 */
	public static function store_api_update( $data ) {
		$cart = function_exists( 'WC' ) && WC()->cart ? WC()->cart : null;

		if ( ! $cart ) {
			self::error( __( 'Your cart is not available. Please refresh the page.', 'bogo-select' ) );
		}

		$action       = isset( $data['action'] ) ? sanitize_key( BOGO_Select_Settings::to_text( $data['action'] ) ) : '';
		$product_id   = isset( $data['product_id'] ) ? BOGO_Select_Settings::to_id( $data['product_id'] ) : 0;
		$variation_id = isset( $data['variation_id'] ) ? BOGO_Select_Settings::to_id( $data['variation_id'] ) : 0;

		if ( 'remove' === $action ) {
			BOGO_Select_Ajax::clear_gift( $cart );

			return;
		}

		if ( 'choose' !== $action ) {
			self::error( __( 'Unrecognised gift request.', 'bogo-select' ) );
		}

		$result = BOGO_Select_Ajax::select_gift( $cart, $product_id, $variation_id );

		if ( is_string( $result ) ) {
			self::error( $result );
		}
	}

	/**
	 * Label the gift line where the blocks can see it.
	 *
	 * The blocks render item metadata from this filter; the classic cart shows
	 * the badge on the product name instead, so this only speaks up while a
	 * Store API response is being built, to avoid saying the same thing twice.
	 *
	 * Every member is spelled out twice on purpose. The blocks read the label
	 * as `key` or `name` and the text as `display` or `value`, and which one
	 * wins has moved between WooCommerce versions; supplying both halves of
	 * each pair means the row reads the same on every supported release. An
	 * empty `display` is what previously blanked the row on the versions that
	 * prefer it.
	 *
	 * @param array<int,array<string,mixed>> $item_data Item metadata.
	 * @param array<string,mixed>            $cart_item Cart item.
	 * @return array<int,array<string,mixed>>
	 */
	public function item_data( $item_data, $cart_item ) {
		if ( ! BOGO_Select_Engine::is_reward_item( $cart_item ) || ! self::is_store_api_context() ) {
			return $item_data;
		}

		$meta  = BOGO_Select_Engine::reward_meta();
		$label = $meta['label'];
		$value = $meta['value'];

		$item_data[] = array(
			'key'     => $label,
			'name'    => $label,
			'value'   => $value,
			'display' => $value,
		);

		return $item_data;
	}

	/**
	 * Note that a Store API response has started being built.
	 *
	 * @param mixed                $response Response so far.
	 * @param array<string,mixed>  $handler  Route handler.
	 * @param WP_REST_Request|null $request Request being served.
	 * @return mixed
	 */
	public function open_rest_scope( $response, $handler = array(), $request = null ) {
		if ( self::is_store_api_route( $request ) ) {
			++self::$store_api_depth;
		}

		return $response;
	}

	/**
	 * Note that a Store API response has finished being built.
	 *
	 * @param mixed                $response Response.
	 * @param array<string,mixed>  $handler  Route handler.
	 * @param WP_REST_Request|null $request Request being served.
	 * @return mixed
	 */
	public function close_rest_scope( $response, $handler = array(), $request = null ) {
		if ( self::is_store_api_route( $request ) ) {
			self::$store_api_depth = max( 0, self::$store_api_depth - 1 );
		}

		return $response;
	}

	/**
	 * Note that a preloaded Store API response has started being built.
	 *
	 * WooCommerce only runs this filter for its own hydration of `/wc/store`
	 * paths, so reaching it is itself the signal.
	 *
	 * @param mixed               $result  Short-circuit result, if any.
	 * @param WP_REST_Request     $request Request being served.
	 * @param string              $path    Store API path.
	 * @param array<string,mixed> $handler Route handler.
	 * @return mixed
	 */
	public function open_hydration_scope( $result = null, $request = null, $path = '', $handler = array() ) {
		++self::$store_api_depth;

		return $result;
	}

	/**
	 * Note that a preloaded Store API response has finished being built.
	 *
	 * @param mixed               $response Response.
	 * @param array<string,mixed> $handler  Route handler.
	 * @param WP_REST_Request     $request  Request being served.
	 * @return mixed
	 */
	public function close_hydration_scope( $response, $handler = array(), $request = null ) {
		self::$store_api_depth = max( 0, self::$store_api_depth - 1 );

		return $response;
	}

	/**
	 * Whether a Store API cart response is being built for this cart.
	 *
	 * True for a fetched cart and for the preloaded one the block cart first
	 * renders from; `is_store_api_request()` only sees the former.
	 *
	 * @return bool
	 */
	public static function is_store_api_context() {
		return self::$store_api_depth > 0 || self::is_store_api_request();
	}

	/**
	 * Whether a REST request is aimed at the Store API.
	 *
	 * @param mixed $request Request, or anything else.
	 * @return bool
	 */
	protected static function is_store_api_route( $request ) {
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return false;
		}

		return 0 === strpos( (string) $request->get_route(), '/wc/store/' );
	}

	/**
	 * Gift lines are not resizable in the block cart.
	 *
	 * @param bool                $editable  Whether the quantity may be edited.
	 * @param WC_Product          $product   Product.
	 * @param array<string,mixed> $cart_item Cart item.
	 * @return bool
	 */
	public function quantity_editable( $editable, $product = null, $cart_item = array() ) {
		return BOGO_Select_Engine::is_reward_item( $cart_item ) ? false : $editable;
	}

	/**
	 * Pin the gift line's minimum and maximum to the units it has earned.
	 *
	 * Both bounds use the same callback: for a gift there is exactly one
	 * permitted quantity, and it is the one validation has already settled on.
	 *
	 * @param int                 $value     Quantity bound.
	 * @param WC_Product          $product   Product.
	 * @param array<string,mixed> $cart_item Cart item.
	 * @return int
	 */
	public function quantity_bound( $value, $product = null, $cart_item = array() ) {
		if ( ! BOGO_Select_Engine::is_reward_item( $cart_item ) ) {
			return $value;
		}

		return max( 1, BOGO_Select_Settings::to_id( $cart_item['quantity'] ) );
	}

	/**
	 * Whether this request is being served to the Store API.
	 *
	 * @return bool
	 */
	public static function is_store_api_request() {
		if ( function_exists( 'WC' ) && is_object( WC() ) && method_exists( WC(), 'is_store_api_request' ) ) {
			return (bool) WC()->is_store_api_request();
		}

		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] )
			? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '';

		return false !== strpos( $uri, '/wc/store/' );
	}

	/**
	 * Fail a Store API update in the way the blocks understand.
	 *
	 * @param string $message Customer-facing message.
	 * @throws Exception Always.
	 * @return never
	 */
	protected static function error( $message ) {
		$route_exception = '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException';

		if ( class_exists( $route_exception ) ) {
			throw new $route_exception( 'bogo_select_error', $message, 400 );
		}

		throw new Exception( $message );
	}
}
