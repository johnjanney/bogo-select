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
				'description'       => '',

				/*
				 * Variable products. A parent carries 'children'; a variation
				 * carries 'parent_id' and the attributes that identify it.
				 *
				 * An attribute whose value is an empty string is WooCommerce's
				 * "any" — the variation matches every value of that attribute
				 * and so cannot be added without a further choice.
				 */
				'parent_id'         => 0,
				'children'          => array(),
				'attributes'        => array(),
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

	/**
	 * Product description, which WordPress keyword search does cover.
	 *
	 * @return string
	 */
	public function get_description() {
		return (string) $this->props['description'];
	}

	/**
	 * A placeholder image tag.
	 *
	 * @param string $size Image size.
	 * @return string
	 */
	public function get_image( $size = 'woocommerce_thumbnail' ) {
		return '<img src="' . esc_attr( $size ) . '.png" alt="" />';
	}

	/**
	 * The variable parent this variation belongs to.
	 *
	 * @return int Zero for anything that is not a variation.
	 */
	public function get_parent_id() {
		return (int) $this->props['parent_id'];
	}

	/**
	 * Variation IDs belonging to a variable parent.
	 *
	 * WooCommerce returns these in menu order, and returns an empty array for
	 * every product type that cannot have children.
	 *
	 * @return int[]
	 */
	public function get_children() {
		return array_map( 'absint', (array) $this->props['children'] );
	}

	/**
	 * The attributes that pick this variation out of its parent.
	 *
	 * Keyed by attribute name. An empty value is "any", which is why the plugin
	 * has to look at the values rather than only counting them.
	 *
	 * @return array<string,string>
	 */
	public function get_variation_attributes() {
		return (array) $this->props['attributes'];
	}
}

/**
 * A cart with the handful of methods the plugin uses.
 */
/**
 * Just enough of an order line item to record metadata against.
 */
class WC_Order_Item_Product {

	/**
	 * Metadata written so far, keyed by meta key.
	 *
	 * @var array
	 */
	public $meta = array();

	/**
	 * Record a piece of metadata.
	 *
	 * @param string $key    Meta key.
	 * @param mixed  $value  Meta value.
	 * @param bool   $unique Whether the key is unique.
	 */
	public function add_meta_data( $key, $value, $unique = false ) {
		$this->meta[ $key ] = $value;
	}

	/**
	 * A recorded meta value.
	 *
	 * @param string $key Meta key.
	 * @return mixed Null when nothing was recorded under that key.
	 */
	public function get_meta( $key ) {
		return array_key_exists( $key, $this->meta ) ? $this->meta[ $key ] : null;
	}
}

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
	 * The product object is cloned, because WooCommerce gives every cart line its
	 * own instance — one built per line from the data store, not the catalogue
	 * object itself. Sharing it here would let code that writes to a line's
	 * product (BOGO_Select_Cart::set_reward_price(), which sets the reward price)
	 * appear to change the catalogue, so a second pass would read back its own
	 * output. Production never behaves that way; the stub must not either.
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
			// The variation holds the line's own product where there is one. Tests
			// that use a bare variation ID with no product behind it — checking
			// that a variation qualifies through its parent — fall back to the
			// parent rather than ending up with a line that has no product at all.
			$product = $item['variation_id'] ? wc_get_product( (int) $item['variation_id'] ) : false;

			if ( ! $product ) {
				$product = wc_get_product( (int) $item['product_id'] );
			}

			$item['data'] = $product ? clone $product : $product;
		}

		$this->items[ $key ] = $item;

		return $key;
	}

	/**
	 * Add a line the way WC_Cart::add_to_cart() does.
	 *
	 * Returns false — after raising an error notice — when the test has asked
	 * for a rejected add, which is how core stock validation and third-party
	 * woocommerce_add_to_cart_validation callbacks refuse a product.
	 *
	 * @param int   $product_id     Product ID.
	 * @param int   $quantity       Quantity.
	 * @param int   $variation_id   Variation ID.
	 * @param array $variation      Variation attributes.
	 * @param array $cart_item_data Extra cart item data.
	 * @return string|false Cart item key, or false when refused.
	 */
	public function add_to_cart( $product_id, $quantity = 1, $variation_id = 0, $variation = array(), $cart_item_data = array() ) {
		if ( BOGO_Test_Env::$reject_add_to_cart ) {
			wc_add_notice( BOGO_Test_Env::$reject_add_to_cart, 'error' );

			return false;
		}

		$key = 'added_' . $product_id . '_' . count( $this->items );

		$this->add_item(
			$key,
			array_merge(
				(array) $cart_item_data,
				array(
					'product_id'   => (int) $product_id,
					'variation_id' => (int) $variation_id,
					'quantity'     => (int) $quantity,
				)
			)
		);

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
 * The product data store's search, which is where SKU matching lives.
 *
 * Mirrors WC_Product_Data_Store_CPT::search_products(): title, excerpt,
 * description, and SKU, optionally constrained to a list of IDs and capped by
 * a limit.
 */
class BOGO_Test_Product_Data_Store {

	/**
	 * Search products by keyword or SKU.
	 *
	 * @param string $term               Search term.
	 * @param string $type               Product type filter.
	 * @param bool   $include_variations Unused.
	 * @param bool   $all_statuses       Unused.
	 * @param int    $limit              Maximum matches.
	 * @param array  $include            IDs to constrain to.
	 * @param array  $exclude            IDs to leave out.
	 * @return int[]
	 */
	public function search_products( $term, $type = '', $include_variations = false, $all_statuses = false, $limit = null, $include = null, $exclude = null ) {
		$term    = (string) $term;
		$include = $include ? array_map( 'absint', (array) $include ) : array();
		$exclude = $exclude ? array_map( 'absint', (array) $exclude ) : array();
		$found   = array();

		foreach ( BOGO_Test_Env::$products as $product ) {
			if ( 'publish' !== $product->get_status() ) {
				continue;
			}

			if ( $type && ! $product->is_type( $type ) ) {
				continue;
			}

			if ( $include && ! in_array( $product->get_id(), $include, true ) ) {
				continue;
			}

			if ( $exclude && in_array( $product->get_id(), $exclude, true ) ) {
				continue;
			}

			$haystack = $product->get_name() . ' ' . $product->get_description() . ' ' . $product->get_sku();

			if ( false === stripos( $haystack, $term ) ) {
				continue;
			}

			$found[] = $product->get_id();
		}

		if ( $limit ) {
			$found = array_slice( $found, 0, (int) $limit );
		}

		BOGO_Test_Env::$store_searches[] = array(
			'term'    => $term,
			'include' => $include,
			'limit'   => $limit,
		);

		return $found;
	}
}

/**
 * Stand-in for WooCommerce's data store loader.
 */
class WC_Data_Store {

	/**
	 * Load a data store.
	 *
	 * @param string $name Store name.
	 * @return BOGO_Test_Product_Data_Store
	 * @throws Exception When the test has switched the store off.
	 */
	public static function load( $name ) {
		if ( 'product' !== $name || ! BOGO_Test_Env::$data_store ) {
			throw new Exception( 'Invalid data store.' );
		}

		return new BOGO_Test_Product_Data_Store();
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
 * Supports the arguments the plugin passes: status, type, s, sku, include,
 * orderby title, limit, page, paginate, and return => ids.
 *
 * `s` deliberately behaves the way WordPress does — it searches the title,
 * excerpt, and content and knows nothing about SKUs. Making it match SKUs here
 * is what let a broken "search by SKU" claim pass its own test in 1.1.0.
 * WooCommerce matches SKUs through the separate `sku` argument, so that is what
 * this stub does too.
 *
 * @param array $args Query arguments.
 * @return int[]|stdClass
 */
function wc_get_products( $args = array() ) {
	$status  = isset( $args['status'] ) ? $args['status'] : '';
	$type    = isset( $args['type'] ) ? $args['type'] : '';
	$search  = isset( $args['s'] ) ? (string) $args['s'] : '';
	$sku     = isset( $args['sku'] ) ? (string) $args['sku'] : '';
	$include = isset( $args['include'] ) ? array_map( 'absint', (array) $args['include'] ) : array();

	$matches = array_filter(
		array_values( BOGO_Test_Env::$products ),
		function ( $product ) use ( $status, $type, $search, $sku, $include ) {
			if ( $status && $product->get_status() !== $status ) {
				return false;
			}

			if ( $type && ! $product->is_type( $type ) ) {
				return false;
			}

			if ( $include && ! in_array( $product->get_id(), $include, true ) ) {
				return false;
			}

			// Keyword search: title, excerpt, and content. Not the SKU.
			if ( '' !== $search && false === stripos( $product->get_name() . ' ' . $product->get_description(), $search ) ) {
				return false;
			}

			// SKU search: a partial, case-insensitive match, as WooCommerce does.
			if ( '' !== $sku && false === stripos( $product->get_sku(), $sku ) ) {
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
 * `price` overrides the product's own price, which is how WooCommerce is asked to
 * render a figure the product does not itself hold — a discounted reward line,
 * for instance — while still applying the store's tax display rules.
 *
 * @param WC_Product $product Product.
 * @param array      $args    Optional 'qty' and 'price'.
 * @return float
 */
function wc_get_price_to_display( $product, $args = array() ) {
	$qty   = isset( $args['qty'] ) ? (int) $args['qty'] : 1;
	$price = isset( $args['price'] ) ? (float) $args['price'] : (float) $product->get_price();

	return $price * $qty;
}

/**
 * How many decimal places prices are stored and rounded to.
 *
 * @return int
 */
function wc_get_price_decimals() {
	return 2;
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
