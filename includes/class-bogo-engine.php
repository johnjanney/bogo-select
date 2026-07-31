<?php
/**
 * Qualification logic.
 *
 * Pure decisions about who qualifies and for how much. No output, no cart
 * mutation — BOGO_Select_Cart and BOGO_Select_Ajax act on what this reports.
 *
 * @package BOGO_Select
 */

defined( 'ABSPATH' ) || exit;

/**
 * Works out whether a cart qualifies and what it has earned.
 */
class BOGO_Select_Engine {

	/**
	 * Cart item data key marking a line as a BOGO gift.
	 */
	const FLAG = 'bogo_select_free';

	/**
	 * Whether the offer can run at all.
	 *
	 * @return bool
	 */
	public static function is_active() {
		if ( ! BOGO_Select_Settings::is_enabled() ) {
			return false;
		}

		// A "select" scope with an empty list can never match anything.
		if ( 'select' === BOGO_Select_Settings::get( 'buy_scope' ) && ! BOGO_Select_Settings::get( 'buy_products' ) ) {
			return false;
		}

		if ( 'select' === BOGO_Select_Settings::get( 'get_scope' ) && ! BOGO_Select_Settings::get( 'get_products' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Whether a purchased product counts toward the Buy quantity.
	 *
	 * Variations match on either their own ID or their parent's ID.
	 *
	 * @param int $product_id   Product ID.
	 * @param int $variation_id Variation ID, if any.
	 * @return bool
	 */
	public static function is_buy_eligible( $product_id, $variation_id = 0 ) {
		if ( 'all' === BOGO_Select_Settings::get( 'buy_scope' ) ) {
			return true;
		}

		$allowed = BOGO_Select_Settings::get( 'buy_products' );

		return in_array( (int) $product_id, $allowed, true )
			|| ( $variation_id && in_array( (int) $variation_id, $allowed, true ) );
	}

	/**
	 * Total quantity of Buy-eligible units in the cart.
	 *
	 * Gift lines are excluded so a reward can never qualify the cart for
	 * another reward (DECISION.md D-004).
	 *
	 * @param WC_Cart|null $cart Cart to inspect. Defaults to the session cart.
	 * @return int
	 */
	public static function count_buy_units( $cart = null ) {
		$cart = self::resolve_cart( $cart );

		if ( ! $cart ) {
			return 0;
		}

		$count = 0;

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( self::is_reward_item( $cart_item ) ) {
				continue;
			}

			$variation_id = isset( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0;

			if ( self::is_buy_eligible( (int) $cart_item['product_id'], $variation_id ) ) {
				$count += (int) $cart_item['quantity'];
			}
		}

		return $count;
	}

	/**
	 * How many free units the cart has earned.
	 *
	 * @param int $buy_count Eligible units in the cart.
	 * @return int Zero when the cart does not qualify.
	 */
	public static function reward_quantity( $buy_count ) {
		$buy_qty = (int) BOGO_Select_Settings::get( 'buy_qty' );
		$get_qty = (int) BOGO_Select_Settings::get( 'get_qty' );

		$qualifies = self::is_active() && $buy_count >= $buy_qty;

		/**
		 * Filter whether the cart qualifies for a gift.
		 *
		 * @param bool $qualifies Whether the cart qualifies.
		 * @param int  $buy_count Eligible units counted.
		 */
		$qualifies = (bool) apply_filters( 'bogo_select_qualifies', $qualifies, $buy_count );

		if ( ! $qualifies ) {
			return 0;
		}

		$sets = BOGO_Select_Settings::is_repeating() ? (int) floor( $buy_count / $buy_qty ) : 1;
		$qty  = max( 0, $sets * $get_qty );

		/**
		 * Filter the number of free units awarded.
		 *
		 * @param int $qty       Free units.
		 * @param int $buy_count Eligible units counted.
		 */
		return (int) apply_filters( 'bogo_select_reward_quantity', $qty, $buy_count );
	}

	/**
	 * Free units earned by the given cart.
	 *
	 * @param WC_Cart|null $cart Cart to inspect.
	 * @return int
	 */
	public static function reward_quantity_for_cart( $cart = null ) {
		return self::reward_quantity( self::count_buy_units( $cart ) );
	}

	/**
	 * Whether the cart currently qualifies for a gift.
	 *
	 * @param WC_Cart|null $cart Cart to inspect.
	 * @return bool
	 */
	public static function qualifies( $cart = null ) {
		return self::reward_quantity_for_cart( $cart ) > 0;
	}

	/**
	 * Whether a product may be offered as a gift.
	 *
	 * Scope membership only — this says nothing about stock. Use
	 * self::unavailable_reason() for availability.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	public static function is_get_eligible( $product_id ) {
		$product_id = (int) $product_id;
		$product    = wc_get_product( $product_id );

		if ( ! $product || ! $product->is_purchasable() ) {
			return false;
		}

		// Variable parents are ambiguous as gifts, and their variations are not
		// offered directly either (DECISION.md D-006).
		if ( $product->is_type( 'variable' ) || $product->is_type( 'variation' ) || $product->is_type( 'grouped' ) || $product->is_type( 'external' ) ) {
			return false;
		}

		if ( 'select' === BOGO_Select_Settings::get( 'get_scope' ) ) {
			return in_array( $product_id, BOGO_Select_Settings::get( 'get_products' ), true );
		}

		return 'publish' === $product->get_status();
	}

	/**
	 * Why a gift product cannot be awarded at the given quantity.
	 *
	 * @param WC_Product $product      Product to check.
	 * @param int        $qty          Quantity to award.
	 * @param int        $other_demand Units of the same stock-managed product already
	 *                                 claimed by other cart lines. Counted against
	 *                                 stock but not reported as free units.
	 * @return string Empty string when it can be awarded.
	 */
	public static function unavailable_reason( $product, $qty, $other_demand = 0 ) {
		if ( ! $product instanceof WC_Product ) {
			return __( 'This product is no longer available.', 'bogo-select' );
		}

		$qty          = (int) $qty;
		$other_demand = max( 0, (int) $other_demand );

		if ( ! $product->is_in_stock() ) {
			return __( 'Out of stock', 'bogo-select' );
		}

		if ( $product->managing_stock() && ! $product->backorders_allowed() && ! $product->has_enough_stock( $qty + $other_demand ) ) {
			if ( $other_demand > 0 ) {
				return sprintf(
					/* translators: 1: number of free units required, 2: units of the same product already in the cart. */
					__( 'Not enough stock for %1$d free units alongside the %2$d already in your cart', 'bogo-select' ),
					$qty,
					$other_demand
				);
			}

			return sprintf(
				/* translators: %d: number of units required. */
				__( 'Not enough stock for %d free units', 'bogo-select' ),
				$qty
			);
		}

		if ( $product->is_sold_individually() && ( $qty + $other_demand ) > 1 ) {
			return __( 'Limited to one per order', 'bogo-select' );
		}

		return '';
	}

	/**
	 * Units of a product's stock already claimed by the cart.
	 *
	 * Lines are matched on the ID that actually holds the stock record, so a
	 * variation that inherits its parent's stock counts against the same pool.
	 *
	 * @param WC_Cart|null $cart        Cart to inspect.
	 * @param WC_Product   $product     Product whose stock is in question.
	 * @param string       $exclude_key Cart item key to leave out of the total.
	 * @return int
	 */
	public static function stock_demand( $cart, $product, $exclude_key = '' ) {
		$cart = self::resolve_cart( $cart );

		if ( ! $cart || ! $product instanceof WC_Product ) {
			return 0;
		}

		$target = (int) $product->get_stock_managed_by_id();

		if ( ! $target ) {
			return 0;
		}

		$demand = 0;

		foreach ( $cart->get_cart() as $key => $cart_item ) {
			if ( $key === $exclude_key ) {
				continue;
			}

			if ( empty( $cart_item['data'] ) || ! $cart_item['data'] instanceof WC_Product ) {
				continue;
			}

			if ( (int) $cart_item['data']->get_stock_managed_by_id() === $target ) {
				$demand += (int) $cart_item['quantity'];
			}
		}

		return $demand;
	}

	/**
	 * How many gift options one page of the chooser holds.
	 *
	 * @return int
	 */
	public static function choices_per_page() {
		/**
		 * Filter how many gift options the chooser shows at once.
		 *
		 * Before 1.1.0 this capped the whole "All Products" list; it is now the
		 * page size, and every eligible product remains reachable by paging or
		 * searching.
		 *
		 * @param int $per_page Options per page.
		 */
		$per_page = (int) apply_filters( 'bogo_select_all_products_limit', 24 );

		return max( 1, $per_page );
	}

	/**
	 * One page of gift options, optionally narrowed by a search term.
	 *
	 * Both scopes are paged: "Select Products" over the configured list, "All
	 * Products" over the catalogue, so no eligible product is unreachable.
	 *
	 * @param array $args {
	 *     @type string $search   Search term matched against name and SKU.
	 *     @type int    $page     One-based page number.
	 *     @type int    $per_page Page size. Defaults to self::choices_per_page().
	 * }
	 * @return array {
	 *     @type int[] $ids   Product IDs on this page.
	 *     @type int   $page  Page actually returned.
	 *     @type int   $pages Total number of pages.
	 *     @type int   $total Total matching products before eligibility filtering.
	 * }
	 */
	public static function get_choice_page( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'search'   => '',
				'page'     => 1,
				'per_page' => 0,
			)
		);

		$search   = trim( (string) $args['search'] );
		$page     = max( 1, (int) $args['page'] );
		$per_page = (int) $args['per_page'] > 0 ? (int) $args['per_page'] : self::choices_per_page();

		if ( 'select' === BOGO_Select_Settings::get( 'get_scope' ) ) {
			return self::page_selected_choices( $search, $page, $per_page );
		}

		return self::page_all_choices( $search, $page, $per_page );
	}

	/**
	 * Product IDs on the first page of the chooser.
	 *
	 * Kept for callers that only want a single page; use self::get_choice_page()
	 * to reach the rest.
	 *
	 * @return int[]
	 */
	public static function get_choice_ids() {
		$page = self::get_choice_page();

		return $page['ids'];
	}

	/**
	 * Page the admin-configured gift list.
	 *
	 * @param string $search   Search term.
	 * @param int    $page     Page number.
	 * @param int    $per_page Page size.
	 * @return array
	 */
	protected static function page_selected_choices( $search, $page, $per_page ) {
		$ids = self::filter_choice_ids( BOGO_Select_Settings::get( 'get_products' ) );

		if ( '' !== $search ) {
			$ids = array_values(
				array_filter(
					$ids,
					function ( $product_id ) use ( $search ) {
						return BOGO_Select_Engine::matches_search( $product_id, $search );
					}
				)
			);
		}

		$total = count( $ids );
		$pages = (int) max( 1, ceil( $total / $per_page ) );
		$page  = min( $page, $pages );

		return array(
			'ids'   => array_slice( $ids, ( $page - 1 ) * $per_page, $per_page ),
			'page'  => $page,
			'pages' => $pages,
			'total' => $total,
		);
	}

	/**
	 * Page the whole catalogue.
	 *
	 * @param string $search   Search term.
	 * @param int    $page     Page number.
	 * @param int    $per_page Page size.
	 * @return array
	 */
	protected static function page_all_choices( $search, $page, $per_page ) {
		$query_args = array(
			'status'   => 'publish',
			'type'     => 'simple',
			'limit'    => $per_page,
			'page'     => $page,
			'orderby'  => 'title',
			'order'    => 'ASC',
			'return'   => 'ids',
			'paginate' => true,
		);

		if ( '' !== $search ) {
			$query_args['s'] = $search;
		}

		$results = wc_get_products( $query_args );

		$ids   = isset( $results->products ) ? (array) $results->products : (array) $results;
		$total = isset( $results->total ) ? (int) $results->total : count( $ids );
		$pages = isset( $results->max_num_pages ) ? (int) $results->max_num_pages : 1;
		$pages = max( 1, $pages );

		return array(
			'ids'   => self::filter_choice_ids( $ids ),
			'page'  => min( $page, $pages ),
			'pages' => $pages,
			'total' => $total,
		);
	}

	/**
	 * Whether a product's name or SKU contains the search term.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $search     Search term.
	 * @return bool
	 */
	public static function matches_search( $product_id, $search ) {
		$product = wc_get_product( (int) $product_id );

		if ( ! $product ) {
			return false;
		}

		$haystack = $product->get_name() . ' ' . $product->get_sku();

		return false !== stripos( $haystack, $search );
	}

	/**
	 * Normalise, filter, and eligibility-check a list of candidate gift IDs.
	 *
	 * @param mixed $ids Raw ID list.
	 * @return int[]
	 */
	protected static function filter_choice_ids( $ids ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );

		/**
		 * Filter the products offered in the gift chooser.
		 *
		 * Applied per page of results, so a callback that appends IDs appends
		 * them to every page.
		 *
		 * @param int[] $ids Product IDs.
		 */
		$ids = (array) apply_filters( 'bogo_select_get_products', $ids );

		return array_values(
			array_filter(
				array_map( 'absint', $ids ),
				array( __CLASS__, 'is_get_eligible' )
			)
		);
	}

	/**
	 * Whether a cart item is a BOGO gift line.
	 *
	 * @param array $cart_item Cart item.
	 * @return bool
	 */
	public static function is_reward_item( $cart_item ) {
		return is_array( $cart_item ) && ! empty( $cart_item[ self::FLAG ] );
	}

	/**
	 * Every cart item key flagged as a gift line.
	 *
	 * Normally there is at most one (BRIEF.md §4.3). More than one means the
	 * session has drifted and needs normalising — see BOGO_Select_Cart::validate().
	 *
	 * @param WC_Cart|null $cart Cart to inspect.
	 * @return string[]
	 */
	public static function find_reward_keys( $cart = null ) {
		$cart = self::resolve_cart( $cart );

		if ( ! $cart ) {
			return array();
		}

		$keys = array();

		foreach ( $cart->get_cart() as $key => $cart_item ) {
			if ( self::is_reward_item( $cart_item ) ) {
				$keys[] = $key;
			}
		}

		return $keys;
	}

	/**
	 * The cart item key of the current gift line, if there is one.
	 *
	 * @param WC_Cart|null $cart Cart to inspect.
	 * @return string Empty string when no gift is selected.
	 */
	public static function find_reward_key( $cart = null ) {
		$keys = self::find_reward_keys( $cart );

		return $keys ? $keys[0] : '';
	}

	/**
	 * The product ID of the currently selected gift, if any.
	 *
	 * @param WC_Cart|null $cart Cart to inspect.
	 * @return int Zero when no gift is selected.
	 */
	public static function selected_product_id( $cart = null ) {
		$cart = self::resolve_cart( $cart );
		$key  = self::find_reward_key( $cart );

		if ( ! $key ) {
			return 0;
		}

		$cart_item = $cart->get_cart_item( $key );

		return $cart_item ? (int) $cart_item['product_id'] : 0;
	}

	/**
	 * Resolve a cart argument to a usable cart object.
	 *
	 * @param WC_Cart|null $cart Cart or null.
	 * @return WC_Cart|null
	 */
	protected static function resolve_cart( $cart = null ) {
		if ( $cart instanceof WC_Cart ) {
			return $cart;
		}

		return function_exists( 'WC' ) && WC()->cart ? WC()->cart : null;
	}
}
