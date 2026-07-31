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

		// Variable parents are ambiguous as gifts (DECISION.md D-006).
		if ( $product->is_type( 'variable' ) || $product->is_type( 'grouped' ) || $product->is_type( 'external' ) ) {
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
	 * @param WC_Product $product Product to check.
	 * @param int        $qty     Quantity to award.
	 * @return string Empty string when it can be awarded.
	 */
	public static function unavailable_reason( $product, $qty ) {
		if ( ! $product instanceof WC_Product ) {
			return __( 'This product is no longer available.', 'bogo-select' );
		}

		if ( ! $product->is_in_stock() ) {
			return __( 'Out of stock', 'bogo-select' );
		}

		if ( $product->managing_stock() && ! $product->backorders_allowed() && ! $product->has_enough_stock( $qty ) ) {
			return sprintf(
				/* translators: %d: number of units required. */
				__( 'Not enough stock for %d free units', 'bogo-select' ),
				(int) $qty
			);
		}

		if ( $product->is_sold_individually() && $qty > 1 ) {
			return __( 'Limited to one per order', 'bogo-select' );
		}

		return '';
	}

	/**
	 * Product IDs to show in the chooser.
	 *
	 * @return int[]
	 */
	public static function get_choice_ids() {
		if ( 'select' === BOGO_Select_Settings::get( 'get_scope' ) ) {
			$ids = BOGO_Select_Settings::get( 'get_products' );
		} else {
			/**
			 * Filter how many products the "All Products" gift scope lists.
			 *
			 * @param int $limit Maximum products shown in the chooser.
			 */
			$limit = (int) apply_filters( 'bogo_select_all_products_limit', 50 );

			$ids = wc_get_products(
				array(
					'status'  => 'publish',
					'type'    => 'simple',
					'limit'   => $limit,
					'orderby' => 'title',
					'order'   => 'ASC',
					'return'  => 'ids',
				)
			);
		}

		$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );

		/**
		 * Filter the products offered in the gift chooser.
		 *
		 * @param int[] $ids Product IDs.
		 */
		$ids = (array) apply_filters( 'bogo_select_get_products', $ids );

		return array_values(
			array_filter(
				$ids,
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
	 * The cart item key of the current gift line, if there is one.
	 *
	 * @param WC_Cart|null $cart Cart to inspect.
	 * @return string Empty string when no gift is selected.
	 */
	public static function find_reward_key( $cart = null ) {
		$cart = self::resolve_cart( $cart );

		if ( ! $cart ) {
			return '';
		}

		foreach ( $cart->get_cart() as $key => $cart_item ) {
			if ( self::is_reward_item( $cart_item ) ) {
				return $key;
			}
		}

		return '';
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
