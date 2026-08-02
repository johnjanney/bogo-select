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
		add_action( 'wp_ajax_bogo_select_choices', array( $this, 'choices' ) );
		add_action( 'wp_ajax_nopriv_bogo_select_choices', array( $this, 'choices' ) );
		add_action( 'wp_ajax_bogo_select_refresh', array( $this, 'refresh' ) );
		add_action( 'wp_ajax_nopriv_bogo_select_refresh', array( $this, 'refresh' ) );
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

		$product_id   = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		$variation_id = isset( $_POST['variation_id'] ) ? absint( wp_unslash( $_POST['variation_id'] ) ) : 0;

		$result = self::select_gift( $cart, $product_id, $variation_id );

		if ( is_string( $result ) ) {
			$this->fail( $result );
		}

		list( $product_id, $variation_id ) = BOGO_Select_Engine::reward_pair( $product_id, $variation_id );

		$this->succeed( BOGO_Select_Engine::reward_product( $product_id, $variation_id ), (int) $result );
	}

	/**
	 * Put a gift in the cart, replacing whatever is there.
	 *
	 * Shared by the AJAX endpoint and the Store API update callback, so classic
	 * and block carts take exactly the same path through validation.
	 *
	 * @param WC_Cart $cart         Cart to act on.
	 * @param int     $product_id   Chosen product, or a variation's own ID.
	 * @param int     $variation_id Chosen variation, or 0.
	 * @return int|string Free units awarded, or a customer-facing error message.
	 */
	public static function select_gift( $cart, $product_id, $variation_id = 0 ) {
		// Settled once, here, so everything below names the reward the same way
		// the cart will.
		list( $product_id, $variation_id ) = BOGO_Select_Engine::reward_pair( $product_id, $variation_id );

		if ( ! BOGO_Select_Engine::is_active() ) {
			return __( 'This promotion is no longer running.', 'bogo-select' );
		}

		$qty = BOGO_Select_Engine::reward_quantity_for_cart( $cart );

		if ( $qty < 1 ) {
			return sprintf(
				/* translators: %s: what the reward is called, e.g. "free gift". */
				__( 'Your cart no longer qualifies for a %s.', 'bogo-select' ),
				BOGO_Select_Engine::reward_noun()
			);
		}

		if ( ! BOGO_Select_Engine::is_awardable( $product_id, $variation_id ) ) {
			return sprintf(
				/* translators: %s: what the reward is called, e.g. "free gift". */
				__( 'That product is not available as a %s.', 'bogo-select' ),
				BOGO_Select_Engine::reward_noun()
			);
		}

		$product = BOGO_Select_Engine::reward_product( $product_id, $variation_id );

		// Only one gift may exist at a time (BRIEF.md R4).
		$existing = BOGO_Select_Engine::find_reward_key( $cart );

		// The gift being replaced is not competing with its own replacement, so
		// its units are left out of the stock arithmetic.
		$other_demand = BOGO_Select_Engine::stock_demand( $cart, $product, $existing );
		$reason       = BOGO_Select_Engine::unavailable_reason( $product, $qty, $other_demand );

		if ( $reason ) {
			return $reason;
		}

		// Re-picking the gift already held is a no-op beyond keeping it the right
		// size. Handling it here also keeps the swap below from merging a line
		// into the one it is about to remove.
		if ( $existing ) {
			$current = $cart->get_cart_item( $existing );

			$current_variation = $current && ! empty( $current['variation_id'] ) ? (int) $current['variation_id'] : 0;

			if ( $current && (int) $current['product_id'] === $product_id && $current_variation === $variation_id ) {
				if ( (int) $current['quantity'] !== $qty ) {
					$cart->set_quantity( $existing, $qty, true );
				}

				return $qty;
			}
		}

		/*
		 * Swapping gifts is one operation, not a remove followed by an add: the
		 * replacement must be in the cart before the old gift leaves, so a reject
		 * from core stock validation or a third-party
		 * woocommerce_add_to_cart_validation callback cannot strand the customer
		 * with no gift at all. Validation is suspended for the moment both lines
		 * coexist, or it would treat the pair as a duplicate and cull one.
		 */
		BOGO_Select_Cart::suspend();

		try {
			$errors_before = count( wc_get_notices( 'error' ) );

			$attributes = array();

			if ( $variation_id && $product ) {
				/**
				 * reward_product() returns the variation itself when one is
				 * named, so this is a variation wherever $variation_id is set.
				 *
				 * @var WC_Product_Variation $product
				 */
				$attributes = (array) $product->get_variation_attributes();
			}

			$key = $cart->add_to_cart(
				$product_id,
				$qty,
				$variation_id,
				$attributes,
				array(
					BOGO_Select_Engine::FLAG => true,
				)
			);

			if ( ! $key ) {
				$errors  = wc_get_notices( 'error' );
				$new     = array_slice( $errors, $errors_before );
				$message = __( 'That gift could not be added to your cart.', 'bogo-select' );

				if ( $new && ! empty( $new[0]['notice'] ) ) {
					$message = wp_strip_all_tags( $new[0]['notice'] );
				}

				// The previous gift is still in the cart, untouched.
				return $message;
			}

			// Every prior gift line goes, not just the first: if the session had
			// drifted into duplicates, the swap is the moment to settle it.
			foreach ( BOGO_Select_Engine::find_reward_keys( $cart ) as $previous ) {
				if ( $previous !== $key ) {
					$cart->remove_cart_item( $previous );
				}
			}
		} finally {
			// Whatever happens — including an exception thrown by a third-party
			// add-to-cart callback — validation must not stay suspended.
			BOGO_Select_Cart::resume();
		}

		/**
		 * Fires after a customer picks a gift.
		 *
		 * @param int $product_id   Chosen product ID. The parent, for a variation.
		 * @param int $qty          Free units awarded.
		 * @param int $variation_id Chosen variation ID, or 0.
		 */
		do_action( 'bogo_select_reward_added', $product_id, $qty, $variation_id );

		return $qty;
	}

	/**
	 * Take the current gift out of the cart.
	 *
	 * @param WC_Cart $cart Cart to act on.
	 * @return bool Whether a gift was there to remove.
	 */
	public static function clear_gift( $cart ) {
		$removed = false;

		foreach ( BOGO_Select_Engine::find_reward_keys( $cart ) as $key ) {
			$cart->remove_cart_item( $key );
			$removed = true;
		}

		return $removed;
	}

	/**
	 * Return one page of gift options as rendered cards.
	 */
	public function choices() {
		check_ajax_referer( 'bogo-select', 'nonce' );

		$cart = $this->cart();

		if ( ! $cart ) {
			$this->fail( __( 'Your cart is not available. Please refresh the page.', 'bogo-select' ) );
		}

		if ( ! BOGO_Select_Engine::is_active() ) {
			$this->fail( __( 'This promotion is no longer running.', 'bogo-select' ) );
		}

		$reward_qty = BOGO_Select_Engine::reward_quantity_for_cart( $cart );

		if ( $reward_qty < 1 ) {
			$this->fail(
				sprintf(
					/* translators: %s: what the reward is called, e.g. "free gift". */
					__( 'Your cart no longer qualifies for a %s.', 'bogo-select' ),
					BOGO_Select_Engine::reward_noun()
				)
			);
		}

		$search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';
		$page   = isset( $_POST['page'] ) ? absint( wp_unslash( $_POST['page'] ) ) : 1;

		$results = BOGO_Select_Engine::get_choice_page(
			array(
				'search' => $search,
				'page'   => $page,
			)
		);

		wp_send_json_success(
			array(
				'items' => BOGO_Select_Frontend::render_choices( $results['ids'], $reward_qty ),
				'page'  => (int) $results['page'],
				'pages' => (int) $results['pages'],
				'total' => (int) $results['total'],
				'empty' => $results['ids'] ? '' : __( 'No gifts match that search.', 'bogo-select' ),
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

		self::clear_gift( $cart );

		wp_send_json_success(
			$this->chooser_payload(
				array(
					'message' => sprintf(
						/* translators: %s: what the reward is called, e.g. "Free gift". */
						__( '%s removed.', 'bogo-select' ),
						BOGO_Select_Engine::reward_meta()['label']
					),
					'reload'  => true,
				)
			)
		);
	}

	/**
	 * Re-render the chooser for the cart as it stands.
	 *
	 * The block cart and block checkout change the cart without reloading the
	 * page, so the chooser has to be able to catch up on demand: a customer who
	 * has just crossed the qualifying threshold needs it to appear, and one who
	 * has dropped below it needs it to go away.
	 */
	public function refresh() {
		check_ajax_referer( 'bogo-select', 'nonce' );

		if ( ! $this->cart() ) {
			$this->fail( __( 'Your cart is not available. Please refresh the page.', 'bogo-select' ) );
		}

		wp_send_json_success( $this->chooser_payload() );
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
	 * The current chooser markup and offer state, for a JSON response.
	 *
	 * @param array $extra Fields to merge in.
	 * @return array
	 */
	protected function chooser_payload( $extra = array() ) {
		return array_merge(
			array(
				'html'  => BOGO_Select_Frontend::chooser_html(),
				'state' => BOGO_Select_Engine::state_signature(),
			),
			$extra
		);
	}

	/**
	 * Send a failure response and stop.
	 *
	 * @param string $message Customer-facing message.
	 */
	protected function fail( $message ) {
		wp_send_json_error( array( 'message' => $message ) );
	}

	/**
	 * Confirm a successful selection and stop.
	 *
	 * @param WC_Product $product Chosen product.
	 * @param int        $qty     Free units awarded.
	 */
	protected function succeed( $product, $qty ) {
		wp_send_json_success(
			$this->chooser_payload(
				array(
					'message' => sprintf(
						/* translators: 1: quantity, 2: product name, 3: what it costs, e.g. "free" or "at 50% off". */
						__( '%1$d × %2$s added to your cart %3$s.', 'bogo-select' ),
						(int) $qty,
						$product ? $product->get_name() : '',
						BOGO_Select_Engine::reward_phrase()
					),
					'reload'  => true,
				)
			)
		);
	}
}
