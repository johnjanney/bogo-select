<?php
/**
 * Minimal WooCommerce stand-ins for the unit suite.
 *
 * @package BOGO_Select
 */

/**
 * A product with just the traits the plugin inspects.
 */
class WC_Product {

	/**
	 * Product properties.
	 *
	 * @var array
	 */
	protected $props;

	/**
	 * Build a product.
	 *
	 * @param array $props Overrides for the defaults below.
	 */
	public function __construct( array $props = array() ) {
		$this->props = array_merge(
			array(
				'id'                => 0,
				'name'              => 'Product',
				'sku'               => '',
				'type'              => 'simple',
				'status'            => 'publish',
				'purchasable'       => true,
				'price'             => 10.0,
				'in_stock'          => true,
				'managing_stock'    => false,
				'stock_quantity'    => 0,
				'backorders'        => false,
				'sold_individually' => false,
				'stock_managed_by'  => 0,
			),
			$props
		);
	}

	/**
	 * Change a property after construction.
	 *
	 * @param string $key   Property.
	 * @param mixed  $value Value.
	 * @return WC_Product
	 */
	public function set( $key, $value ) {
		$this->props[ $key ] = $value;

		return $this;
	}

	/**
	 * Product ID.
	 *
	 * @return int
	 */
	public function get_id() {
		return (int) $this->props['id'];
	}

	/**
	 * Product name.
	 *
	 * @return string
	 */
	public function get_name() {
		return (string) $this->props['name'];
	}

	/**
	 * Product SKU.
	 *
	 * @return string
	 */
	public function get_sku() {
		return (string) $this->props['sku'];
	}

	/**
	 * Post status.
	 *
	 * @return string
	 */
	public function get_status() {
		return (string) $this->props['status'];
	}

	/**
	 * Price.
	 *
	 * @return float
	 */
	public function get_price() {
		return (float) $this->props['price'];
	}

	/**
	 * Set the price.
	 *
	 * @param float $price Price.
	 */
	public function set_price( $price ) {
		$this->props['price'] = (float) $price;
	}

	/**
	 * Whether this is the given product type.
	 *
	 * @param string|string[] $type Type(s).
	 * @return bool
	 */
	public function is_type( $type ) {
		return in_array( $this->props['type'], (array) $type, true );
	}

	/**
	 * Whether the product can be bought.
	 *
	 * @return bool
	 */
	public function is_purchasable() {
		return (bool) $this->props['purchasable'];
	}

	/**
	 * Whether any stock remains.
	 *
	 * @return bool
	 */
	public function is_in_stock() {
		return (bool) $this->props['in_stock'];
	}

	/**
	 * Whether stock is tracked.
	 *
	 * @return bool
	 */
	public function managing_stock() {
		return (bool) $this->props['managing_stock'];
	}

	/**
	 * Whether backorders are permitted.
	 *
	 * @return bool
	 */
	public function backorders_allowed() {
		return (bool) $this->props['backorders'];
	}

	/**
	 * Whether the tracked stock covers the quantity.
	 *
	 * @param int $quantity Quantity.
	 * @return bool
	 */
	public function has_enough_stock( $quantity ) {
		if ( ! $this->managing_stock() ) {
			return true;
		}

		return (int) $this->props['stock_quantity'] >= (int) $quantity;
	}

	/**
	 * Whether only one may be bought per order.
	 *
	 * @return bool
	 */
	public function is_sold_individually() {
		return (bool) $this->props['sold_individually'];
	}

	/**
	 * The ID holding the stock record.
	 *
	 * @return int
	 */
	public function get_stock_managed_by_id() {
		return (int) ( $this->props['stock_managed_by'] ? $this->props['stock_managed_by'] : $this->props['id'] );
	}
}

/**
 * A cart with the handful of methods the plugin uses.
 */
class WC_Cart {

	/**
	 * Cart contents keyed by cart item key.
	 *
	 * @var array
	 */
	protected $items = array();

	/**
	 * Put a line in the cart.
	 *
	 * @param string $key  Cart item key.
	 * @param array  $item Cart item.
	 * @return string The key.
	 */
	public function add_item( $key, array $item ) {
		$item = array_merge(
			array(
				'product_id'   => 0,
				'variation_id' => 0,
				'quantity'     => 1,
			),
			$item
		);

		if ( ! isset( $item['data'] ) ) {
			$item['data'] = wc_get_product( (int) $item['product_id'] );
		}

		$this->items[ $key ] = $item;

		return $key;
	}

	/**
	 * Cart contents.
	 *
	 * @return array
	 */
	public function get_cart() {
		return $this->items;
	}

	/**
	 * A single cart item, or an empty array.
	 *
	 * @param string $key Cart item key.
	 * @return array
	 */
	public function get_cart_item( $key ) {
		return isset( $this->items[ $key ] ) ? $this->items[ $key ] : array();
	}

	/**
	 * Remove a line.
	 *
	 * @param string $key Cart item key.
	 * @return bool
	 */
	public function remove_cart_item( $key ) {
		unset( $this->items[ $key ] );

		return true;
	}

	/**
	 * Resize a line.
	 *
	 * @param string $key      Cart item key.
	 * @param int    $quantity New quantity.
	 * @param bool   $refresh  Unused.
	 * @return bool
	 */
	public function set_quantity( $key, $quantity, $refresh = true ) {
		if ( (int) $quantity <= 0 ) {
			return $this->remove_cart_item( $key );
		}

		if ( isset( $this->items[ $key ] ) ) {
			$this->items[ $key ]['quantity'] = (int) $quantity;
		}

		return true;
	}
}

/**
 * Stand-in for the WooCommerce singleton.
 */
class BOGO_Test_WooCommerce {

	/**
	 * The cart.
	 *
	 * @var WC_Cart|null
	 */
	public $cart;

	/**
	 * A truthy session.
	 *
	 * @var object
	 */
	public $session;

	/**
	 * Wire up the current test cart.
	 */
	public function __construct() {
		$this->cart    = BOGO_Test_Env::$cart;
		$this->session = new stdClass();
	}
}

/**
 * The WooCommerce singleton.
 *
 * @return BOGO_Test_WooCommerce
 */
function WC() { // phpcs:ignore
	return new BOGO_Test_WooCommerce();
}

/**
 * Fetch a product from the fake catalogue.
 *
 * @param int $product_id Product ID.
 * @return WC_Product|false
 */
function wc_get_product( $product_id ) {
	$product_id = (int) $product_id;

	return isset( BOGO_Test_Env::$products[ $product_id ] ) ? BOGO_Test_Env::$products[ $product_id ] : false;
}

/**
 * Query the fake catalogue.
 *
 * Supports the arguments the plugin passes: status, type, s, orderby title,
 * limit, page, paginate, and return => ids.
 *
 * @param array $args Query arguments.
 * @return int[]|stdClass
 */
function wc_get_products( $args = array() ) {
	$status = isset( $args['status'] ) ? $args['status'] : '';
	$type   = isset( $args['type'] ) ? $args['type'] : '';
	$search = isset( $args['s'] ) ? (string) $args['s'] : '';

	$matches = array_filter(
		array_values( BOGO_Test_Env::$products ),
		function ( $product ) use ( $status, $type, $search ) {
			if ( $status && $product->get_status() !== $status ) {
				return false;
			}

			if ( $type && ! $product->is_type( $type ) ) {
				return false;
			}

			if ( '' !== $search && false === stripos( $product->get_name() . ' ' . $product->get_sku(), $search ) ) {
				return false;
			}

			return true;
		}
	);

	$matches = array_values( $matches );

	usort(
		$matches,
		function ( $a, $b ) {
			return strcasecmp( $a->get_name(), $b->get_name() );
		}
	);

	$total = count( $matches );
	$limit = isset( $args['limit'] ) ? max( 1, (int) $args['limit'] ) : 10;
	$page  = isset( $args['page'] ) ? max( 1, (int) $args['page'] ) : 1;

	$ids = array_map(
		function ( $product ) {
			return $product->get_id();
		},
		array_slice( $matches, ( $page - 1 ) * $limit, $limit )
	);

	if ( empty( $args['paginate'] ) ) {
		return $ids;
	}

	$results                = new stdClass();
	$results->products      = $ids;
	$results->total         = $total;
	$results->max_num_pages = (int) max( 1, ceil( $total / $limit ) );

	return $results;
}

/**
 * Record a notice.
 *
 * @param string $message Message.
 * @param string $type    Notice type.
 */
function wc_add_notice( $message, $type = 'success' ) {
	BOGO_Test_Env::$notices[] = array(
		'notice' => $message,
		'type'   => $type,
	);
}

/**
 * Whether a notice has already been raised.
 *
 * @param string $message Message.
 * @param string $type    Notice type.
 * @return bool
 */
function wc_has_notice( $message, $type = 'success' ) {
	foreach ( BOGO_Test_Env::$notices as $notice ) {
		if ( $notice['notice'] === $message && $notice['type'] === $type ) {
			return true;
		}
	}

	return false;
}

/**
 * Read recorded notices.
 *
 * @param string $type Notice type, or empty for all.
 * @return array
 */
function wc_get_notices( $type = '' ) {
	if ( ! $type ) {
		return BOGO_Test_Env::$notices;
	}

	return array_values(
		array_filter(
			BOGO_Test_Env::$notices,
			function ( $notice ) use ( $type ) {
				return $notice['type'] === $type;
			}
		)
	);
}

/**
 * Display price for a quantity.
 *
 * @param WC_Product $product Product.
 * @param array      $args    Optional 'qty'.
 * @return float
 */
function wc_get_price_to_display( $product, $args = array() ) {
	$qty = isset( $args['qty'] ) ? (int) $args['qty'] : 1;

	return $product->get_price() * $qty;
}

/**
 * Format a price.
 *
 * @param float $price Price.
 * @return string
 */
function wc_price( $price ) {
	return '<span class="amount">' . number_format( (float) $price, 2 ) . '</span>';
}
