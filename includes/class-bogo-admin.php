<?php
/**
 * Admin settings screen.
 *
 * @package BOGO_Select
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds the WooCommerce → BOGO Select settings page.
 */
class BOGO_Select_Admin {

	/**
	 * Settings group name.
	 */
	const GROUP = 'bogo_select_group';

	/**
	 * Menu slug.
	 */
	const SLUG = 'bogo-select';

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_filter( 'option_page_capability_' . self::GROUP, array( $this, 'settings_capability' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 20 );
		add_filter( 'plugin_action_links_' . BOGO_SELECT_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Add the submenu page under WooCommerce.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'BOGO Select', 'bogo-select' ),
			__( 'BOGO Select', 'bogo-select' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Register the option with the Settings API.
	 *
	 * @return void
	 */
	public function register_settings() {
		register_setting(
			self::GROUP,
			BOGO_Select_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => BOGO_Select_Settings::defaults(),
			)
		);
	}

	/**
	 * How the offer's schedule reads in one sentence.
	 *
	 * @param string $start Start date, or an empty string.
	 * @param string $end   End date, or an empty string.
	 * @return string Empty when the offer is unscheduled.
	 */
	protected static function window_sentence( $start, $end ) {
		if ( '' === $start && '' === $end ) {
			return '';
		}

		if ( '' !== $start && '' !== $end ) {
			return sprintf(
				/* translators: 1: start date, 2: end date. */
				__( 'Runs from %1$s to %2$s inclusive.', 'bogo-select' ),
				$start,
				$end
			);
		}

		if ( '' !== $start ) {
			/* translators: %s: start date. */
			return sprintf( __( 'Runs from %s until switched off.', 'bogo-select' ), $start );
		}

		/* translators: %s: end date. */
		return sprintf( __( 'Runs until the end of %s.', 'bogo-select' ), $end );
	}

	/**
	 * Sanitize submitted settings and warn about unusable choices.
	 *
	 * @param mixed $raw Raw form input.
	 * @return array<string,mixed>
	 */
	public function sanitize( $raw ) {
		// Read before sanitize(), which flushes the settings cache. options.php
		// has not written the new row yet, so this is the schedule the store is
		// running on now — what a rejected submission falls back to.
		$stored = BOGO_Select_Settings::all();
		$clean  = BOGO_Select_Settings::sanitize( $raw );

		$clean = $this->keep_last_valid_schedule( $raw, $clean, $stored );

		// A reward has to be something the customer can actually be given. Grouped
		// and external products never are; a variable product needs at least one
		// usable variation; a variation must not leave an option open. The reasons
		// come from the engine so the rule lives in one place, and are grouped so
		// a list rejected for several different reasons says so once each.
		$rejected = array();

		$clean['get_products'] = array_values(
			array_filter(
				$clean['get_products'],
				function ( $product_id ) use ( &$rejected ) {
					$reason = BOGO_Select_Engine::unofferable_reason( $product_id );

					if ( '' === $reason ) {
						return true;
					}

					$product = wc_get_product( $product_id );

					$rejected[ $reason ][] = $product
						? $product->get_name()
						/* translators: %d: product ID. */
						: sprintf( __( 'product %d', 'bogo-select' ), (int) $product_id );

					return false;
				}
			)
		);

		foreach ( $rejected as $reason => $names ) {
			add_settings_error(
				BOGO_Select_Settings::OPTION,
				'bogo_select_unofferable_gift',
				sprintf(
					/* translators: 1: why they were removed, 2: comma-separated product names. */
					__( 'Removed from the reward list (%1$s): %2$s.', 'bogo-select' ),
					$reason,
					implode( ', ', array_map( 'sanitize_text_field', $names ) )
				),
				'warning'
			);
		}

		if ( 'yes' === $clean['enabled'] ) {
			if ( 'select' === $clean['buy_scope'] && ! $clean['buy_products'] ) {
				add_settings_error(
					BOGO_Select_Settings::OPTION,
					'bogo_select_no_buy',
					__( 'The offer is enabled but the Buy list is empty, so nothing can qualify. Add products or switch Buy to All Products.', 'bogo-select' ),
					'error'
				);
			}

			if ( 'select' === $clean['get_scope'] && ! $clean['get_products'] ) {
				add_settings_error(
					BOGO_Select_Settings::OPTION,
					'bogo_select_no_get',
					__( 'The offer is enabled but the gift list is empty, so there is nothing to choose. Add products or switch Get to All Products.', 'bogo-select' ),
					'error'
				);
			}

			if ( '' !== $clean['end_date'] && $clean['end_date'] < BOGO_Select_Engine::today() ) {
				// Enabled, but the window has already closed. Worth saying plainly:
				// the storefront will look exactly as though the offer were off.
				add_settings_error(
					BOGO_Select_Settings::OPTION,
					'bogo_select_window_past',
					sprintf(
						/* translators: %s: the end date. */
						__( 'The offer is enabled but ended on %s, so nothing is shown on the storefront. Change the end date or clear it.', 'bogo-select' ),
						$clean['end_date']
					),
					'warning'
				);
			} elseif ( '' !== $clean['start_date'] && $clean['start_date'] > BOGO_Select_Engine::today() ) {
				add_settings_error(
					BOGO_Select_Settings::OPTION,
					'bogo_select_window_future',
					sprintf(
						/* translators: %s: the start date. */
						__( 'The offer is enabled and scheduled to start on %s. Nothing is shown on the storefront until then.', 'bogo-select' ),
						$clean['start_date']
					),
					'info'
				);
			}

			// A 0% discount charges full price for the reward, which is almost
			// certainly a slip. Warned about rather than corrected: quietly turning
			// a store's promotion into something it did not configure is worse than
			// letting it run visibly wrong, and the offer still functions.
			if ( 'percent' === $clean['get_discount_type'] && 0.0 === (float) $clean['get_discount_value'] ) {
				add_settings_error(
					BOGO_Select_Settings::OPTION,
					'bogo_select_zero_discount',
					__( 'The reward price is set to a percentage off, but the discount is 0%, so the reward costs its full price. Set a discount, or make the reward free.', 'bogo-select' ),
					'warning'
				);
			}

			// The offer title is the store's own words and cannot be rewritten for
			// them, so a title left over from a free-gift offer is only flagged.
			if ( 'percent' === $clean['get_discount_type']
				&& 0.0 < (float) $clean['get_discount_value']
				&& false !== stripos( $clean['offer_title'], __( 'free', 'bogo-select' ) )
			) {
				add_settings_error(
					BOGO_Select_Settings::OPTION,
					'bogo_select_stale_title',
					sprintf(
						/* translators: %s: the offer title as saved. */
						__( 'The reward is discounted rather than free, but the offer title still says “%s”. Customers see that heading above the chooser.', 'bogo-select' ),
						$clean['offer_title']
					),
					'warning'
				);
			}
		}

		return $clean;
	}

	/**
	 * Refuse a schedule that cannot mean what it says, keeping the stored one.
	 *
	 * WordPress draws the message add_settings_error() records and then writes
	 * the option regardless, so the previous code showed "this window can never
	 * run" and saved that window anyway (CODEX-REVIEW.md M-01). Two submissions
	 * are refused here rather than described:
	 *
	 * - A non-empty date that is not a date. Clearing a field is how a store
	 *   says "no bound on this side", and a typo must not be read as that
	 *   request — it would silently widen a campaign rather than narrow it.
	 * - An end date before the start date, which can never run. Both bounds are
	 *   put back, since either one of them could be the one that was mistyped.
	 *
	 * Everything else in the submission still saves. The schedule is a single
	 * setting the store can correct on its own; refusing the whole form would
	 * throw away unrelated edits made in the same visit.
	 *
	 * @param mixed               $raw    Raw form input.
	 * @param array<string,mixed> $clean  Sanitized settings.
	 * @param array<string,mixed> $stored Settings as they are stored now.
	 * @return array<string,mixed> The settings to save.
	 */
	protected function keep_last_valid_schedule( $raw, $clean, $stored ) {
		$raw = is_array( $raw ) ? $raw : array();

		foreach ( array( 'start_date', 'end_date' ) as $field ) {
			$submitted = isset( $raw[ $field ] ) && is_scalar( $raw[ $field ] ) ? trim( (string) $raw[ $field ] ) : '';

			// Sanitization turns anything unreadable into an empty string. A
			// submission that was not empty to begin with and is empty now is a
			// date the store meant to set and mistyped.
			if ( '' === $submitted || '' !== $clean[ $field ] ) {
				continue;
			}

			$kept                = isset( $stored[ $field ] ) ? (string) $stored[ $field ] : '';
			$clean[ $field ]     = $kept;
			$replacement_message = '' === $kept
				/* translators: 1: submitted value, 2: field name. */
				? __( '“%1$s” is not a date the %2$s could be set to, so that side of the schedule is still unbounded. Dates are entered as YYYY-MM-DD.', 'bogo-select' )
				/* translators: 1: submitted value, 2: field name, 3: the date that was kept. */
				: __( '“%1$s” is not a date the %2$s could be set to, so it is unchanged at %3$s. Clear the field to remove the bound.', 'bogo-select' );

			add_settings_error(
				BOGO_Select_Settings::OPTION,
				'bogo_select_invalid_date',
				sprintf(
					$replacement_message,
					sanitize_text_field( $submitted ),
					'start_date' === $field ? __( 'start date', 'bogo-select' ) : __( 'end date', 'bogo-select' ),
					$kept
				),
				'error'
			);
		}

		// A window that ends before it begins can never run, and the fields give
		// no other sign of it.
		if ( '' !== $clean['start_date'] && '' !== $clean['end_date'] && $clean['end_date'] < $clean['start_date'] ) {
			add_settings_error(
				BOGO_Select_Settings::OPTION,
				'bogo_select_backwards_window',
				sprintf(
					/* translators: 1: end date, 2: start date, 3: the schedule that was kept instead. */
					__( 'The offer would have ended on %1$s, before it started on %2$s, so it could never run. The schedule is unchanged: %3$s', 'bogo-select' ),
					$clean['end_date'],
					$clean['start_date'],
					$this->schedule_sentence( $stored['start_date'], $stored['end_date'] )
				),
				'error'
			);

			$clean['start_date'] = $stored['start_date'];
			$clean['end_date']   = $stored['end_date'];
		}

		return $clean;
	}

	/**
	 * How a stored schedule reads when a rejected one has to name it.
	 *
	 * @param string $start Start date, or an empty string.
	 * @param string $end   End date, or an empty string.
	 * @return string
	 */
	protected function schedule_sentence( $start, $end ) {
		$sentence = self::window_sentence( $start, $end );

		return '' !== $sentence ? $sentence : __( 'the offer has no start or end date.', 'bogo-select' );
	}

	/**
	 * Let anyone who can reach this screen save it.
	 *
	 * The menu and the renderer both ask for `manage_woocommerce`, which is the
	 * capability a Shop Manager has and the one WooCommerce's own settings use.
	 * options.php asks for `manage_options` unless told otherwise, so without
	 * this a Shop Manager could open the page, fill it in, and be refused on
	 * save (CODEX-REVIEW.md M-02). One capability now governs both halves.
	 *
	 * @return string
	 */
	public function settings_capability() {
		return 'manage_woocommerce';
	}

	/**
	 * Load select2 and our admin assets on this screen only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue( $hook ) {
		if ( 'woocommerce_page_' . self::SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_script( 'wc-enhanced-select' );

		wp_enqueue_style(
			'bogo-select-admin',
			BOGO_SELECT_URL . 'assets/css/bogo-select-admin.css',
			array(),
			BOGO_SELECT_VERSION
		);

		wp_enqueue_script(
			'bogo-select-admin',
			BOGO_SELECT_URL . 'assets/js/bogo-select-admin.js',
			array( 'jquery' ),
			BOGO_SELECT_VERSION,
			true
		);
	}

	/**
	 * Add a Settings link on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=' . self::SLUG );

		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'bogo-select' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Render the settings page.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$s = BOGO_Select_Settings::all();
		$o = BOGO_Select_Settings::OPTION;
		?>
		<div class="wrap bogo-select-admin">
			<h1><?php esc_html_e( 'BOGO Select', 'bogo-select' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Buy X, get Y — the customer chooses their reward from the list you set here. The reward is free or discounted, as set below, and stock is still reduced either way.', 'bogo-select' ); ?>
			</p>

			<?php settings_errors( $o ); ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<h2 class="title"><?php esc_html_e( 'Offer', 'bogo-select' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable offer', 'bogo-select' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $o ); ?>[enabled]" value="yes" <?php checked( 'yes', $s['enabled'] ); ?> />
								<?php esc_html_e( 'Run this BOGO promotion on the storefront', 'bogo-select' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="bogo-offer-title"><?php esc_html_e( 'Offer title', 'bogo-select' ); ?></label>
						</th>
						<td>
							<input type="text" class="regular-text" id="bogo-offer-title"
								name="<?php echo esc_attr( $o ); ?>[offer_title]"
								value="<?php echo esc_attr( $s['offer_title'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Heading shown above the gift chooser on the cart page.', 'bogo-select' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="bogo-start-date"><?php esc_html_e( 'Start date', 'bogo-select' ); ?></label>
						</th>
						<td>
							<input type="date" id="bogo-start-date"
								name="<?php echo esc_attr( $o ); ?>[start_date]"
								value="<?php echo esc_attr( $s['start_date'] ); ?>" />
							<p class="description"><?php esc_html_e( 'The first day the offer runs. Leave empty to start immediately.', 'bogo-select' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="bogo-end-date"><?php esc_html_e( 'End date', 'bogo-select' ); ?></label>
						</th>
						<td>
							<input type="date" id="bogo-end-date"
								name="<?php echo esc_attr( $o ); ?>[end_date]"
								value="<?php echo esc_attr( $s['end_date'] ); ?>" />
							<p class="description">
								<?php
								printf(
									/* translators: %s: today's date in the site's timezone. */
									esc_html__( 'The last day the offer runs, and it runs all of that day. Leave empty to run until you switch it off. Today is %s in your store\'s timezone.', 'bogo-select' ),
									esc_html( BOGO_Select_Engine::today() )
								);
								?>
							</p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Quantities', 'bogo-select' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="bogo-buy-qty"><?php esc_html_e( 'Buy quantity', 'bogo-select' ); ?></label>
						</th>
						<td>
							<input type="number" min="1" step="1" class="small-text" id="bogo-buy-qty"
								name="<?php echo esc_attr( $o ); ?>[buy_qty]"
								value="<?php echo esc_attr( $s['buy_qty'] ); ?>" />
							<p class="description"><?php esc_html_e( 'How many qualifying units must be in the cart. Quantities are added up across the whole cart.', 'bogo-select' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="bogo-get-qty"><?php esc_html_e( 'Get quantity', 'bogo-select' ); ?></label>
						</th>
						<td>
							<input type="number" min="1" step="1" class="small-text" id="bogo-get-qty"
								name="<?php echo esc_attr( $o ); ?>[get_qty]"
								value="<?php echo esc_attr( $s['get_qty'] ); ?>" />
							<p class="description"><?php esc_html_e( 'How many units the customer receives of the gift they choose.', 'bogo-select' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Reward price', 'bogo-select' ); ?></th>
						<td>
							<fieldset class="bogo-discount" data-target="bogo-discount-value">
								<label>
									<input type="radio" name="<?php echo esc_attr( $o ); ?>[get_discount_type]" value="free" <?php checked( 'free', $s['get_discount_type'] ); ?> />
									<?php esc_html_e( 'Free', 'bogo-select' ); ?>
								</label><br />
								<label>
									<input type="radio" name="<?php echo esc_attr( $o ); ?>[get_discount_type]" value="percent" <?php checked( 'percent', $s['get_discount_type'] ); ?> />
									<?php esc_html_e( 'Percentage off', 'bogo-select' ); ?>
								</label>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Whether the reward is given away or sold at a discount.', 'bogo-select' ); ?></p>
						</td>
					</tr>
					<tr class="bogo-discount-row" id="bogo-discount-value">
						<th scope="row">
							<label for="bogo-discount-value-field"><?php esc_html_e( 'Discount', 'bogo-select' ); ?></label>
						</th>
						<td>
							<input type="number" min="0" max="100" step="0.01" class="small-text" id="bogo-discount-value-field"
								name="<?php echo esc_attr( $o ); ?>[get_discount_value]"
								value="<?php echo esc_attr( $s['get_discount_value'] ); ?>" />
							<span class="description">%</span>
							<p class="description"><?php esc_html_e( 'Taken off the reward\'s usual price. 100% is the same as giving it away.', 'bogo-select' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Repeat offer', 'bogo-select' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $o ); ?>[repeat]" value="yes" <?php checked( 'yes', $s['repeat'] ); ?> />
								<?php esc_html_e( 'Award another set of reward items for every multiple of the Buy quantity', 'bogo-select' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Off: one reward set no matter how much is bought. On: Buy 2 Get 1 means six qualifying items earn three rewards.', 'bogo-select' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Buy products', 'bogo-select' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Which products count toward the Buy quantity.', 'bogo-select' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Applies to', 'bogo-select' ); ?></th>
						<td>
							<fieldset class="bogo-scope" data-target="bogo-buy-products">
								<label>
									<input type="radio" name="<?php echo esc_attr( $o ); ?>[buy_scope]" value="all" <?php checked( 'all', $s['buy_scope'] ); ?> />
									<?php esc_html_e( 'All Products', 'bogo-select' ); ?>
								</label><br />
								<label>
									<input type="radio" name="<?php echo esc_attr( $o ); ?>[buy_scope]" value="select" <?php checked( 'select', $s['buy_scope'] ); ?> />
									<?php esc_html_e( 'Select Products', 'bogo-select' ); ?>
								</label>
							</fieldset>
						</td>
					</tr>
					<tr class="bogo-products-row" id="bogo-buy-products">
						<th scope="row">
							<label for="bogo-buy-products-field"><?php esc_html_e( 'Qualifying products', 'bogo-select' ); ?></label>
						</th>
						<td>
							<?php
							$this->product_select(
								$o . '[buy_products][]',
								'bogo-buy-products-field',
								$s['buy_products'],
								__( 'Search for products or variations…', 'bogo-select' ),
								// Offers individual variations as well as products, so an
								// offer can turn on one size rather than every size.
								'woocommerce_json_search_products_and_variations'
							);
							?>
							<p class="description"><?php esc_html_e( 'Only these count toward qualifying. Add a product to count every variation of it, or one specific variation to count only that one.', 'bogo-select' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Get products', 'bogo-select' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Which products the customer may choose as their reward.', 'bogo-select' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Applies to', 'bogo-select' ); ?></th>
						<td>
							<fieldset class="bogo-scope" data-target="bogo-get-products">
								<label>
									<input type="radio" name="<?php echo esc_attr( $o ); ?>[get_scope]" value="all" <?php checked( 'all', $s['get_scope'] ); ?> />
									<?php esc_html_e( 'All Products', 'bogo-select' ); ?>
								</label><br />
								<label>
									<input type="radio" name="<?php echo esc_attr( $o ); ?>[get_scope]" value="select" <?php checked( 'select', $s['get_scope'] ); ?> />
									<?php esc_html_e( 'Select Products', 'bogo-select' ); ?>
								</label>
							</fieldset>
						</td>
					</tr>
					<tr class="bogo-products-row" id="bogo-get-products">
						<th scope="row">
							<label for="bogo-get-products-field"><?php esc_html_e( 'Gift options', 'bogo-select' ); ?></label>
						</th>
						<td>
							<?php
							$this->product_select(
								$o . '[get_products][]',
								'bogo-get-products-field',
								$s['get_products'],
								__( 'Search for products or variations…', 'bogo-select' ),
								// Offers individual variations as well as products, so a
								// single size can be pinned as the reward.
								'woocommerce_json_search_products_and_variations'
							);
							?>
							<p class="description"><?php esc_html_e( 'Add a simple product, a variable product — the customer then picks the option — or one specific variation to pin the reward to it. Grouped and external products are removed when you save, as is anything that could not be given as it stands.', 'bogo-select' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Display', 'bogo-select' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Shop notice', 'bogo-select' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( $o ); ?>[show_notice]" value="yes" <?php checked( 'yes', $s['show_notice'] ); ?> />
								<?php esc_html_e( 'Tell qualifying customers on shop and product pages that a gift is waiting in their cart', 'bogo-select' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<h2 class="title"><?php esc_html_e( 'Current offer', 'bogo-select' ); ?></h2>
			<p class="bogo-select-summary"><?php echo esc_html( $this->summary( $s ) ); ?></p>
		</div>
		<?php
	}

	/**
	 * Render a WooCommerce product search multi-select.
	 *
	 * @param string $name        Field name.
	 * @param string $id          Field id.
	 * @param int[]  $selected    Selected product IDs.
	 * @param string $placeholder Placeholder text.
	 * @param string $action      WooCommerce AJAX search action backing the field.
	 * @return void
	 */
	protected function product_select( $name, $id, $selected, $placeholder, $action = 'woocommerce_json_search_products' ) {
		?>
		<select class="wc-product-search bogo-product-search"
			multiple="multiple"
			style="width: 400px;"
			id="<?php echo esc_attr( $id ); ?>"
			name="<?php echo esc_attr( $name ); ?>"
			data-placeholder="<?php echo esc_attr( $placeholder ); ?>"
			data-action="<?php echo esc_attr( $action ); ?>">
			<?php
			foreach ( (array) $selected as $product_id ) {
				$product = wc_get_product( $product_id );

				if ( ! $product ) {
					continue;
				}

				printf(
					'<option value="%1$s" selected="selected">%2$s</option>',
					esc_attr( (string) $product_id ),
					esc_html( wp_strip_all_tags( $product->get_formatted_name() ) )
				);
			}
			?>
		</select>
		<?php
	}

	/**
	 * A plain-English summary of the saved offer.
	 *
	 * @param array<string,mixed> $s Settings.
	 * @return string
	 */
	protected function summary( $s ) {
		if ( 'yes' !== $s['enabled'] ) {
			return __( 'The offer is switched off. Nothing is shown on the storefront.', 'bogo-select' );
		}

		$buy_count = $this->distinct_buy_selections( $s['buy_products'] );

		$buy_scope = 'all' === $s['buy_scope']
			? __( 'any product', 'bogo-select' )
			: sprintf(
				/* translators: %d: number of products. */
				_n( '%d selected product', '%d selected products', $buy_count, 'bogo-select' ),
				$buy_count
			);

		$get_scope = 'all' === $s['get_scope']
			? __( 'any product', 'bogo-select' )
			: sprintf(
				/* translators: %d: number of products. */
				_n( '%d gift option', '%d gift options', count( $s['get_products'] ), 'bogo-select' ),
				count( $s['get_products'] )
			);

		$summary = sprintf(
			/* translators: 1: buy quantity, 2: buy scope, 3: get quantity, 4: what they cost, 5: get scope. */
			__( 'Buy %1$d of %2$s, then choose %3$d %4$s from %5$s.', 'bogo-select' ),
			(int) $s['buy_qty'],
			$buy_scope,
			(int) $s['get_qty'],
			BOGO_Select_Engine::reward_phrase(),
			$get_scope
		);

		if ( 'yes' === $s['repeat'] ) {
			$summary .= ' ' . __( 'Repeats for every multiple of the Buy quantity.', 'bogo-select' );
		}

		$summary .= ' ' . self::window_sentence( $s['start_date'], $s['end_date'] );

		return trim( $summary );
	}

	/**
	 * How many things a Buy list actually selects.
	 *
	 * A listed variation whose parent is also listed selects nothing the parent
	 * did not already select, so counting the raw array called one parent-wide
	 * choice plus one redundant entry "2 selected products"
	 * (CODEX-REVIEW.md L-05). The list is what the store typed, and is stored
	 * as typed; only the sentence describing it is corrected.
	 *
	 * @param int[] $ids Buy list.
	 * @return int
	 */
	protected function distinct_buy_selections( $ids ) {
		$ids   = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) );
		$count = 0;

		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );

			if ( $product && $product->is_type( 'variation' ) && in_array( (int) $product->get_parent_id(), $ids, true ) ) {
				continue;
			}

			++$count;
		}

		return $count;
	}
}
