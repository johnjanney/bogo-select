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

		new BOGO_Select_Cart();
		new BOGO_Select_Ajax();

		if ( is_admin() ) {
			new BOGO_Select_Admin();
		} else {
			new BOGO_Select_Frontend();
		}
	}

	/**
	 * Load translations.
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
