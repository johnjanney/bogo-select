<?php
/**
 * Plugin Name:       BOGO Select for WooCommerce
 * Plugin URI:        https://github.com/johnjanney/bogo-select
 * Description:       Buy X, Get Y free — the customer chooses their free gift from a list you control, and the gift is added to the cart at $0.00 so inventory is still reduced.
 * Version:           1.0.0
 * Author:            John Janney
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bogo-select
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:   9.9
 *
 * @package BOGO_Select
 */

defined( 'ABSPATH' ) || exit;

define( 'BOGO_SELECT_VERSION', '1.0.0' );
define( 'BOGO_SELECT_FILE', __FILE__ );
define( 'BOGO_SELECT_PATH', plugin_dir_path( __FILE__ ) );
define( 'BOGO_SELECT_URL', plugin_dir_url( __FILE__ ) );
define( 'BOGO_SELECT_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Declare compatibility with WooCommerce feature flags.
 *
 * HPOS (custom order tables) is supported. The Cart/Checkout blocks are not —
 * the gift chooser hooks into classic cart templates. See DECISION.md D-008.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', BOGO_SELECT_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', BOGO_SELECT_FILE, false );
	}
);

/**
 * Boot the plugin once all plugins are loaded, provided WooCommerce is present.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', 'bogo_select_missing_wc_notice' );
			return;
		}

		require_once BOGO_SELECT_PATH . 'includes/class-bogo-settings.php';
		require_once BOGO_SELECT_PATH . 'includes/class-bogo-engine.php';
		require_once BOGO_SELECT_PATH . 'includes/class-bogo-cart.php';
		require_once BOGO_SELECT_PATH . 'includes/class-bogo-frontend.php';
		require_once BOGO_SELECT_PATH . 'includes/class-bogo-ajax.php';
		require_once BOGO_SELECT_PATH . 'includes/class-bogo-admin.php';
		require_once BOGO_SELECT_PATH . 'includes/class-bogo-select.php';

		BOGO_Select::instance();
	}
);

/**
 * Admin notice shown when WooCommerce is not active.
 */
function bogo_select_missing_wc_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'BOGO Select for WooCommerce requires WooCommerce to be installed and active.', 'bogo-select' )
	);
}

/**
 * Store the installed version on activation.
 */
register_activation_hook(
	__FILE__,
	function () {
		update_option( 'bogo_select_version', BOGO_SELECT_VERSION );
	}
);
