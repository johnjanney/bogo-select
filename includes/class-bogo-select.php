<?php
/**
 * Plugin bootstrap.
 *
 * @package BOGO_Select
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin's pieces together.
 */
class BOGO_Select {

	/**
	 * Singleton instance.
	 *
	 * @var BOGO_Select|null
	 */
	protected static $instance = null;

	/**
	 * Get (and on first call, create) the instance.
	 *
	 * @return BOGO_Select
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Set everything up.
	 */
	protected function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Cached gift eligibility is about product state, so anything that
		// changes a product — or the offer itself — clears it.
		foreach ( array( 'update_option_' . BOGO_Select_Settings::OPTION, 'woocommerce_update_product', 'woocommerce_new_product', 'woocommerce_delete_product', 'woocommerce_trash_product', 'save_post_product' ) as $hook ) {
			add_action( $hook, array( 'BOGO_Select_Engine', 'flush_choice_cache' ) );
		}

		new BOGO_Select_Cart();
		new BOGO_Select_Ajax();
		new BOGO_Select_Blocks();

		if ( is_admin() ) {
			new BOGO_Select_Admin();
		}

		// The chooser is rendered for the storefront, but also for the AJAX
		// endpoints that hand a fresh copy of it back to a block cart.
		if ( ! is_admin() || wp_doing_ajax() ) {
			new BOGO_Select_Frontend();
		}
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'bogo-select', false, dirname( BOGO_SELECT_BASENAME ) . '/languages' );
	}

	/**
	 * No cloning.
	 */
	private function __clone() {}

	/**
	 * No unserializing.
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize BOGO_Select.' );
	}
}
