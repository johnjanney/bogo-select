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
	 * How many times wc_get_product() has been called.
	 *
	 * A stand-in for the data-store lookups a real store would make, so a test
	 * can hold the cost of rendering a page of variable cards.
	 *
	 * @var int
	 */
	public static $product_loads = 0;

	/**
	 * The site-local date the schedule should believe it is, as `Y-m-d`.
	 *
	 * Empty means "really now". A schedule that can only be tested by waiting
	 * for a date to arrive is a schedule that never gets tested.
	 *
	 * @var string
	 */
	public static $now = '';

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
	 * Stored transients.
	 *
	 * @var array
	 */
	public static $transients = array();

	/**
	 * Messages raised through add_settings_error().
	 *
	 * @var array[]
	 */
	public static $settings_errors = array();

	/**
	 * Whether WC_Data_Store::load() answers, so the query fallback can be
	 * exercised too.
	 *
	 * @var bool
	 */
	public static $data_store = true;

	/**
	 * Every data-store search performed, for asserting what was asked for.
	 *
	 * @var array[]
	 */
	public static $store_searches = array();

	/**
	 * Error message add_to_cart() should refuse with, or an empty string.
	 *
	 * @var string
	 */
	public static $reject_add_to_cart = '';

	/**
	 * Assets enqueued through the stubs, keyed by handle.
	 *
	 * @var array
	 */
	public static $enqueued = array();

	/**
	 * Data passed to wp_localize_script(), keyed by object name.
	 *
	 * @var array
	 */
	public static $localized = array();

	/**
	 * Reset everything between tests.
	 */
	public static function reset() {
		self::$product_loads      = 0;
		self::$now                = '';
		self::$options            = array();
		self::$hooks              = array();
		self::$products           = array();
		self::$notices            = array();
		self::$transients         = array();
		self::$settings_errors    = array();
		self::$store_searches     = array();
		self::$data_store         = true;
		self::$reject_add_to_cart = '';
		self::$enqueued           = array();
		self::$localized          = array();
		self::$cart               = new WC_Cart();

		BOGO_Select_Settings::flush();
		BOGO_Select_Engine::flush_choice_cache();
		BOGO_Select_Frontend::forget_slot();

		unset( $_SERVER['REQUEST_URI'] );
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
 * Echo an HTML attribute when two values match.
 *
 * WordPress's checked()/selected()/disabled() family, which all defer to the
 * same comparison and all echo by default.
 *
 * @param mixed  $helper  Value to compare.
 * @param mixed  $current Value to compare against.
 * @param bool   $echo    Whether to echo.
 * @param string $type    Attribute name.
 * @return string
 */
function __checked_selected_helper( $helper, $current, $echo, $type ) {
	// Loose comparison, as WordPress does: form values arrive as strings.
	$result = (string) $helper === (string) $current ? " $type=\"$type\"" : '';

	if ( $echo ) {
		echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	return $result;
}

/**
 * Echo `selected="selected"` when the values match.
 *
 * @param mixed $selected Value to compare.
 * @param mixed $current  Value to compare against.
 * @param bool  $echo     Whether to echo.
 * @return string
 */
function selected( $selected, $current = true, $echo = true ) {
	return __checked_selected_helper( $selected, $current, $echo, 'selected' );
}

/**
 * Echo `disabled="disabled"` when the values match.
 *
 * @param mixed $disabled Value to compare.
 * @param mixed $current  Value to compare against.
 * @param bool  $echo     Whether to echo.
 * @return string
 */
function disabled( $disabled, $current = true, $echo = true ) {
	return __checked_selected_helper( $disabled, $current, $echo, 'disabled' );
}

/**
 * Echo `checked="checked"` when the values match.
 *
 * @param mixed $checked Value to compare.
 * @param mixed $current Value to compare against.
 * @param bool  $echo    Whether to echo.
 * @return string
 */
function checked( $checked, $current = true, $echo = true ) {
	return __checked_selected_helper( $checked, $current, $echo, 'checked' );
}

/**
 * The current time, in the site's timezone.
 *
 * Only the `Y-m-d` shape the schedule asks for is modelled. Tests set
 * BOGO_Test_Env::$now to fix the date.
 *
 * @param string $type  Format, or 'timestamp'/'mysql'.
 * @param int    $gmt   Unused.
 * @return string|int
 */
function current_time( $type, $gmt = 0 ) {
	if ( '' !== BOGO_Test_Env::$now ) {
		if ( 'timestamp' === $type || 'U' === $type ) {
			return strtotime( BOGO_Test_Env::$now . ' 00:00:00' );
		}

		return gmdate( 'Y-m-d' === $type ? 'Y-m-d' : $type, strtotime( BOGO_Test_Env::$now . ' 00:00:00' ) );
	}

	if ( 'timestamp' === $type || 'U' === $type ) {
		return time();
	}

	return gmdate( $type );
}

/**
 * Format a number for display.
 *
 * The real one applies the site's locale separators; the tests only care that
 * the decimal places come out right, so this uses the English defaults.
 *
 * @param float $number   Number.
 * @param int   $decimals Decimal places.
 * @return string
 */
function number_format_i18n( $number, $decimals = 0 ) {
	return number_format( (float) $number, (int) $decimals );
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

/**
 * Record a settings-screen message.
 *
 * WordPress draws these after the option has already been written, which is the
 * whole point of CODEX-REVIEW.md M-01: a message is not a refusal. Recording
 * them lets a test assert both halves — what was said, and what was saved.
 *
 * @param string $setting Option name.
 * @param string $code    Message code.
 * @param string $message Message text.
 * @param string $type    error|warning|info|success.
 */
function add_settings_error( $setting, $code, $message, $type = 'error' ) {
	BOGO_Test_Env::$settings_errors[] = array(
		'setting' => $setting,
		'code'    => $code,
		'message' => $message,
		'type'    => $type,
	);
}

/**
 * Every settings message raised so far.
 *
 * @param string $setting Option name, or an empty string for all of them.
 * @return array[]
 */
function get_settings_errors( $setting = '' ) {
	if ( '' === $setting ) {
		return BOGO_Test_Env::$settings_errors;
	}

	return array_values(
		array_filter(
			BOGO_Test_Env::$settings_errors,
			function ( $error ) use ( $setting ) {
				return $error['setting'] === $setting;
			}
		)
	);
}

/**
 * Read a transient.
 *
 * @param string $name Transient name.
 * @return mixed False when unset.
 */
function get_transient( $name ) {
	return array_key_exists( $name, BOGO_Test_Env::$transients ) ? BOGO_Test_Env::$transients[ $name ] : false;
}

/**
 * Write a transient.
 *
 * @param string $name       Transient name.
 * @param mixed  $value      Value.
 * @param int    $expiration Ignored.
 * @return bool
 */
function set_transient( $name, $value, $expiration = 0 ) {
	BOGO_Test_Env::$transients[ $name ] = $value;

	return true;
}

/**
 * Delete a transient.
 *
 * @param string $name Transient name.
 * @return bool
 */
function delete_transient( $name ) {
	unset( BOGO_Test_Env::$transients[ $name ] );

	return true;
}

// --- Request context ---------------------------------------------------------

/**
 * Whether this is an admin screen.
 *
 * @return bool
 */
function is_admin() {
	return false;
}

/**
 * Whether this is an AJAX request.
 *
 * @return bool
 */
function wp_doing_ajax() {
	return false;
}

/**
 * Sanitize a key.
 *
 * @param string $key Key.
 * @return string
 */
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

/**
 * Sanitize a URL.
 *
 * @param string $url URL.
 * @return string
 */
function esc_url_raw( $url ) {
	return (string) $url;
}

/**
 * Escape post-safe HTML.
 *
 * @param string $html HTML.
 * @return string
 */
function wp_kses_post( $html ) {
	return (string) $html;
}

/**
 * Echo escaped translated text.
 *
 * @param string $text   Text.
 * @param string $domain Unused.
 */
function esc_html_e( $text, $domain = null ) { // phpcs:ignore
	echo esc_html( $text );
}

/**
 * Echo an escaped translated attribute.
 *
 * @param string $text   Text.
 * @param string $domain Unused.
 */
function esc_attr_e( $text, $domain = null ) { // phpcs:ignore
	echo esc_attr( $text );
}

/**
 * Pass-through escaped translated attribute.
 *
 * @param string $text   Text.
 * @param string $domain Unused.
 * @return string
 */
function esc_attr__( $text, $domain = null ) { // phpcs:ignore
	return $text;
}

// --- Assets ------------------------------------------------------------------

/**
 * Whether a script is in the given state.
 *
 * Nothing is registered or enqueued in the unit environment, except what the
 * plugin itself enqueues.
 *
 * @param string $handle Script handle.
 * @param string $state  State to test.
 * @return bool
 */
function wp_script_is( $handle, $state = 'enqueued' ) {
	return 'enqueued' === $state && isset( BOGO_Test_Env::$enqueued[ $handle ] );
}

/**
 * Record an enqueued script.
 *
 * @param string $handle    Handle.
 * @param string $src       Source.
 * @param array  $deps      Dependencies.
 * @param string $version   Version.
 * @param bool   $in_footer Whether it loads in the footer.
 */
function wp_enqueue_script( $handle, $src = '', $deps = array(), $version = '', $in_footer = false ) {
	BOGO_Test_Env::$enqueued[ $handle ] = array(
		'src'  => $src,
		'deps' => (array) $deps,
	);
}

/**
 * Record an enqueued style.
 *
 * @param string $handle  Handle.
 * @param string $src     Source.
 * @param array  $deps    Dependencies.
 * @param string $version Version.
 */
function wp_enqueue_style( $handle, $src = '', $deps = array(), $version = '' ) {
	BOGO_Test_Env::$enqueued[ $handle . '-style' ] = array( 'src' => $src );
}

/**
 * Record localized script data.
 *
 * @param string $handle Handle.
 * @param string $name   JavaScript object name.
 * @param array  $data   Data.
 * @return bool
 */
function wp_localize_script( $handle, $name, $data ) {
	BOGO_Test_Env::$localized[ $name ] = $data;

	return true;
}

/**
 * An admin URL.
 *
 * @param string $path Path.
 * @return string
 */
function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' );
}

/**
 * A predictable nonce.
 *
 * @param string $action Action.
 * @return string
 */
function wp_create_nonce( $action = '' ) {
	return 'nonce-' . $action;
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

// --- REST ---------------------------------------------------------------------

/**
 * Just enough of a REST request for route-based checks.
 */
class WP_REST_Request {

	/**
	 * Route being requested.
	 *
	 * @var string
	 */
	protected $route;

	/**
	 * @param string $route Route.
	 */
	public function __construct( $route = '' ) {
		$this->route = $route;
	}

	/**
	 * The route.
	 *
	 * @return string
	 */
	public function get_route() {
		return $this->route;
	}
}
