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
		add_action( 'woocommerce_add_to_cart', array( $this, 'validate' ), 20 );

		// Presentation.
		add_filter( 'woocommerce_cart_item_quantity', array( $this, 'lock_quantity' ), 10, 3 );
		add_filter( 'woocommerce_cart_item_name', array( $this, 'label_name' ), 10, 3 );
		add_filter( 'woocommerce_cart_item_price', array( $this, 'label_price' ), 10, 3 );
		add_filter( 'woocommerce_cart_item_subtotal', array( $this, 'label_price' ), 10, 3 );

		// Carry the flag through to the order.
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'add_order_item_meta' ), 10, 4 );
	}

	/**
	 * Force every gift line item to cost nothing.
	 *
	 * @param WC_Cart $cart Cart being calculated.
	 */
	public function set_reward_price( $cart ) {
		if ( ! $cart instanceof WC_Cart ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( BOGO_Select_Engine::is_reward_item( $cart_item ) && isset( $cart_item['data'] ) ) {
				$cart_item['data']->set_price( 0 );
			}
		}
	}

	/**
	 * Re-check the gift against the current cart and settings.
	 *
	 * Removes it when the cart no longer qualifies, and resizes it when the
	 * earned quantity has changed.
	 *
	 * @param mixed $context Hook argument; ignored except when it is a cart.
	 */
	public function validate( $context = null ) {
		if ( $this->validating ) {
			return;
		}

		$cart = $context instanceof WC_Cart ? $context : ( function_exists( 'WC' ) && WC()->cart ? WC()->cart : null );

		if ( ! $cart ) {
			return;
		}

		$key = BOGO_Select_Engine::find_reward_key( $cart );

		if ( ! $key ) {
			return;
		}

		$this->validating = true;

		$cart_item = $cart->get_cart_item( $key );
		$product   = $cart_item ? wc_get_product( (int) $cart_item['product_id'] ) : null;

		if ( ! BOGO_Select_Engine::is_active() ) {
			$this->drop( $cart, $key, __( 'Your free gift was removed because the promotion is no longer running.', 'bogo-select' ) );
			$this->validating = false;
			return;
		}

		if ( ! $product || ! BOGO_Select_Engine::is_get_eligible( (int) $cart_item['product_id'] ) ) {
			$this->drop( $cart, $key, __( 'Your free gift was removed because it is no longer part of the promotion.', 'bogo-select' ) );
			$this->validating = false;
			return;
		}

		$earned = BOGO_Select_Engine::reward_quantity_for_cart( $cart );

		if ( $earned < 1 ) {
			$this->drop( $cart, $key, __( 'Your free gift was removed because your cart no longer qualifies.', 'bogo-select' ) );
			$this->validating = false;
			return;
		}

		$current = (int) $cart_item['quantity'];

		if ( $earned !== $current ) {
			$reason = BOGO_Select_Engine::unavailable_reason( $product, $earned );

			if ( $reason ) {
				$this->drop(
					$cart,
					$key,
					sprintf(
						/* translators: 1: product name, 2: reason. */
						__( 'Your free gift (%1$s) was removed: %2$s.', 'bogo-select' ),
						$product->get_name(),
						$reason
					)
				);
			} else {
				$cart->set_quantity( $key, $earned, false );
				$this->notice(
					sprintf(
						/* translators: %d: new quantity. */
						__( 'Your free gift quantity was updated to %d.', 'bogo-select' ),
						$earned
					)
				);
			}
		}

		$this->validating = false;
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
	 * Append a "Free (BOGO)" badge to the gift's product name.
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
			esc_html__( 'Free (BOGO)', 'bogo-select' )
		);
	}

	/**
	 * Show the gift's price as free, with the usual price struck through.
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

		$product = wc_get_product( (int) $cart_item['product_id'] );
		$regular = $product ? wc_get_price_to_display( $product ) : 0;

		$free = '<span class="bogo-select-free-price">' . esc_html__( 'Free', 'bogo-select' ) . '</span>';

		if ( $regular > 0 ) {
			return '<del aria-hidden="true">' . wc_price( $regular ) . '</del> ' . $free;
		}

		return $free;
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
		$item->add_meta_data(
			__( 'Free gift', 'bogo-select' ),
			__( 'BOGO promotion', 'bogo-select' ),
			true
		);
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
