<?php
/**
 * Shared base for the unit suite.
 *
 * @package BOGO_Select
 */

namespace BOGO_Select\Tests;

use BOGO_Test_Env;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use WC_Product;

/**
 * Resets the fake environment before every test and offers small builders.
 */
abstract class TestCase extends PHPUnitTestCase {

	/**
	 * Reset options, hooks, products, notices, and the cart.
	 */
	protected function setUp(): void {
		parent::setUp();

		BOGO_Test_Env::reset();
	}

	/**
	 * Register a product in the fake catalogue.
	 *
	 * @param int   $id    Product ID.
	 * @param array $props Property overrides.
	 * @return WC_Product
	 */
	protected function product( $id, array $props = array() ) {
		$props['id'] = $id;

		if ( ! isset( $props['name'] ) ) {
			$props['name'] = 'Product ' . $id;
		}

		return BOGO_Test_Env::add_product( new WC_Product( $props ) );
	}

	/**
	 * Register a variable parent and its variations in one call.
	 *
	 * Each entry in $variations is keyed by variation ID and holds that
	 * variation's own property overrides. Its `attributes` are what pick it out
	 * of the parent; an empty attribute value is WooCommerce's "any", and is
	 * spelled that way here so tests can build one deliberately.
	 *
	 * The parent's price is left to the caller. WooCommerce reports the low end
	 * of the range there, which is not the price of any particular variation and
	 * is why the chooser must not quote it once a variation has been chosen.
	 *
	 * @param int   $id         Parent product ID.
	 * @param array $variations Variation ID => property overrides.
	 * @param array $props      Parent property overrides.
	 * @return WC_Product The parent.
	 */
	protected function variable_product( $id, array $variations = array(), array $props = array() ) {
		$children = array();

		foreach ( $variations as $variation_id => $overrides ) {
			$children[] = (int) $variation_id;

			$this->product(
				(int) $variation_id,
				array_merge(
					array(
						'name'       => 'Product ' . $id . ' - ' . $variation_id,
						'type'       => 'variation',
						'parent_id'  => (int) $id,
						'attributes' => array( 'size' => 'small' ),
					),
					(array) $overrides
				)
			);
		}

		return $this->product(
			$id,
			array_merge(
				array(
					'type'     => 'variable',
					'children' => $children,
				),
				$props
			)
		);
	}

	/**
	 * Save plugin settings over the defaults.
	 *
	 * @param array $settings Settings.
	 */
	protected function settings( array $settings ) {
		BOGO_Test_Env::settings( $settings );
	}

	/**
	 * The session cart.
	 *
	 * @return \WC_Cart
	 */
	protected function cart() {
		return BOGO_Test_Env::$cart;
	}

	/**
	 * Add a paid line to the cart.
	 *
	 * @param string $key        Cart item key.
	 * @param int    $product_id Product ID.
	 * @param int    $quantity   Quantity.
	 * @param int    $variation  Variation ID.
	 * @return string
	 */
	protected function add_paid_item( $key, $product_id, $quantity = 1, $variation = 0 ) {
		return $this->cart()->add_item(
			$key,
			array(
				'product_id'   => $product_id,
				'variation_id' => $variation,
				'quantity'     => $quantity,
			)
		);
	}

	/**
	 * Add a gift line to the cart.
	 *
	 * @param string $key        Cart item key.
	 * @param int    $product_id Product ID.
	 * @param int    $quantity   Quantity.
	 * @return string
	 */
	protected function add_gift_item( $key, $product_id, $quantity = 1 ) {
		return $this->cart()->add_item(
			$key,
			array(
				'product_id'              => $product_id,
				'quantity'                => $quantity,
				\BOGO_Select_Engine::FLAG => true,
			)
		);
	}

	/**
	 * Every notice message raised so far.
	 *
	 * @return string[]
	 */
	protected function notices() {
		return BOGO_Test_Env::notice_messages();
	}

	/**
	 * Assert that some notice contains the given fragment.
	 *
	 * @param string $fragment Text to look for.
	 */
	protected function assertNoticeContains( $fragment ) {
		$messages = $this->notices();

		foreach ( $messages as $message ) {
			if ( false !== strpos( $message, $fragment ) ) {
				$this->addToAssertionCount( 1 );
				return;
			}
		}

		$this->fail( 'No notice contained "' . $fragment . '". Notices: ' . wp_json_encode_fallback( $messages ) );
	}
}

/**
 * JSON encode without depending on WordPress.
 *
 * @param mixed $value Value.
 * @return string
 */
function wp_json_encode_fallback( $value ) {
	return (string) wp_json_encode_raw( $value );
}

/**
 * JSON encode.
 *
 * @param mixed $value Value.
 * @return string
 */
function wp_json_encode_raw( $value ) {
	return json_encode( $value ); // phpcs:ignore
}
