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
	 * Per-request memo of which configured gift IDs are eligible.
	 *
	 * @var array<int,bool>|null
	 */
	protected static $eligibility = null;

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
	 * How much of the reward's price the customer still pays.
	 *
	 * 0.0 gives the item away, 0.5 halves it, 1.0 charges full price.
	 *
	 * @return float Between 0.0 and 1.0.
	 */
	public static function discount_factor() {
		if ( 'percent' !== BOGO_Select_Settings::get( 'get_discount_type' ) ) {
			return 0.0;
		}

		$percent = (float) BOGO_Select_Settings::get( 'get_discount_value' );

		// Settings clamp this already; clamped again because a filter or a
		// hand-edited option row can reach here without passing through them.
		return max( 0.0, min( 1.0, 1 - ( $percent / 100 ) ) );
	}

	/**
	 * What one unit of the reward costs.
	 *
	 * Rounded per unit, because WooCommerce multiplies a unit price by the
	 * quantity everywhere else. Rounding here and nowhere else keeps the line
	 * total, the price shown in the chooser, and the order from disagreeing by a
	 * penny on a quantity of eight.
	 *
	 * @param float $base Undiscounted unit price.
	 * @return float
	 */
	public static function reward_price( $base ) {
		$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;

		return round( max( 0.0, (float) $base * self::discount_factor() ), $decimals );
	}

	/**
	 * Whether the reward costs the customer nothing.
	 *
	 * True for the 'free' type and for a percentage of 100, so the display layer
	 * can say "Free" without comparing floats itself.
	 *
	 * @return bool
	 */
	public static function is_free_reward() {
		return self::discount_factor() <= 0.0;
	}

	/*
	 * How the offer is worded.
	 *
	 * Every customer-facing string that used to hardcode "free" asks one of these
	 * instead, so changing the vocabulary is one edit rather than twenty. The
	 * free wording is byte-identical to what shipped before discounts existed —
	 * an unconfigured store reads exactly as it always did.
	 *
	 * Note that these return strings containing a literal "%" and so must be
	 * passed to sprintf() as arguments, never used as a format.
	 */

	/**
	 * What the reward costs, as a label. "Free" or "50% off".
	 *
	 * @return string
	 */
	public static function reward_label() {
		if ( self::is_free_reward() ) {
			return __( 'Free', 'bogo-select' );
		}

		return sprintf(
			/* translators: %s: a discount percentage, already formatted, e.g. "50". */
			__( '%s%% off', 'bogo-select' ),
			self::format_percent( BOGO_Select_Settings::get( 'get_discount_value' ) )
		);
	}

	/**
	 * The same thing mid-sentence. "free" or "at 50% off".
	 *
	 * @return string
	 */
	public static function reward_phrase() {
		if ( self::is_free_reward() ) {
			return __( 'free', 'bogo-select' );
		}

		return sprintf(
			/* translators: %s: a discount percentage, already formatted, e.g. "50". */
			__( 'at %s%% off', 'bogo-select' ),
			self::format_percent( BOGO_Select_Settings::get( 'get_discount_value' ) )
		);
	}

	/**
	 * What to call the reward itself. "free gift" or "discounted item".
	 *
	 * @return string
	 */
	public static function reward_noun() {
		return self::is_free_reward()
			? __( 'free gift', 'bogo-select' )
			: __( 'discounted item', 'bogo-select' );
	}

	/**
	 * How to describe the reward's units. "free" or "discounted".
	 *
	 * Distinct from reward_phrase(), which reads "at 50% off" and cannot sit in
	 * front of a noun.
	 *
	 * @return string
	 */
	public static function reward_adjective() {
		return self::is_free_reward()
			? __( 'free', 'bogo-select' )
			: __( 'discounted', 'bogo-select' );
	}

	/**
	 * The label and value that mark the reward on an order line and in the blocks.
	 *
	 * Both places show the customer the same row, so both ask here.
	 *
	 * @return array{label:string,value:string}
	 */
	public static function reward_meta() {
		if ( self::is_free_reward() ) {
			return array(
				'label' => __( 'Free gift', 'bogo-select' ),
				'value' => __( 'BOGO promotion', 'bogo-select' ),
			);
		}

		return array(
			'label' => __( 'Discounted item', 'bogo-select' ),
			'value' => sprintf(
				/* translators: %s: what the reward costs, e.g. "50% off". */
				__( '%s — BOGO promotion', 'bogo-select' ),
				self::reward_label()
			),
		);
	}

	/**
	 * The offer's pricing, in a form an order can store and a report can parse.
	 *
	 * "free", or "percent:50". Written to each reward order line so the order
	 * still explains itself after the settings move on.
	 *
	 * Reads the configured type rather than is_free_reward(), which is a question
	 * about wording: it answers true for a percentage of 100, so asking it here
	 * would record an explicit 100%-off campaign as `free` and leave reports
	 * unable to tell one from the other. A 100% offer still *reads* as "Free"
	 * everywhere the customer sees it (`CODEX-REVIEW.md` L-01).
	 *
	 * @return string
	 */
	public static function discount_snapshot() {
		if ( 'percent' !== BOGO_Select_Settings::get( 'get_discount_type' ) ) {
			return 'free';
		}

		// Cast through float so 50.00 stores as "50" and 12.50 as "12.5".
		return 'percent:' . (float) BOGO_Select_Settings::get( 'get_discount_value' );
	}

	/**
	 * A percentage with only the decimal places it needs.
	 *
	 * 50 reads as "50", not "50.00"; 12.5 keeps its half.
	 *
	 * @param mixed $percent Raw percentage.
	 * @return string
	 */
	protected static function format_percent( $percent ) {
		$percent  = (float) $percent;
		$decimals = 0;

		if ( round( $percent, 2 ) !== round( $percent ) ) {
			$decimals = round( $percent, 2 ) === round( $percent, 1 ) ? 1 : 2;
		}

		return number_format_i18n( $percent, $decimals );
	}

	/*
	 * Eligibility asks two questions, and they come apart once variations exist.
	 *
	 * is_choice()    — may this appear as a card in the chooser?
	 * is_awardable() — may this exact thing go into the cart?
	 *
	 * A variable parent is the first and never the second: it is offered, but
	 * what the customer receives is one of its variations. One function answered
	 * both while every reward was a simple product, and could not once they are
	 * not (PLAN-VARIABLE.md §4).
	 *
	 * Neither says anything about stock. Use self::unavailable_reason() for that.
	 */

	/**
	 * Whether a product may be offered as a reward at all.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	public static function is_choice( $product_id ) {
		$product_id = (int) $product_id;
		$product    = wc_get_product( $product_id );

		if ( ! $product || ! self::is_offerable_type( $product ) ) {
			return false;
		}

		if ( ! self::in_get_scope( $product_id, $product ) ) {
			return false;
		}

		// A variable product is offerable only through its variations, so it is a
		// card only while at least one of them can actually be given.
		if ( $product->is_type( 'variable' ) ) {
			return (bool) self::offerable_variation_ids( $product );
		}

		if ( $product->is_type( 'variation' ) ) {
			return self::is_offerable_variation( $product );
		}

		return $product->is_purchasable();
	}

	/**
	 * Whether this exact product, or product and variation, may be awarded.
	 *
	 * @param int $product_id   Product ID. For a variation, its parent.
	 * @param int $variation_id Variation ID, or 0.
	 * @return bool
	 */
	public static function is_awardable( $product_id, $variation_id = 0 ) {
		$product_id   = (int) $product_id;
		$variation_id = (int) $variation_id;

		if ( $variation_id ) {
			$variation = wc_get_product( $variation_id );

			if ( ! self::is_offerable_variation( $variation ) ) {
				return false;
			}

			/*
			 * The caller names both halves of the pair, and the browser is a
			 * caller. Without this, naming an in-scope parent alongside any
			 * variation ID in the catalogue would award that variation.
			 */
			if ( $product_id && (int) $variation->get_parent_id() !== $product_id ) {
				return false;
			}

			return self::variation_in_scope( $variation );
		}

		$product = wc_get_product( $product_id );

		if ( ! $product || ! $product->is_purchasable() || ! self::is_offerable_type( $product ) ) {
			return false;
		}

		// Named without a variation, a variable product says which product but not
		// which thing. Its variations are awardable; it is not.
		if ( $product->is_type( 'variable' ) ) {
			return false;
		}

		// A variation named on its own, rather than beside its parent.
		if ( $product->is_type( 'variation' ) ) {
			return self::is_offerable_variation( $product ) && self::variation_in_scope( $product );
		}

		return self::in_get_scope( $product_id, $product );
	}

	/**
	 * Whether a product may be offered as a gift.
	 *
	 * Retained because the cart, the selection endpoint, and the chooser filters
	 * all ask it of simple products, where it means exactly what it always did.
	 *
	 * @param int $product_id   Product ID.
	 * @param int $variation_id Variation ID, or 0.
	 * @return bool
	 */
	public static function is_get_eligible( $product_id, $variation_id = 0 ) {
		return self::is_awardable( $product_id, $variation_id );
	}

	/**
	 * Settle a reward into the pair the cart stores it as.
	 *
	 * Callers name a reward in whichever way they hold it: a chooser card sends a
	 * parent and a variation, a curated settings list holds bare IDs, and a cart
	 * line already carries both. A bare variation ID is resolved to its parent
	 * here, once, so that nothing downstream has to guess — passing a variation
	 * to add_to_cart() as a product would build a line whose product_id is a
	 * variation and whose variation_id is zero.
	 *
	 * @param int $product_id   Product ID, or a variation's own ID.
	 * @param int $variation_id Variation ID, or 0.
	 * @return array{0:int,1:int} Parent ID, then variation ID.
	 */
	public static function reward_pair( $product_id, $variation_id = 0 ) {
		$product_id   = absint( $product_id );
		$variation_id = absint( $variation_id );

		if ( $variation_id ) {
			return array( $product_id, $variation_id );
		}

		$product = wc_get_product( $product_id );

		if ( $product && $product->is_type( 'variation' ) ) {
			return array( (int) $product->get_parent_id(), $product_id );
		}

		return array( $product_id, 0 );
	}

	/**
	 * The product a reward pair actually refers to.
	 *
	 * The variation where there is one, since that is what carries the price, the
	 * name, and the stock record.
	 *
	 * @param int $product_id   Parent ID.
	 * @param int $variation_id Variation ID, or 0.
	 * @return WC_Product|false
	 */
	public static function reward_product( $product_id, $variation_id = 0 ) {
		return wc_get_product( $variation_id ? $variation_id : $product_id );
	}

	/**
	 * Whether a product's type can be a reward at all.
	 *
	 * Grouped and external products still cannot: a grouped product is a listing
	 * rather than a thing, and an external one is bought somewhere else. D-006's
	 * reasoning survives for both, and only its rejection of variable products
	 * and variations is being answered here.
	 *
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	protected static function is_offerable_type( $product ) {
		return ! $product->is_type( 'grouped' ) && ! $product->is_type( 'external' );
	}

	/**
	 * Whether a product is inside the configured Get scope.
	 *
	 * Variations are never enumerated by scope — they reach the chooser only by
	 * being listed individually, which can only happen in Select Products.
	 *
	 * @param int        $product_id Product ID.
	 * @param WC_Product $product    Product.
	 * @return bool
	 */
	protected static function in_get_scope( $product_id, $product ) {
		if ( 'select' === BOGO_Select_Settings::get( 'get_scope' ) ) {
			return in_array( (int) $product_id, BOGO_Select_Settings::get( 'get_products' ), true );
		}

		if ( $product->is_type( 'variation' ) ) {
			return false;
		}

		return 'publish' === $product->get_status();
	}

	/**
	 * Whether a variation is in scope, through its own ID or its parent's.
	 *
	 * @param WC_Product $variation Variation.
	 * @return bool
	 */
	protected static function variation_in_scope( $variation ) {
		$parent_id = (int) $variation->get_parent_id();

		if ( 'select' === BOGO_Select_Settings::get( 'get_scope' ) ) {
			$allowed = BOGO_Select_Settings::get( 'get_products' );

			return in_array( (int) $variation->get_id(), $allowed, true )
				|| ( $parent_id && in_array( $parent_id, $allowed, true ) );
		}

		$parent = $parent_id ? wc_get_product( $parent_id ) : false;

		return $parent && 'publish' === $parent->get_status();
	}

	/**
	 * Whether a variation can be given as it stands.
	 *
	 * @param mixed $variation Variation, or anything else.
	 * @return bool
	 */
	protected static function is_offerable_variation( $variation ) {
		if ( ! $variation instanceof WC_Product || ! $variation->is_type( 'variation' ) ) {
			return false;
		}

		// A variation is added to the cart as its parent plus itself, so one
		// without a parent cannot be added at all.
		if ( ! $variation->get_parent_id() ) {
			return false;
		}

		if ( ! $variation->is_purchasable() ) {
			return false;
		}

		return ! self::has_any_attribute( $variation );
	}

	/**
	 * Whether a variation leaves an attribute open.
	 *
	 * WooCommerce spells "any size" as an empty attribute value rather than by
	 * omitting the attribute, so such a variation still needs a choice made
	 * before it can be added — the ambiguity D-006 objected to, and the reason
	 * these are not offered (PLAN-VARIABLE.md §1).
	 *
	 * @param WC_Product $variation Variation.
	 * @return bool
	 */
	protected static function has_any_attribute( $variation ) {
		foreach ( $variation->get_variation_attributes() as $value ) {
			if ( '' === (string) $value ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * The product types the chooser enumerates.
	 *
	 * Variations are absent on purpose: a variable parent stands for all of its
	 * variations as one card, and enumerating them separately would turn fifty
	 * variable products into a thousand (PLAN-VARIABLE.md §1). A variation
	 * reaches the chooser only by being listed individually.
	 *
	 * @return string[]
	 */
	protected static function offerable_types() {
		return array( 'simple', 'variable' );
	}

	/**
	 * Why a product could never be offered as a reward, whatever the scope.
	 *
	 * Scope-blind on purpose: the settings screen asks this while saving the very
	 * list that decides scope, so consulting the saved list would judge the new
	 * selection against the old one. Sits beside unavailable_reason(), which
	 * answers the same shape of question about stock.
	 *
	 * @param int $product_id Product ID.
	 * @return string Empty when the product can be offered.
	 */
	public static function unofferable_reason( $product_id ) {
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return __( 'the product no longer exists', 'bogo-select' );
		}

		if ( ! self::is_offerable_type( $product ) ) {
			return __( 'grouped and external products cannot be given as rewards', 'bogo-select' );
		}

		if ( $product->is_type( 'variable' ) ) {
			return self::offerable_variation_ids( $product )
				? ''
				: __( 'a variable product needs at least one variation that can be given', 'bogo-select' );
		}

		if ( $product->is_type( 'variation' ) ) {
			if ( ! $product->get_parent_id() ) {
				return __( 'a variation with no parent product cannot be added to a cart', 'bogo-select' );
			}

			if ( self::has_any_attribute( $product ) ) {
				return __( 'a variation that leaves an option set to "Any" would still need a choice', 'bogo-select' );
			}
		}

		return $product->is_purchasable() ? '' : __( 'the product cannot be purchased', 'bogo-select' );
	}

	/**
	 * The variations of a parent that could be given.
	 *
	 * Reads each child directly rather than through
	 * WC_Product_Variable::get_available_variations(), which builds a large array
	 * per variation including image and attribute data. A page of variable cards
	 * calls this once each (PLAN-VARIABLE.md §5).
	 *
	 * @param WC_Product $parent Variable parent.
	 * @return int[]
	 */
	public static function offerable_variation_ids( $parent ) {
		if ( ! $parent instanceof WC_Product ) {
			return array();
		}

		$ids = array();

		foreach ( $parent->get_children() as $child_id ) {
			if ( self::is_offerable_variation( wc_get_product( $child_id ) ) ) {
				$ids[] = (int) $child_id;
			}
		}

		return $ids;
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
					/* translators: 1: number of units required, 2: how they are described, 3: units of the same product already in the cart. */
					__( 'Not enough stock for %1$d %2$s units alongside the %3$d already in your cart', 'bogo-select' ),
					$qty,
					self::reward_adjective(),
					$other_demand
				);
			}

			return sprintf(
				/* translators: 1: number of units required, 2: how they are described. */
				__( 'Not enough stock for %1$d %2$s units', 'bogo-select' ),
				$qty,
				self::reward_adjective()
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
	 * How many products a gift search may inspect before it stops looking.
	 *
	 * Search runs over the whole catalogue, so it needs a ceiling of its own —
	 * page size bounds what is rendered, not what is examined.
	 *
	 * The ceiling is a real limit on completeness, not just on cost: a search
	 * reports the eligible products among the first 200 matches, so a broad
	 * term on a large catalogue can omit later matches entirely and say nothing
	 * about having done so. That is the accepted trade-off (CODEX-REVIEW.md
	 * L-02), documented in README.md. Raise it from measured catalogue data if
	 * a store needs deeper searches and can afford them.
	 *
	 * @return int
	 */
	public static function search_limit() {
		/**
		 * Filter how many matching products a gift search considers.
		 *
		 * Raising this makes deep searches more thorough and more expensive.
		 *
		 * @param int $limit Maximum candidates inspected.
		 */
		return max( 1, (int) apply_filters( 'bogo_select_search_limit', 200 ) );
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
		$configured = array_values( array_unique( array_filter( array_map( 'absint', (array) BOGO_Select_Settings::get( 'get_products' ) ) ) ) );

		if ( '' !== $search ) {
			// The search runs in the database, constrained to the configured
			// list, rather than loading every configured product to compare it
			// in PHP.
			$matched = self::search_product_ids( $search, self::search_limit(), $configured );
			$ids     = array_values( array_intersect( $configured, $matched ) );
		} else {
			$ids = $configured;
		}

		$context = array(
			'scope'    => 'select',
			'search'   => $search,
			'page'     => $page,
			'per_page' => $per_page,
		);

		$ids   = self::filter_choice_ids( $ids, $context );
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
	 * Browsing pages one catalogue query at a time; searching resolves the
	 * matches first (bounded by self::search_limit()) and pages the result.
	 *
	 * The two halves report totals of different things, deliberately:
	 *
	 * - Searching filters for eligibility before paging, so "total" and "pages"
	 *   count selectable gifts exactly.
	 * - Browsing pages the catalogue and filters each page afterwards, so
	 *   "total" and "pages" are WooCommerce's pre-eligibility catalogue counts.
	 *   The count can overstate what is selectable, and a page can come back
	 *   short or empty while eligible products remain on later pages.
	 *
	 * Making browse counts exact means counting an eligibility-filtered
	 * candidate set, which is the O(catalogue) query paging exists to avoid.
	 * The inexact count is the accepted price of paging (CODEX-REVIEW.md M-03);
	 * the limitation is documented in README.md. Stores that need an exact
	 * count should curate a list with the "Select Products" scope, which pages
	 * a filtered set and counts it exactly.
	 *
	 * @param string $search   Search term.
	 * @param int    $page     Page number.
	 * @param int    $per_page Page size.
	 * @return array
	 */
	protected static function page_all_choices( $search, $page, $per_page ) {
		$context = array(
			'scope'    => 'all',
			'search'   => $search,
			'page'     => $page,
			'per_page' => $per_page,
		);

		if ( '' !== $search ) {
			$ids = self::filter_choice_ids( self::search_product_ids( $search, self::search_limit() ), $context );
			$ids = self::sort_by_name( $ids );

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

		$results = wc_get_products(
			array(
				'status'   => 'publish',
				'type'     => self::offerable_types(),
				'limit'    => $per_page,
				'page'     => $page,
				'orderby'  => 'title',
				'order'    => 'ASC',
				'return'   => 'ids',
				'paginate' => true,
			)
		);

		$ids   = isset( $results->products ) ? (array) $results->products : (array) $results;
		$total = isset( $results->total ) ? (int) $results->total : count( $ids );
		$pages = isset( $results->max_num_pages ) ? (int) $results->max_num_pages : 1;
		$pages = max( 1, $pages );

		return array(
			'ids'   => self::filter_choice_ids( $ids, $context ),
			'page'  => min( $page, $pages ),
			'pages' => $pages,
			'total' => $total,
		);
	}

	/**
	 * Product IDs matching a search term by name, description, or SKU.
	 *
	 * WooCommerce's product data store owns "search products the way the admin
	 * product search does", which covers the SKU. WP_Query's `s` does not look
	 * at SKUs at all, so it cannot be used on its own here.
	 *
	 * @param string $search  Search term.
	 * @param int    $limit   Maximum matches to return.
	 * @param int[]  $include Optional list to constrain the search to.
	 * @return int[]
	 */
	public static function search_product_ids( $search, $limit, $include = array() ) {
		$search  = trim( (string) $search );
		$limit   = max( 1, (int) $limit );
		$include = array_values( array_unique( array_filter( array_map( 'absint', (array) $include ) ) ) );

		if ( '' === $search ) {
			return array();
		}

		$ids = self::store_search( $search, $limit, $include );

		if ( null === $ids ) {
			$ids = self::query_search( $search, $limit, $include );
		}

		/**
		 * Filter the raw search matches before eligibility filtering.
		 *
		 * @param int[]  $ids     Matching product IDs.
		 * @param string $search  Search term.
		 * @param int[]  $include IDs the search was constrained to, if any.
		 */
		$ids = (array) apply_filters( 'bogo_select_search_results', $ids, $search, $include );

		return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	}

	/**
	 * Search through WooCommerce's product data store.
	 *
	 * This is the same call the admin product search uses; it matches title,
	 * excerpt, description, and SKU, and it goes through the data store rather
	 * than assuming products are posts.
	 *
	 * @param string $search  Search term.
	 * @param int    $limit   Maximum matches.
	 * @param int[]  $include Optional constraint list.
	 * @return int[]|null Null when the data store cannot answer.
	 */
	protected static function store_search( $search, $limit, $include ) {
		if ( ! class_exists( 'WC_Data_Store' ) ) {
			return null;
		}

		try {
			$store = WC_Data_Store::load( 'product' );
		} catch ( Exception $e ) {
			return null;
		}

		if ( ! is_object( $store ) || ! method_exists( $store, 'search_products' ) ) {
			return null;
		}

		/*
		 * Signature: term, type, include_variations, all_statuses, limit,
		 * include, exclude. Older signatures simply ignore the trailing
		 * arguments, and the caller intersects with $include regardless.
		 *
		 * Variations are searched only when the search is constrained to a
		 * curated list, which is the only way a variation reaches the chooser.
		 * Without this a store that pinned "Tee - Small" could never find it by
		 * typing its name. In the All Products scope the flag stays off, so a
		 * search there returns parents rather than every size of everything.
		 */
		$ids = $store->search_products( $search, '', (bool) $include, false, $limit, $include ? $include : null, null );

		return array_map( 'absint', (array) $ids );
	}

	/**
	 * Search with product queries when the data store is unavailable.
	 *
	 * Two queries, because WooCommerce matches SKUs through `sku` and keywords
	 * through the post search; neither one covers both.
	 *
	 * @param string $search  Search term.
	 * @param int    $limit   Maximum matches.
	 * @param int[]  $include Optional constraint list.
	 * @return int[]
	 */
	protected static function query_search( $search, $limit, $include ) {
		$base = array(
			'status' => 'publish',
			'type'   => self::offerable_types(),
			'limit'  => $limit,
			'return' => 'ids',
		);

		if ( $include ) {
			$base['include'] = $include;
		}

		$by_sku     = (array) wc_get_products( array_merge( $base, array( 'sku' => $search ) ) );
		$by_keyword = (array) wc_get_products( array_merge( $base, array( 's' => $search ) ) );

		return array_merge( $by_keyword, $by_sku );
	}

	/**
	 * Order product IDs by product name.
	 *
	 * @param int[] $ids Product IDs.
	 * @return int[]
	 */
	protected static function sort_by_name( $ids ) {
		$ids   = array_values( (array) $ids );
		$names = array();

		foreach ( $ids as $id ) {
			$product      = wc_get_product( $id );
			$names[ $id ] = $product ? $product->get_name() : '';
		}

		usort(
			$ids,
			function ( $a, $b ) use ( $names ) {
				return strcasecmp( $names[ $a ], $names[ $b ] );
			}
		);

		return $ids;
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
	 * @param mixed $ids     Raw ID list.
	 * @param array $context Page context: scope, search, page, per_page.
	 * @return int[]
	 */
	protected static function filter_choice_ids( $ids, $context = array() ) {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );

		$context = wp_parse_args(
			$context,
			array(
				'scope'    => BOGO_Select_Settings::get( 'get_scope' ),
				'search'   => '',
				'page'     => 1,
				'per_page' => self::choices_per_page(),
			)
		);

		/**
		 * Filter the products offered in the gift chooser.
		 *
		 * Applied per page of results, so a callback that appends IDs appends
		 * them to every page. Callbacks that need to know which page they are
		 * looking at should use bogo_select_choice_ids instead.
		 *
		 * @param int[] $ids Product IDs.
		 */
		$ids = (array) apply_filters( 'bogo_select_get_products', $ids );

		/**
		 * Filter one page of gift options, with the context that produced it.
		 *
		 * Unlike bogo_select_get_products, this one says which scope, search
		 * term, page, and page size are in play, so a callback can act on the
		 * first page only, leave searches alone, and so on.
		 *
		 * @param int[] $ids     Product IDs for this page.
		 * @param array $context Scope, search, page, and per_page.
		 */
		$ids = (array) apply_filters( 'bogo_select_choice_ids', $ids, $context );

		return self::eligible_only( array_map( 'absint', $ids ) );
	}

	/**
	 * Keep only the IDs that may be offered as gifts.
	 *
	 * Eligibility for the configured "Select Products" list is cached, because
	 * it depends on product state rather than on the request — a long curated
	 * list would otherwise be loaded product by product on every page view and
	 * every search keystroke.
	 *
	 * @param int[] $ids Candidate IDs.
	 * @return int[]
	 */
	protected static function eligible_only( $ids ) {
		$known = self::eligibility_map();
		$out   = array();

		foreach ( (array) $ids as $id ) {
			$id = (int) $id;

			if ( ! $id ) {
				continue;
			}

			if ( array_key_exists( $id, $known ) ) {
				if ( $known[ $id ] ) {
					$out[] = $id;
				}

				continue;
			}

			if ( self::is_choice( $id ) ) {
				$out[] = $id;
			}
		}

		return array_values( array_unique( $out ) );
	}

	/**
	 * Cached eligibility of every configured gift product.
	 *
	 * Empty for the "All Products" scope, where a page is already bounded by
	 * the catalogue query.
	 *
	 * @return array<int,bool>
	 */
	protected static function eligibility_map() {
		if ( null !== self::$eligibility ) {
			return self::$eligibility;
		}

		self::$eligibility = array();

		if ( 'select' !== BOGO_Select_Settings::get( 'get_scope' ) ) {
			return self::$eligibility;
		}

		$configured = array_values( array_unique( array_filter( array_map( 'absint', (array) BOGO_Select_Settings::get( 'get_products' ) ) ) ) );

		if ( ! $configured ) {
			return self::$eligibility;
		}

		$key    = self::eligibility_key( $configured );
		$cached = function_exists( 'get_transient' ) ? get_transient( $key ) : false;

		if ( is_array( $cached ) ) {
			self::$eligibility = array_map( 'boolval', $cached );

			return self::$eligibility;
		}

		foreach ( $configured as $id ) {
			self::$eligibility[ $id ] = self::is_choice( $id );
		}

		if ( function_exists( 'set_transient' ) ) {
			/**
			 * Filter how long gift eligibility stays cached, in seconds.
			 *
			 * The cache is also cleared when the settings or any product are
			 * saved; this is the ceiling, not the only refresh.
			 *
			 * @param int $ttl Seconds.
			 */
			$ttl = (int) apply_filters( 'bogo_select_eligibility_ttl', 600 );

			if ( $ttl > 0 ) {
				set_transient( $key, self::$eligibility, $ttl );
			}
		}

		return self::$eligibility;
	}

	/**
	 * Transient key for a configured gift list.
	 *
	 * @param int[] $configured Configured IDs.
	 * @return string
	 */
	protected static function eligibility_key( $configured ) {
		return 'bogo_select_eligible_' . md5( implode( ',', $configured ) );
	}

	/**
	 * Forget cached eligibility.
	 *
	 * Called when the settings change and when any product is saved or
	 * deleted, so a gift that stops being purchasable leaves the chooser
	 * without waiting for the cache to expire.
	 */
	public static function flush_choice_cache() {
		self::$eligibility = null;

		if ( ! function_exists( 'delete_transient' ) ) {
			return;
		}

		$configured = array_values( array_unique( array_filter( array_map( 'absint', (array) BOGO_Select_Settings::get( 'get_products' ) ) ) ) );

		if ( $configured ) {
			delete_transient( self::eligibility_key( $configured ) );
		}
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
		$cart_item = self::selected_reward_item( $cart );

		return $cart_item ? (int) $cart_item['product_id'] : 0;
	}

	/**
	 * The variation ID of the currently selected reward, if it is a variation.
	 *
	 * Sits beside selected_product_id() rather than replacing it: for a variation
	 * line the cart's product_id is the parent, so the two together name the
	 * reward and neither does on its own.
	 *
	 * @param WC_Cart|null $cart Cart to inspect.
	 * @return int Zero when no reward is selected, or it is not a variation.
	 */
	public static function selected_variation_id( $cart = null ) {
		$cart_item = self::selected_reward_item( $cart );

		return $cart_item && ! empty( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0;
	}

	/**
	 * The cart line holding the current reward.
	 *
	 * @param WC_Cart|null $cart Cart to inspect.
	 * @return array|null
	 */
	protected static function selected_reward_item( $cart = null ) {
		$cart = self::resolve_cart( $cart );
		$key  = self::find_reward_key( $cart );

		if ( ! $key ) {
			return null;
		}

		$cart_item = $cart->get_cart_item( $key );

		return $cart_item ? $cart_item : null;
	}

	/**
	 * Everything the chooser needs to know about the cart's offer state.
	 *
	 * Exposed through the Store API on block carts and returned by the AJAX
	 * endpoints, so the front end can tell — without re-rendering anything —
	 * whether the cart has crossed the qualifying threshold, changed the number
	 * of free units, or had its gift removed elsewhere on the page.
	 *
	 * @param WC_Cart|null $cart Cart to inspect.
	 * @return array
	 */
	public static function state( $cart = null ) {
		$cart   = self::resolve_cart( $cart );
		$reward = self::reward_quantity_for_cart( $cart );

		$state = array(
			'active'                => self::is_active(),
			'qualifies'             => $reward > 0,
			'reward_quantity'       => $reward,
			'selected_product_id'   => self::selected_product_id( $cart ),
			'selected_variation_id' => self::selected_variation_id( $cart ),
		);

		// Other lines matter too: they compete for the same stock, so a gift
		// that was available a moment ago may not be now.
		$lines = array();

		if ( $cart ) {
			foreach ( $cart->get_cart() as $cart_item ) {
				$lines[] = (int) $cart_item['product_id']
					. ':' . ( isset( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0 )
					. ':' . (int) $cart_item['quantity'];
			}
		}

		sort( $lines );

		$state['signature'] = md5(
			implode(
				'|',
				array_merge(
					array(
						$state['active'] ? '1' : '0',
						(string) $state['reward_quantity'],
						(string) $state['selected_product_id'],
						(string) $state['selected_variation_id'],
					),
					$lines
				)
			)
		);

		return $state;
	}

	/**
	 * A hash that changes whenever the chooser would render differently.
	 *
	 * @param WC_Cart|null $cart Cart to inspect.
	 * @return string
	 */
	public static function state_signature( $cart = null ) {
		$state = self::state( $cart );

		return $state['signature'];
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
