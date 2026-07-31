<?php
/**
 * Front-end AJAX endpoints for choosing and removing the gift.
 *
 * Every request is re-validated server-side, so a crafted call cannot award a
 * free product to a cart that has not earned one.
 *
 * @package BOGO_Select
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles gift selection over AJAX.
 */
class BOGO_Select_Ajax {

	/**
	 * Register endpoints.
	 */
	public function __construct() {
		add_action( 'wp_ajax_bogo_select_choose', array( $this, 'choose' ) );
		add_action( 'wp_ajax_nopriv_bogo_select_choose', array( $this, 'choose' ) );
		add_action( 'wp_ajax_bogo_select_remove', array( $this, 'remove' ) );
		add_action( 'wp_ajax_nopriv_bogo_select_remove', array( $this, 'remove' ) );
	}

	/**
	 * Add the chosen gift to the cart at the earned quantity.
	 */
	public function choose() {
		check_ajax_referer( 'bogo-select', 'nonce' );

		$cart = $this->cart();

		if ( ! $cart ) {
			$this->fail( __( 'Your cart is not available. Please refresh the page.', 'bogo-select' ) );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;

		if ( ! BOGO_Select_Engine::is_active() ) {
			$this->fail( __( 'This promotion is no longer running.', 'bogo-select' ) );
		}

		$qty = BOGO_Select_Engine::reward_quantity_for_cart( $cart );

		if ( $qty < 1 ) {
			$this->fail( __( 'Your cart no longer qualifies for a free gift.', 'bogo-select' ) );
		}

		if ( ! BOGO_Select_Engine::is_get_eligible( $product_id ) ) {
			$this->fail( __( 'That product is not available as a free gift.', 'bogo-select' ) );
		}

		$product = wc_get_product( $product_id );
		$reason  = BOGO_Select_Engine::unavailable_reason( $product, $qty );

		if ( $reason ) {
			$this->fail( $reason );
		}

		// Only one gift may exist at a time (BRIEF.md R4).
		$existing = BOGO_Select_Engine::find_reward_key( $cart );

		if ( $existing ) {
			$cart->remove_cart_item( $existing );
		}

		$errors_before = count( wc_get_notices( 'error' ) );

		$key = $cart->add_to_cart(
			$product_id,
			$qty,
			0,
			array(),
			array(
				BOGO_Select_Engine::FLAG => true,
				'bogo_select_stamp'      => wp_generate_uuid4(),
			)
		);

		if ( ! $key ) {
			$errors = wc_get_notices( 'error' );
			$new    = array_slice( $errors, $errors_before );
			$message = __( 'That gift could not be added to your cart.', 'bogo-select' );

			if ( $new && ! empty( $new[0]['notice'] ) ) {
				$message = wp_strip_all_tags( $new[0]['notice'] );
			}

			$this->fail( $message );
		}

		/**
		 * Fires after a customer picks a gift.
		 *
		 * @param int $product_id Chosen product ID.
		 * @param int $qty        Free units awarded.
		 */
		do_action( 'bogo_select_reward_added', $product_id, $qty );

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: quantity, 2: product name. */
					__( '%1$d × %2$s added to your cart free of charge.', 'bogo-select' ),
					(int) $qty,
					$product->get_name()
				),
				'reload'  => true,
			)
		);
	}

	/**
	 * Remove the current gift from the cart.
	 */
	public function remove() {
		check_ajax_referer( 'bogo-select', 'nonce' );

		$cart = $this->cart();

		if ( ! $cart ) {
			$this->fail( __( 'Your cart is not available. Please refresh the page.', 'bogo-select' ) );
		}

		$key = BOGO_Select_Engine::find_reward_key( $cart );

		if ( $key ) {
			$cart->remove_cart_item( $key );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Free gift removed.', 'bogo-select' ),
				'reload'  => true,
			)
		);
	}

	/**
	 * The session cart, if one exists in this request.
	 *
	 * @return WC_Cart|null
	 */
	protected function cart() {
		return function_exists( 'WC' ) && WC()->cart ? WC()->cart : null;
	}

	/**
	 * Send a failure response and stop.
	 *
	 * @param string $message Customer-facing message.
	 */
	protected function fail( $message ) {
		wp_send_json_error( array( 'message' => $message ) );
	}
}
