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
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ), 20 );
		add_filter( 'plugin_action_links_' . BOGO_SELECT_BASENAME, array( $this, 'action_links' ) );
	}

	/**
	 * Add the submenu page under WooCommerce.
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
	 * Sanitize submitted settings and warn about unusable choices.
	 *
	 * @param mixed $raw Raw form input.
	 * @return array
	 */
	public function sanitize( $raw ) {
		$clean = BOGO_Select_Settings::sanitize( $raw );

		// Gifts must be addable without a variation choice (DECISION.md D-006).
		$rejected = array();

		$clean['get_products'] = array_values(
			array_filter(
				$clean['get_products'],
				function ( $product_id ) use ( &$rejected ) {
					$product = wc_get_product( $product_id );

					if ( ! $product ) {
						return false;
					}

					if ( $product->is_type( 'variable' ) || $product->is_type( 'variation' ) || $product->is_type( 'grouped' ) || $product->is_type( 'external' ) ) {
						$rejected[] = $product->get_name();
						return false;
					}

					return true;
				}
			)
		);

		if ( $rejected ) {
			add_settings_error(
				BOGO_Select_Settings::OPTION,
				'bogo_select_variable_gift',
				sprintf(
					/* translators: %s: comma-separated product names. */
					__( 'Removed from the gift list because variable, variation, grouped, and external products cannot be given as gifts: %s.', 'bogo-select' ),
					implode( ', ', array_map( 'sanitize_text_field', $rejected ) )
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
	 * Load select2 and our admin assets on this screen only.
	 *
	 * @param string $hook Current admin page hook.
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
	 * @param array $links Existing links.
	 * @return array
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
								__( 'Search for products…', 'bogo-select' )
							);
							?>
							<p class="description"><?php esc_html_e( 'Only these products count toward qualifying. Variations count if either the variation or its parent is listed.', 'bogo-select' ); ?></p>
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
								__( 'Search for products…', 'bogo-select' )
							);
							?>
							<p class="description"><?php esc_html_e( 'Simple products only — variable, grouped, and external products, and individual variations, are removed when you save, because a gift must be addable without choosing options.', 'bogo-select' ); ?></p>
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
	 */
	protected function product_select( $name, $id, $selected, $placeholder ) {
		?>
		<select class="wc-product-search bogo-product-search"
			multiple="multiple"
			style="width: 400px;"
			id="<?php echo esc_attr( $id ); ?>"
			name="<?php echo esc_attr( $name ); ?>"
			data-placeholder="<?php echo esc_attr( $placeholder ); ?>"
			data-action="woocommerce_json_search_products">
			<?php
			foreach ( (array) $selected as $product_id ) {
				$product = wc_get_product( $product_id );

				if ( ! $product ) {
					continue;
				}

				printf(
					'<option value="%1$s" selected="selected">%2$s</option>',
					esc_attr( $product_id ),
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
	 * @param array $s Settings.
	 * @return string
	 */
	protected function summary( $s ) {
		if ( 'yes' !== $s['enabled'] ) {
			return __( 'The offer is switched off. Nothing is shown on the storefront.', 'bogo-select' );
		}

		$buy_scope = 'all' === $s['buy_scope']
			? __( 'any product', 'bogo-select' )
			: sprintf(
				/* translators: %d: number of products. */
				_n( '%d selected product', '%d selected products', count( $s['buy_products'] ), 'bogo-select' ),
				count( $s['buy_products'] )
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

		return $summary;
	}
}
