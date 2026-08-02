<?php
/**
 * Settings storage, defaults, and sanitization.
 *
 * @package BOGO_Select
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes the single plugin option.
 *
 * The shape below is not aspiration: `all()` normalises every key on the way
 * out, so a corrupt or hand-edited row cannot produce anything else. That is
 * what makes it safe to state, and what lets callers stop treating a setting as
 * something that could be any type at all.
 *
 * @phpstan-type BogoSettings array{
 *     buy_products: list<int<1, max>>,
 *     get_products: list<int<1, max>>,
 *     repeat: string,
 *     show_notice: string,
 *     get_scope: string,
 *     enabled: string,
 *     offer_title: string,
 *     buy_qty: int<1, max>,
 *     get_qty: int<1, max>,
 *     start_date: string,
 *     end_date: string,
 *     get_discount_type: string,
 *     get_discount_value: float,
 *     buy_scope: string
 * }
 */
class BOGO_Select_Settings {

	/**
	 * Option name holding every setting.
	 */
	const OPTION = 'bogo_select_settings';

	/**
	 * Runtime cache of the parsed settings array.
	 *
	 * @var BogoSettings|null
	 */
	protected static $cache = null;

	/**
	 * Default values for every setting.
	 *
	 * @return BogoSettings
	 */
	public static function defaults() {
		return array(
			'enabled'            => 'no',
			'offer_title'        => __( 'Choose your free gift', 'bogo-select' ),
			'buy_qty'            => 1,
			'get_qty'            => 1,
			'start_date'         => '',
			'end_date'           => '',
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
	 * Built as one array rather than by amending the merged one, so that every
	 * key is visibly normalised in the same place. A key added to defaults() and
	 * forgotten here is now a hole in the declared shape rather than a value
	 * that quietly reaches a caller in whatever type the database held it.
	 *
	 * @return BogoSettings
	 */
	public static function all() {
		if ( null !== self::$cache ) {
			return self::$cache;
		}

		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$values = wp_parse_args( $stored, self::defaults() );

		self::$cache = array(
			'enabled'            => self::to_bool_string( $values['enabled'] ),
			'offer_title'        => (string) $values['offer_title'],
			'buy_qty'            => max( 1, absint( $values['buy_qty'] ) ),
			'get_qty'            => max( 1, absint( $values['get_qty'] ) ),
			'start_date'         => self::to_date( $values['start_date'] ),
			'end_date'           => self::to_date( $values['end_date'] ),
			'get_discount_type'  => self::to_discount_type( $values['get_discount_type'] ),
			'get_discount_value' => self::to_percent( $values['get_discount_value'] ),
			'buy_scope'          => self::to_scope( $values['buy_scope'] ),
			'buy_products'       => self::to_id_array( $values['buy_products'] ),
			'get_scope'          => self::to_scope( $values['get_scope'] ),
			'get_products'       => self::to_id_array( $values['get_products'] ),
			'repeat'             => self::to_bool_string( $values['repeat'] ),
			'show_notice'        => self::to_bool_string( $values['show_notice'] ),
		);

		return self::$cache;
	}

	/**
	 * A single setting value.
	 *
	 * The return type is stated per key rather than left as `mixed`. Every value
	 * has passed through the normaliser in all() by the time it is handed out,
	 * so this describes what a caller actually receives — and saves each of them
	 * casting a value that is already the right type. An unrecognised key falls
	 * through to $default, which is the one case that stays genuinely unknown.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback when the key is unknown.
	 * @return mixed
	 *
	 * @phpstan-return (
	 *     $key is 'buy_qty'|'get_qty' ? int<1, max> : (
	 *     $key is 'get_discount_value' ? float : (
	 *     $key is 'buy_products'|'get_products' ? list<int<1, max>> : (
	 *     $key is 'enabled'|'offer_title'|'start_date'|'end_date'|'get_discount_type'|'buy_scope'|'get_scope'|'repeat'|'show_notice' ? string :
	 *     mixed ))))
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
	 *
	 * @return void
	 */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * Sanitize a raw settings array coming from the settings form.
	 *
	 * @param mixed $raw Raw input.
	 * @return BogoSettings
	 */
	public static function sanitize( $raw ) {
		$raw   = is_array( $raw ) ? $raw : array();
		$clean = self::defaults();

		$clean['enabled']     = isset( $raw['enabled'] ) && 'yes' === $raw['enabled'] ? 'yes' : 'no';
		$clean['repeat']      = isset( $raw['repeat'] ) && 'yes' === $raw['repeat'] ? 'yes' : 'no';
		$clean['show_notice'] = isset( $raw['show_notice'] ) && 'yes' === $raw['show_notice'] ? 'yes' : 'no';

		$title                = isset( $raw['offer_title'] ) ? self::to_text( $raw['offer_title'] ) : '';
		$clean['offer_title'] = '' !== $title ? $title : self::defaults()['offer_title'];

		$clean['buy_qty'] = isset( $raw['buy_qty'] ) ? max( 1, self::to_id( $raw['buy_qty'] ) ) : 1;
		$clean['get_qty'] = isset( $raw['get_qty'] ) ? max( 1, self::to_id( $raw['get_qty'] ) ) : 1;

		$clean['start_date'] = isset( $raw['start_date'] ) ? self::to_date( $raw['start_date'] ) : '';
		$clean['end_date']   = isset( $raw['end_date'] ) ? self::to_date( $raw['end_date'] ) : '';

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
	 * Normalise a calendar date to `Y-m-d`, or to nothing.
	 *
	 * Anything that is not a real date becomes an empty string. At this level
	 * that reads as "no bound on this side", which is also what a blank field
	 * means — the two are told apart where it matters, in the settings screen,
	 * which keeps the schedule it already had rather than treating a typo as a
	 * request to remove a boundary (CODEX-REVIEW.md M-01).
	 *
	 * A date that does not exist, such as 2026-02-30, is rejected rather than
	 * rolled forward into March, because a schedule silently shifting by a day
	 * is worse than one that refuses the input.
	 *
	 * The whole string must be a date. Converting each dash-separated part with
	 * intval() alone accepted "2026-08-01junk" as the first of August, because
	 * intval stops at the first character it cannot read — a stored value that
	 * looks nothing like a date then silently became a real boundary. Unpadded
	 * parts are still accepted, so a hand-written 2026-8-1 keeps working.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	protected static function to_date( $value ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		if ( '' === $value ) {
			return '';
		}

		if ( ! preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $parts ) ) {
			return '';
		}

		$year  = (int) $parts[1];
		$month = (int) $parts[2];
		$day   = (int) $parts[3];

		if ( ! checkdate( $month, $day, $year ) ) {
			return '';
		}

		return sprintf( '%04d-%02d-%02d', $year, $month, $day );
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
	 * A submitted value as a whole number, or zero.
	 *
	 * Request data is not necessarily scalar: `product_id[]=7` arrives as an
	 * array, and absint() reaches for intval(), which answers 1 for any
	 * non-empty array. Every ID taken from a request goes through here so that
	 * a shape nobody asked for becomes nothing rather than product 1.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	public static function to_id( $value ) {
		return is_scalar( $value ) ? absint( $value ) : 0;
	}

	/**
	 * A submitted value as plain text, or an empty string.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function to_text( $value ) {
		return is_scalar( $value ) ? sanitize_text_field( wp_unslash( (string) $value ) ) : '';
	}

	/**
	 * Normalise any value into a list of product IDs.
	 *
	 * The public face of to_id_array(), for callers outside this class that are
	 * handed a list from a filter or a request and need it reduced the same way.
	 *
	 * @param mixed $value Raw value.
	 * @return list<int>
	 */
	public static function to_id_list( $value ) {
		return self::to_id_array( $value );
	}

	/**
	 * Normalise a list of product IDs.
	 *
	 * A list rather than an array: array_values() reindexes, and saying so is
	 * what lets the settings shape promise one.
	 *
	 * @param mixed $value Raw value.
	 * @return list<int<1, max>>
	 */
	protected static function to_id_array( $value ) {
		if ( is_string( $value ) ) {
			$value = explode( ',', $value );
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$ids = array();

		foreach ( $value as $item ) {
			// absint() reaches for intval(), and intval() of an array is 1 — so a
			// nested array in a hand-edited option row used to become product 1
			// rather than nothing. Anything that is not a scalar is not an ID.
			$id = self::to_id( $item );

			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return array_values( array_unique( $ids ) );
	}
}
