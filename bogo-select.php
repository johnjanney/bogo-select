<?php
/**
 * Plugin Name:       BOGO Select for WooCommerce
 * Plugin URI:        https://github.com/johnjanney/bogo-select
 * Description:       Buy X, Get Y — the customer chooses their reward from a list you control, free or at a percentage off. Variable products let them pick the size or colour, and the offer can run to a schedule. The reward is a real cart line, so inventory is still reduced.
 * Version:           2.3.8
 * Author:            John Janney
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bogo-select
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.9
 * WC tested up to:   10.9
 *
 * @package BOGO_Select
 */

defined( 'ABSPATH' ) || exit;

define( 'BOGO_SELECT_VERSION', '2.3.8' );
define( 'BOGO_SELECT_MIN_WC', '9.9' );
define( 'BOGO_SELECT_FILE', __FILE__ );
define( 'BOGO_SELECT_PATH', plugin_dir_path( __FILE__ ) );
define( 'BOGO_SELECT_URL', plugin_dir_url( __FILE__ ) );
define( 'BOGO_SELECT_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Declare compatibility with WooCommerce feature flags.
 *
 * HPOS (custom order tables) and the Cart/Checkout blocks are both supported.
 * Block support arrived in 1.2.0: the chooser renders ahead of the Cart and
 * Checkout blocks and selection runs through the Store API. See DECISION.md
 * D-008.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', BOGO_SELECT_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', BOGO_SELECT_FILE, true );
	}
);

/**
 * Boot the plugin once all plugins are loaded, provided WooCommerce is present
 * and new enough.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( bogo_select_dependency_problem() ) {
			add_action( 'admin_notices', 'bogo_select_missing_wc_notice' );
			return;
		}

		require_once BOGO_SELECT_PATH . 'includes/class-bogo-settings.php';
		require_once BOGO_SELECT_PATH . 'includes/class-bogo-engine.php';
		require_once BOGO_SELECT_PATH . 'includes/class-bogo-cart.php';
		require_once BOGO_SELECT_PATH . 'includes/class-bogo-frontend.php';
		require_once BOGO_SELECT_PATH . 'includes/class-bogo-ajax.php';
		require_once BOGO_SELECT_PATH . 'includes/class-bogo-blocks.php';
		require_once BOGO_SELECT_PATH . 'includes/class-bogo-admin.php';
		require_once BOGO_SELECT_PATH . 'includes/class-bogo-select.php';

		BOGO_Select::instance();
	}
);

/**
 * Why the plugin cannot run, if it cannot.
 *
 * @return string Empty string when every dependency is satisfied.
 */
function bogo_select_dependency_problem() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return __( 'BOGO Select for WooCommerce requires WooCommerce to be installed and active.', 'bogo-select' );
	}

	if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, BOGO_SELECT_MIN_WC, '<' ) ) {
		return sprintf(
			/* translators: 1: required WooCommerce version, 2: installed WooCommerce version. */
			__( 'BOGO Select for WooCommerce requires WooCommerce %1$s or later. WooCommerce %2$s is active.', 'bogo-select' ),
			BOGO_SELECT_MIN_WC,
			WC_VERSION
		);
	}

	return '';
}

/**
 * Admin notice shown when a dependency is missing at runtime.
 *
 * The plugin stays activated but loads nothing, so reinstating WooCommerce
 * restores the offer exactly as it was. On WordPress 6.5+ the `Requires
 * Plugins` header means this state is rarely reachable — WordPress deactivates
 * dependents itself.
 *
 * @return void
 */
function bogo_select_missing_wc_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}

	$problem = bogo_select_dependency_problem();

	if ( ! $problem ) {
		return;
	}

	printf(
		'<div class="notice notice-error"><p>%s %s</p></div>',
		esc_html( $problem ),
		esc_html__( 'Its settings are kept, but nothing runs on the storefront until that is fixed.', 'bogo-select' )
	);
}

/**
 * Refuse activation without WooCommerce, and record the installed version.
 *
 * The `Requires Plugins` header covers WordPress 6.5+; this guard covers the
 * 6.0–6.4 range the plugin still supports.
 */
register_activation_hook(
	__FILE__,
	function () {
		$problem = bogo_select_dependency_problem();

		if ( $problem ) {
			if ( ! function_exists( 'deactivate_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}

			deactivate_plugins( plugin_basename( __FILE__ ) );

			wp_die(
				esc_html( $problem ),
				esc_html__( 'Plugin activation failed', 'bogo-select' ),
				array( 'back_link' => true )
			);
		}

		update_option( 'bogo_select_version', BOGO_SELECT_VERSION );
	}
);
