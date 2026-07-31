<?php
/**
 * Settings storage, defaults, and sanitization.
 *
 * @package BOGO_Select
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the single plugin option.
 */
class BOGO_Select_Settings {

	/**
	 * Option name holding every setting.
	 */
	const OPTION = 'bogo_select_settings';

	/**
	 * Runtime cache of the parsed settings array.
	 *
	 * @var array|null
	 */
	protected static $cache = null;

	/**
	 * Default values for every setting.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'            => 'no',
			'offer_title'        => __( 'Choose your free gift', 'bogo-select' ),
			'buy_qty'            => 1,
			'get_qty'            => 1,
			'get_discount_type'  => 'free',
			'get_discount_value' => 0.0,
			'buy_scope'          => 'all',
			'buy_products'       => array(),
			'get_scope'          => 'select',
			'get_products'       => array(),
			'repeat'             => 'no',
			'show_notice'        => 'yes',
		);
	}

	/**
	 * All settings, merged over the defaults and type-cast.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$values = wp_parse_args( $stored, self::defaults() );

		$values['enabled']            = self::to_bool_string( $values['enabled'] );
		$values['repeat']             = self::to_bool_string( $values['repeat'] );
		$values['show_notice']        = self::to_bool_string( $values['show_notice'] );
		$values['offer_title']        = (string) $values['offer_title'];
		$values['buy_qty']            = max( 1, absint( $values['buy_qty'] ) );
		$values['get_qty']            = max( 1, absint( $values['get_qty'] ) );
		$values['get_discount_type']  = self::to_discount_type( $values['get_discount_type'] );
		$values['get_discount_value'] = self::to_percent( $values['get_discount_value'] );
		$values['buy_scope']          = self::to_scope( $values['buy_scope'] );
		$values['get_scope']          = self::to_scope( $values['get_scope'] );
		$values['buy_products']       = self::to_id_array( $values['buy_products'] );
		$values['get_products']       = self::to_id_array( $values['get_products'] );

		self::$cache = $values;

		return self::$cache;
	}

	/**
	 * A single setting value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when the key is unknown.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all = self::all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Whether the offer is switched on.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return 'yes' === self::get( 'enabled' );
	}

	/**
	 * Whether repeat (multiple reward sets) mode is on.
	 *
	 * @return bool
	 */
	public static function is_repeating() {
		return 'yes' === self::get( 'repeat' );
	}

	/**
	 * Clear the runtime cache. Used after a save.
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Sanitize a raw settings array coming from the settings form.
	 *
	 * @param mixed $raw Raw input.
	 * @return array
	 */
	public static function sanitize( $raw ) {
		$raw   = is_array( $raw ) ? $raw : array();
		$clean = self::defaults();

		$clean['enabled']     = isset( $raw['enabled'] ) && 'yes' === $raw['enabled'] ? 'yes' : 'no';
		$clean['repeat']      = isset( $raw['repeat'] ) && 'yes' === $raw['repeat'] ? 'yes' : 'no';
		$clean['show_notice'] = isset( $raw['show_notice'] ) && 'yes' === $raw['show_notice'] ? 'yes' : 'no';

		$title                = isset( $raw['offer_title'] ) ? sanitize_text_field( wp_unslash( $raw['offer_title'] ) ) : '';
		$clean['offer_title'] = '' !== $title ? $title : self::defaults()['offer_title'];

		$clean['buy_qty'] = isset( $raw['buy_qty'] ) ? max( 1, absint( $raw['buy_qty'] ) ) : 1;
		$clean['get_qty'] = isset( $raw['get_qty'] ) ? max( 1, absint( $raw['get_qty'] ) ) : 1;

		$clean['get_discount_type']  = isset( $raw['get_discount_type'] ) ? self::to_discount_type( $raw['get_discount_type'] ) : 'free';
		$clean['get_discount_value'] = isset( $raw['get_discount_value'] ) ? self::to_percent( $raw['get_discount_value'] ) : 0.0;

		$clean['buy_scope'] = isset( $raw['buy_scope'] ) ? self::to_scope( $raw['buy_scope'] ) : 'all';
		$clean['get_scope'] = isset( $raw['get_scope'] ) ? self::to_scope( $raw['get_scope'] ) : 'select';

		$clean['buy_products'] = isset( $raw['buy_products'] ) ? self::to_id_array( $raw['buy_products'] ) : array();
		$clean['get_products'] = isset( $raw['get_products'] ) ? self::to_id_array( $raw['get_products'] ) : array();

		self::flush();

		return $clean;
	}

	/**
	 * Normalise a scope value.
	 *
	 * @param mixed $value Raw value.
	 * @return string 'all' or 'select'.
	 */
	protected static function to_scope( $value ) {
		return 'select' === $value ? 'select' : 'all';
	}

	/**
	 * Normalise the reward's discount type.
	 *
	 * 'free' is the default for anything unrecognised, so a corrupt or
	 * hand-edited option row gives the item away rather than charging for a
	 * reward the customer was promised.
	 *
	 * @param mixed $value Raw value.
	 * @return string 'free' or 'percent'.
	 */
	protected static function to_discount_type( $value ) {
		return 'percent' === $value ? 'percent' : 'free';
	}

	/**
	 * Normalise a percentage, clamped to 0–100.
	 *
	 * Clamped rather than rejected: a value outside the range would otherwise
	 * price the reward above its own value or below nothing.
	 *
	 * @param mixed $value Raw value.
	 * @return float
	 */
	protected static function to_percent( $value ) {
		$value = is_scalar( $value ) ? (float) $value : 0.0;

		return max( 0.0, min( 100.0, $value ) );
	}

	/**
	 * Normalise a checkbox-ish value to 'yes'/'no'.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	protected static function to_bool_string( $value ) {
		return in_array( $value, array( 'yes', 1, '1', true, 'true' ), true ) ? 'yes' : 'no';
	}

	/**
	 * Normalise a list of product IDs.
	 *
	 * @param mixed $value Raw value.
	 * @return int[]
	 */
	protected static function to_id_array( $value ) {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$ids = array_map( 'absint', $value );
		$ids = array_filter( $ids );

		return array_values( array_unique( $ids ) );
	}
}
