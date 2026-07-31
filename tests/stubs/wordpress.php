<?php
/**
 * Minimal WordPress stand-ins for the unit suite.
 *
 * Only the functions the plugin actually calls are defined, and they behave
 * like WordPress does for the inputs the plugin gives them. Anything needing
 * real WordPress or WooCommerce runtime behaviour (hook timing, sessions,
 * checkout, stock reduction) belongs in an integration suite instead — see
 * tests/README.md.
 *
 * @package BOGO_Select
 */

/**
 * Mutable test state: options, hooks, products, notices, and the cart.
 */
class BOGO_Test_Env {

	/**
	 * Stored options.
	 *
	 * @var array
	 */
	public static $options = array();

	/**
	 * Registered hook callbacks, keyed by tag then priority.
	 *
	 * @var array
	 */
	public static $hooks = array();

	/**
	 * Products available to wc_get_product()/wc_get_products().
	 *
	 * @var WC_Product[]
	 */
	public static $products = array();

	/**
	 * Notices raised through wc_add_notice().
	 *
	 * @var array[]
	 */
	public static $notices = array();

	/**
	 * The session cart.
	 *
	 * @var WC_Cart|null
	 */
	public static $cart = null;

	/**
	 * Reset everything between tests.
	 */
	public static function reset() {
		self::$options  = array();
		self::$hooks    = array();
		self::$products = array();
		self::$notices  = array();
		self::$cart     = new WC_Cart();

		BOGO_Select_Settings::flush();
	}

	/**
	 * Register a product with the fake catalogue.
	 *
	 * @param WC_Product $product Product.
	 * @return WC_Product
	 */
	public static function add_product( $product ) {
		self::$products[ $product->get_id() ] = $product;

		return $product;
	}

	/**
	 * Save plugin settings, merged over the defaults.
	 *
	 * @param array $settings Settings to store.
	 */
	public static function settings( array $settings ) {
		update_option( BOGO_Select_Settings::OPTION, array_merge( BOGO_Select_Settings::defaults(), $settings ) );
		BOGO_Select_Settings::flush();
	}

	/**
	 * Every notice message raised so far.
	 *
	 * @return string[]
	 */
	public static function notice_messages() {
		return array_map(
			function ( $notice ) {
				return $notice['notice'];
			},
			self::$notices
		);
	}
}

// --- Translation and escaping ------------------------------------------------

if ( ! function_exists( '__' ) ) {
	/**
	 * Pass-through translation.
	 *
	 * @param string $text   Text.
	 * @param string $domain Unused.
	 * @return string
	 */
	function __( $text, $domain = null ) { // phpcs:ignore
		return $text;
	}
}

/**
 * Pass-through plural translation.
 *
 * @param string $single Singular.
 * @param string $plural Plural.
 * @param int    $number Count.
 * @param string $domain Unused.
 * @return string
 */
function _n( $single, $plural, $number, $domain = null ) { // phpcs:ignore
	return 1 === (int) $number ? $single : $plural;
}

/**
 * Pass-through escaped translation.
 *
 * @param string $text   Text.
 * @param string $domain Unused.
 * @return string
 */
function esc_html__( $text, $domain = null ) { // phpcs:ignore
	return $text;
}

/**
 * Escape for HTML.
 *
 * @param string $text Text.
 * @return string
 */
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

/**
 * Escape for an attribute.
 *
 * @param string $text Text.
 * @return string
 */
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}

/**
 * Strip tags.
 *
 * @param string $text Text.
 * @return string
 */
function wp_strip_all_tags( $text ) {
	return trim( wp_kses_post_strip( (string) $text ) );
}

/**
 * Helper for wp_strip_all_tags().
 *
 * @param string $text Text.
 * @return string
 */
function wp_kses_post_strip( $text ) {
	return strip_tags( $text ); // phpcs:ignore
}

// --- Numbers and arrays ------------------------------------------------------

/**
 * Non-negative integer cast.
 *
 * @param mixed $value Value.
 * @return int
 */
function absint( $value ) {
	return abs( (int) $value );
}

/**
 * Merge arguments over defaults.
 *
 * @param mixed $args     Arguments.
 * @param array $defaults Defaults.
 * @return array
 */
function wp_parse_args( $args, $defaults = array() ) {
	return array_merge( $defaults, (array) $args );
}

/**
 * Remove slashes.
 *
 * @param mixed $value Value.
 * @return mixed
 */
function wp_unslash( $value ) {
	return is_string( $value ) ? stripslashes( $value ) : $value;
}

/**
 * Trim and strip tags from a text field.
 *
 * @param string $value Value.
 * @return string
 */
function sanitize_text_field( $value ) {
	return trim( strip_tags( (string) $value ) ); // phpcs:ignore
}

// --- Options -----------------------------------------------------------------

/**
 * Read an option.
 *
 * @param string $name    Option name.
 * @param mixed  $default Fallback.
 * @return mixed
 */
function get_option( $name, $default = false ) {
	return array_key_exists( $name, BOGO_Test_Env::$options ) ? BOGO_Test_Env::$options[ $name ] : $default;
}

/**
 * Write an option.
 *
 * @param string $name  Option name.
 * @param mixed  $value Value.
 * @return bool
 */
function update_option( $name, $value ) {
	BOGO_Test_Env::$options[ $name ] = $value;

	return true;
}

// --- Hooks -------------------------------------------------------------------

/**
 * Register a filter callback.
 *
 * @param string   $tag           Hook name.
 * @param callable $callback      Callback.
 * @param int      $priority      Priority.
 * @param int      $accepted_args Argument count.
 * @return bool
 */
function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	BOGO_Test_Env::$hooks[ $tag ][ $priority ][] = array(
		'callback' => $callback,
		'args'     => $accepted_args,
	);

	return true;
}

/**
 * Register an action callback.
 *
 * @param string   $tag           Hook name.
 * @param callable $callback      Callback.
 * @param int      $priority      Priority.
 * @param int      $accepted_args Argument count.
 * @return bool
 */
function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	return add_filter( $tag, $callback, $priority, $accepted_args );
}

/**
 * Run a filter chain.
 *
 * @param string $tag   Hook name.
 * @param mixed  $value Value being filtered.
 * @return mixed
 */
function apply_filters( $tag, $value ) {
	$args = array_slice( func_get_args(), 1 );

	if ( empty( BOGO_Test_Env::$hooks[ $tag ] ) ) {
		return $value;
	}

	$by_priority = BOGO_Test_Env::$hooks[ $tag ];
	ksort( $by_priority );

	foreach ( $by_priority as $callbacks ) {
		foreach ( $callbacks as $entry ) {
			$args[0] = $value;
			$value   = call_user_func_array( $entry['callback'], array_slice( $args, 0, max( 1, (int) $entry['args'] ) ) );
		}
	}

	return $value;
}

/**
 * Run an action chain.
 *
 * @param string $tag Hook name.
 */
function do_action( $tag ) {
	$args = array_slice( func_get_args(), 1 );

	if ( empty( BOGO_Test_Env::$hooks[ $tag ] ) ) {
		return;
	}

	$by_priority = BOGO_Test_Env::$hooks[ $tag ];
	ksort( $by_priority );

	foreach ( $by_priority as $callbacks ) {
		foreach ( $callbacks as $entry ) {
			call_user_func_array( $entry['callback'], array_slice( $args, 0, max( 0, (int) $entry['args'] ) ) );
		}
	}
}
