<?php
/**
 * Cart behaviour for gift line items.
 *
 * Forces the gift price to zero, locks its quantity, keeps it in sync with the
 * cart, and labels it through to the order.
 *
 * @package BOGO_Select
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hooks that govern the free gift line item.
 */
class BOGO_Select_Cart {

	/**
	 * Guard against re-entering validation while it mutates the cart.
	 *
	 * @var bool
	 */
	protected $validating = false;

	/**
	 * Nesting depth of deliberate validation suspensions.
	 *
	 * @var int
	 */
	protected static $suspended = 0;

	/**
	 * Register hooks.
	 */
	public function __construct() {
		// Price the gift at zero, after most third-party pricing filters.
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'set_reward_price' ), 20 );

		// Keep the gift honest.
		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'validate' ), 20 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'validate' ), 20 );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( $this, 'validate' ), 20 );
		add_action( 'woocommerce_cart_item_removed', array( $this, 'validate' ), 20 );
		add_action( 'woocommerce_cart_item_restored', array( $this, 'validate' ), 20 );
		add_action( 'woocommerce_add_to_cart', array( $this, 'validate' ), 20 );

		// Presentation.
		add_filter( 'woocommerce_cart_item_quantity', array( $this, 'lock_quantity' ), 10, 3 );
		add_filter( 'woocommerce_cart_item_name', array( $this, 'label_name' ), 10, 3 );
		add_filter( 'woocommerce_cart_item_price', array( $this, 'label_price' ), 10, 3 );
		add_filter( 'woocommerce_cart_item_subtotal', array( $this, 'label_subtotal' ), 10, 3 );

		// Carry the flag through to the order.
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'add_order_item_meta' ), 10, 4 );
	}

	/**
	 * Pause validation while a caller mutates the gift deliberately.
	 *
	 * Used by the choose endpoint, which must hold two gift lines briefly while
	 * it swaps one for the other. Always pair with self::resume().
	 */
	public static function suspend() {
		self::$suspended++;
	}

	/**
	 * Resume validation after a suspension.
	 */
	public static function resume() {
		self::$suspended = max( 0, self::$suspended - 1 );
	}

	/**
	 * Price every gift line at whatever the offer discounts it to.
	 *
	 * Zero for a free gift, which is the default and was the only behaviour
	 * before discounts existed.
	 *
	 * @param WC_Cart $cart Cart being calculated.
	 */
	public function set_reward_price( $cart ) {
		if ( ! $cart instanceof WC_Cart ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( ! BOGO_Select_Engine::is_reward_item( $cart_item ) ) {
				continue;
			}

			// A line whose product could not be loaded holds false here, and the
			// isset() this check replaced was true for false. WooCommerce drops such
			// lines when it builds the cart from the session, so this guards against
			// other code putting them there — the same check, for the same reason,
			// as BOGO_Select_Engine::stock_demand().
			if ( empty( $cart_item['data'] ) || ! $cart_item['data'] instanceof WC_Product ) {
				continue;
			}

			$cart_item['data']->set_price( BOGO_Select_Engine::reward_price( self::base_price( $cart_item ) ) );
		}
	}

	/**
	 * The undiscounted price a reward line is calculated from.
	 *
	 * Read from a product loaded fresh rather than from the line's own product
	 * object, because this method's caller has already written to that object and
	 * will be called again — WooCommerce recalculates totals more than once in
	 * some requests. Discounting a figure this code produced would compound it:
	 * half price, then a quarter, then an eighth. A fresh instance always holds
	 * the catalogue price, so the result is the same on every pass.
	 *
	 * The trade is that a price another plugin set on the cart item is discarded
	 * rather than discounted, even though the priority-20 hook ordering runs
	 * after such plugins (DECISION.md D-016).
	 *
	 * @param array $cart_item Cart item.
	 * @return float Zero when the product cannot be loaded, which leaves the line
	 *               free until the next validation pass removes it.
	 */
	protected static function base_price( $cart_item ) {
		$product = self::line_product( $cart_item );

		return $product ? (float) $product->get_price() : 0.0;
	}

	/**
	 * The catalogue product behind a cart line.
	 *
	 * The variation where there is one, since that is what carries the price.
	 *
	 * @param array $cart_item Cart item.
	 * @return WC_Product|false
	 */
	protected static function line_product( $cart_item ) {
		$id = ! empty( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : (int) $cart_item['product_id'];

		return wc_get_product( $id );
	}

	/**
	 * Re-check the gift against the current cart and settings.
	 *
	 * Removes it when the cart no longer qualifies or the product has become
	 * unavailable, and resizes it when the earned quantity has changed.
	 *
	 * @param mixed $context Hook argument; ignored except when it is a cart.
	 */
	public function validate( $context = null ) {
		if ( $this->validating || self::$suspended > 0 ) {
			return;
		}

		$cart = $context instanceof WC_Cart ? $context : ( function_exists( 'WC' ) && WC()->cart ? WC()->cart : null );

		if ( ! $cart ) {
			return;
		}

		$keys = BOGO_Select_Engine::find_reward_keys( $cart );

		if ( ! $keys ) {
			return;
		}

		// finally, because run_validation() removes items and changes quantities,
		// and any extension watching those cart hooks may throw. Clearing the
		// flag on the way out of an exception keeps a single failed pass from
		// disabling validation for the rest of the request.
		$this->validating = true;

		try {
			$this->run_validation( $cart, $keys );
		} finally {
			$this->validating = false;
		}
	}

	/**
	 * The body of a validation pass, with re-entrancy already guarded.
	 *
	 * @param WC_Cart  $cart Cart being validated.
	 * @param string[] $keys Every gift line key found in the cart.
	 */
	protected function run_validation( $cart, $keys ) {
		$key = array_shift( $keys );

		// Only one gift line may exist (BRIEF.md §4.3). Anything beyond the first
		// is dropped before the survivor is judged, so a duplicated or drifted
		// session cannot leave unchecked free lines behind.
		if ( $keys ) {
			foreach ( $keys as $duplicate ) {
				$cart->remove_cart_item( $duplicate );
			}

			$this->notice(
				sprintf(
					/* translators: 1 and 2: what the reward is called, e.g. "free gift". */
					__( 'Duplicate %1$s lines were removed from your cart. Only one %2$s is awarded per cart.', 'bogo-select' ),
					BOGO_Select_Engine::reward_noun(),
					BOGO_Select_Engine::reward_noun()
				)
			);
		}

		$cart_item = $cart->get_cart_item( $key );

		if ( ! $cart_item ) {
			return;
		}

		$product = wc_get_product( (int) $cart_item['product_id'] );

		if ( ! BOGO_Select_Engine::is_active() ) {
			$this->drop(
				$cart,
				$key,
				sprintf(
					/* translators: %s: what the reward is called, e.g. "free gift". */
					__( 'Your %s was removed because the promotion is no longer running.', 'bogo-select' ),
					BOGO_Select_Engine::reward_noun()
				)
			);
			return;
		}

		if ( ! $product || ! BOGO_Select_Engine::is_get_eligible( (int) $cart_item['product_id'] ) ) {
			$this->drop(
				$cart,
				$key,
				sprintf(
					/* translators: %s: what the reward is called, e.g. "free gift". */
					__( 'Your %s was removed because it is no longer part of the promotion.', 'bogo-select' ),
					BOGO_Select_Engine::reward_noun()
				)
			);
			return;
		}

		$earned = BOGO_Select_Engine::reward_quantity_for_cart( $cart );

		if ( $earned < 1 ) {
			$this->drop(
				$cart,
				$key,
				sprintf(
					/* translators: %s: what the reward is called, e.g. "free gift". */
					__( 'Your %s was removed because your cart no longer qualifies.', 'bogo-select' ),
					BOGO_Select_Engine::reward_noun()
				)
			);
			return;
		}

		// Availability is rechecked on every pass, not only when the earned
		// quantity moves: stock can fall away underneath an unchanged cart.
		$other_demand = BOGO_Select_Engine::stock_demand( $cart, $product, $key );
		$reason       = BOGO_Select_Engine::unavailable_reason( $product, $earned, $other_demand );

		if ( $reason ) {
			$this->drop(
				$cart,
				$key,
				sprintf(
					/* translators: 1: what the reward is called, 2: product name, 3: reason. */
					__( 'Your %1$s (%2$s) was removed: %3$s.', 'bogo-select' ),
					BOGO_Select_Engine::reward_noun(),
					$product->get_name(),
					$reason
				)
			);
			return;
		}

		$current = (int) $cart_item['quantity'];

		if ( $earned !== $current ) {
			$cart->set_quantity( $key, $earned, false );
			$this->notice(
				sprintf(
					/* translators: 1: what the reward is called, 2: new quantity. */
					__( 'Your %1$s quantity was updated to %2$d.', 'bogo-select' ),
					BOGO_Select_Engine::reward_noun(),
					$earned
				)
			);
		}
	}

	/**
	 * Replace the quantity input with static text for gift lines.
	 *
	 * The customer may still remove the line; they may not change its size
	 * (DECISION.md D-007).
	 *
	 * @param string $html      Quantity HTML.
	 * @param string $cart_item_key Cart item key.
	 * @param array  $cart_item Cart item.
	 * @return string
	 */
	public function lock_quantity( $html, $cart_item_key, $cart_item = array() ) {
		if ( ! BOGO_Select_Engine::is_reward_item( $cart_item ) ) {
			return $html;
		}

		return sprintf(
			'<span class="bogo-select-locked-qty">%s</span>',
			esc_html( (string) $cart_item['quantity'] )
		);
	}

	/**
	 * Append a "Free (BOGO)" or "50% off (BOGO)" badge to the reward's name.
	 *
	 * @param string $name      Product name HTML.
	 * @param array  $cart_item Cart item.
	 * @param string $cart_item_key Cart item key.
	 * @return string
	 */
	public function label_name( $name, $cart_item, $cart_item_key = '' ) {
		if ( ! BOGO_Select_Engine::is_reward_item( $cart_item ) ) {
			return $name;
		}

		return $name . sprintf(
			' <span class="bogo-select-badge">%s</span>',
			esc_html(
				sprintf(
					/* translators: %s: what the reward costs, e.g. "Free" or "50% off". */
					__( '%s (BOGO)', 'bogo-select' ),
					BOGO_Select_Engine::reward_label()
				)
			)
		);
	}

	/**
	 * Show the gift's unit price as the offer prices it, usual price struck through.
	 *
	 * @param string $price     Price HTML.
	 * @param array  $cart_item Cart item.
	 * @param string $cart_item_key Cart item key.
	 * @return string
	 */
	public function label_price( $price, $cart_item, $cart_item_key = '' ) {
		if ( ! BOGO_Select_Engine::is_reward_item( $cart_item ) ) {
			return $price;
		}

		return $this->reward_markup( $cart_item, 1 );
	}

	/**
	 * Show the gift's line subtotal as the offer prices it.
	 *
	 * The struck-through figure covers the whole line, not one unit — eight $10
	 * gifts strike through $80.
	 *
	 * @param string $subtotal  Subtotal HTML.
	 * @param array  $cart_item Cart item.
	 * @param string $cart_item_key Cart item key.
	 * @return string
	 */
	public function label_subtotal( $subtotal, $cart_item, $cart_item_key = '' ) {
		if ( ! BOGO_Select_Engine::is_reward_item( $cart_item ) ) {
			return $subtotal;
		}

		$qty = isset( $cart_item['quantity'] ) ? max( 1, (int) $cart_item['quantity'] ) : 1;

		return $this->reward_markup( $cart_item, $qty );
	}

	/**
	 * What the reward costs, preceded by what it would have cost.
	 *
	 * "Free" when the offer gives it away, the discounted figure otherwise. That
	 * figure goes through wc_get_price_to_display() with an explicit price, so a
	 * tax-inclusive store shows a tax-inclusive number — multiplying the raw
	 * price here would not.
	 *
	 * @param array $cart_item Cart item.
	 * @param int   $qty       Units the displayed price should cover.
	 * @return string
	 */
	protected function reward_markup( $cart_item, $qty ) {
		$product = self::line_product( $cart_item );
		$regular = $product ? wc_get_price_to_display( $product, array( 'qty' => $qty ) ) : 0;

		if ( ! $product || BOGO_Select_Engine::is_free_reward() ) {
			$now = esc_html__( 'Free', 'bogo-select' );
		} else {
			$now = wc_price(
				wc_get_price_to_display(
					$product,
					array(
						'qty'   => $qty,
						'price' => BOGO_Select_Engine::reward_price( $product->get_price() ),
					)
				)
			);
		}

		$now = '<span class="bogo-select-free-price">' . $now . '</span>';

		if ( $regular > 0 ) {
			return '<del aria-hidden="true">' . wc_price( $regular ) . '</del> ' . $now;
		}

		return $now;
	}

	/**
	 * Flag the gift on the order line item.
	 *
	 * @param WC_Order_Item_Product $item          Order line item.
	 * @param string                $cart_item_key Cart item key.
	 * @param array                 $values        Cart item values.
	 * @param WC_Order              $order         Order.
	 */
	public function add_order_item_meta( $item, $cart_item_key, $values, $order ) {
		if ( ! BOGO_Select_Engine::is_reward_item( $values ) ) {
			return;
		}

		// Hidden flag for programmatic checks.
		$item->add_meta_data( '_bogo_select_free', 'yes', true );

		// Visible label for the admin order screen, emails, and packing slips.
		$meta = BOGO_Select_Engine::reward_meta();

		$item->add_meta_data( $meta['label'], $meta['value'], true );
	}

	/**
	 * Remove the gift line and explain why.
	 *
	 * @param WC_Cart $cart    Cart.
	 * @param string  $key     Cart item key.
	 * @param string  $message Customer-facing explanation.
	 */
	protected function drop( $cart, $key, $message ) {
		$cart->remove_cart_item( $key );
		$this->notice( $message );
	}

	/**
	 * Add a front-end notice, if notices are available in this request.
	 *
	 * @param string $message Message.
	 */
	protected function notice( $message ) {
		if ( ! function_exists( 'wc_add_notice' ) || ! WC()->session ) {
			return;
		}

		if ( ! wc_has_notice( $message, 'notice' ) ) {
			wc_add_notice( $message, 'notice' );
		}
	}
}
