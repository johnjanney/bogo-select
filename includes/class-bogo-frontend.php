<?php
/**
 * Customer-facing chooser and notices.
 *
 * @package BOGO_Select
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the gift chooser on the cart and checkout pages, and the notice
 * elsewhere.
 *
 * The chooser always lives inside a slot element. Classic templates print the
 * slot through template hooks and block templates get it through the block
 * renderer (BOGO_Select_Blocks); either way the JavaScript has one mount point
 * whose contents it can replace when the cart changes underneath it.
 */
class BOGO_Select_Frontend {

	/**
	 * Whether the slot has already been printed in this request.
	 *
	 * @var bool
	 */
	protected static $slot_rendered = false;

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );

		// Classic templates: above the cart table, and above the checkout form.
		add_action( 'woocommerce_before_cart_table', array( $this, 'render_chooser' ) );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'render_checkout_chooser' ), 5 );

		add_action( 'woocommerce_before_main_content', array( $this, 'maybe_render_notice' ), 20 );
	}

	/**
	 * Load assets on the cart and checkout pages.
	 *
	 * Block-based cart and checkout pages are still is_cart()/is_checkout(),
	 * because WooCommerce identifies them by page ID. A cart or checkout block
	 * dropped on some other page enqueues these from the block renderer
	 * instead.
	 *
	 * @return void
	 */
	public function enqueue() {
		if ( ! function_exists( 'is_cart' ) || ! ( is_cart() || is_checkout() ) ) {
			return;
		}

		// The order-received and pay-for-order screens are checkout pages with
		// nothing left to choose.
		if ( function_exists( 'is_order_received_page' ) && ( is_order_received_page() || is_checkout_pay_page() ) ) {
			return;
		}

		self::enqueue_assets();
	}

	/**
	 * Register and enqueue the chooser assets.
	 *
	 * Safe to call more than once, and late enough to be called while a block
	 * is rendering.
	 *
	 * @return void
	 */
	public static function enqueue_assets() {
		if ( wp_script_is( 'bogo-select', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_style(
			'bogo-select',
			BOGO_SELECT_URL . 'assets/css/bogo-select.css',
			array(),
			BOGO_SELECT_VERSION
		);

		/*
		 * No dependencies are declared on the WooCommerce Blocks bundles. A
		 * block cart loads them for its own sake, and the script waits for
		 * them; declaring them would drag that whole bundle onto classic cart
		 * pages that will never use it.
		 */
		wp_enqueue_script(
			'bogo-select',
			BOGO_SELECT_URL . 'assets/js/bogo-select.js',
			array(),
			BOGO_SELECT_VERSION,
			true
		);

		wp_localize_script(
			'bogo-select',
			'bogoSelect',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'bogo-select' ),
				'namespace' => 'bogo-select',
				'i18n'      => array(
					'working' => __( 'Adding…', 'bogo-select' ),
					'error'   => __( 'Something went wrong. Please refresh and try again.', 'bogo-select' ),
					'confirm' => sprintf(
						/* translators: %s: what the reward is called, e.g. "free gift". */
						__( 'Remove your %s?', 'bogo-select' ),
						BOGO_Select_Engine::reward_noun()
					),
					'loading' => __( 'Loading gifts…', 'bogo-select' ),
					/* translators: 1: current page, 2: total pages. */
					'pageOf'  => __( 'Page %1$d of %2$d', 'bogo-select' ),
					'noPages' => __( 'No results', 'bogo-select' ),
				),
			)
		);
	}

	/**
	 * Print the chooser slot, if the offer is running at all.
	 *
	 * The slot is printed even when the cart does not currently qualify: in a
	 * block cart the customer can cross the threshold without a page load, and
	 * the JavaScript fills the empty slot when they do.
	 *
	 * @return void
	 */
	public function render_chooser() {
		echo self::slot_html( 'classic' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped fragments.
	}

	/**
	 * Print the chooser slot above the classic checkout form.
	 *
	 * @return void
	 */
	public function render_checkout_chooser() {
		echo self::slot_html( 'checkout' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped fragments.
	}

	/**
	 * The chooser slot: a stable mount point wrapping the current chooser.
	 *
	 * The mode travels with the markup because the three places the chooser
	 * appears need different things after a gift changes:
	 *
	 * - classic: reload, so the cart table, totals, and theme fragments catch
	 *   up from PHP;
	 * - checkout: never reload — that would empty the customer's half-filled
	 *   checkout form — so refresh the chooser and ask WooCommerce to update
	 *   the order review instead;
	 * - block: the Cart and Checkout blocks re-render themselves from the
	 *   Store API response, so nothing else is needed.
	 *
	 * Printing the slot is also what enqueues the assets, because a chooser
	 * without its script is a dead chooser: the buttons render, and nothing
	 * answers them. `wp_enqueue_scripts` cannot be trusted to have decided this
	 * on its own — it fires before anything knows where the cart template will
	 * be rendered, and `is_cart()` misses a cart the theme or a page builder
	 * renders somewhere WooCommerce does not recognise. The hook still runs
	 * first on ordinary cart and checkout pages, which is what keeps the
	 * stylesheet in the head rather than the footer; this is the safety net
	 * under it, and enqueuing twice costs nothing.
	 *
	 * @param string $mode 'classic', 'checkout', or 'block'.
	 * @return string Empty string when the offer is switched off.
	 */
	public static function slot_html( $mode = 'classic' ) {
		if ( ! BOGO_Select_Engine::is_active() || self::$slot_rendered ) {
			return '';
		}

		self::enqueue_assets();

		self::$slot_rendered = true;

		$mode = in_array( $mode, array( 'block', 'checkout' ), true ) ? $mode : 'classic';

		return sprintf(
			'<div class="bogo-select-slot" data-bogo-slot="1" data-bogo-mode="%s">%s</div>',
			esc_attr( $mode ),
			self::chooser_html()
		);
	}

	/**
	 * Whether the slot has been printed in this request.
	 *
	 * @return bool
	 */
	public static function slot_rendered() {
		return self::$slot_rendered;
	}

	/**
	 * Forget that the slot has been printed.
	 *
	 * Only one chooser belongs on a page, and the guard that enforces that is
	 * per-process — which is fine for a page load, and wrong for anything that
	 * renders several in one process, such as the unit suite or WP-CLI.
	 *
	 * @return void
	 */
	public static function forget_slot() {
		self::$slot_rendered = false;
	}

	/**
	 * The chooser itself.
	 *
	 * @return string Empty string when there is nothing to choose right now.
	 */
	public static function chooser_html() {
		ob_start();
		self::print_chooser();

		return (string) ob_get_clean();
	}

	/**
	 * Render the chooser panel.
	 *
	 * @return void
	 */
	protected static function print_chooser() {
		if ( ! BOGO_Select_Engine::is_active() ) {
			return;
		}

		$reward_qty = BOGO_Select_Engine::reward_quantity_for_cart();

		if ( $reward_qty < 1 ) {
			return;
		}

		$results = BOGO_Select_Engine::get_choice_page();
		$paged   = $results['pages'] > 1;

		if ( ! $results['ids'] && ! $paged ) {
			return;
		}

		$selected = BOGO_Select_Engine::selected_product_id();
		$title    = BOGO_Select_Settings::get( 'offer_title' );

		?>
		<div class="bogo-select" id="bogo-select"
			data-page="<?php echo esc_attr( (string) $results['page'] ); ?>"
			data-pages="<?php echo esc_attr( (string) $results['pages'] ); ?>">
			<div class="bogo-select__header">
				<h3 class="bogo-select__title"><?php echo esc_html( $title ); ?></h3>
				<p class="bogo-select__subtitle">
					<?php
					if ( $selected ) {
						printf(
							/* translators: 1: number of units, 2: what they cost, e.g. "free" or "at 50% off". */
							esc_html__( 'You are getting %1$d %2$s — pick a different gift below to change your mind.', 'bogo-select' ),
							(int) $reward_qty,
							esc_html( BOGO_Select_Engine::reward_phrase() )
						);
					} else {
						printf(
							/* translators: 1: number of units, 2: what they cost, 3: number of options. */
							esc_html__( 'Your cart qualifies for %1$d %2$s — choose 1 of %3$d options below.', 'bogo-select' ),
							(int) $reward_qty,
							esc_html( BOGO_Select_Engine::reward_phrase() ),
							(int) $results['total']
						);
					}
					?>
				</p>
			</div>

			<?php if ( $paged ) : ?>
				<div class="bogo-select__search">
					<label for="bogo-select-search"><?php esc_html_e( 'Search gifts', 'bogo-select' ); ?></label>
					<input type="search" id="bogo-select-search" class="bogo-select__search-input"
						autocomplete="off"
						data-bogo-search="1"
						placeholder="<?php esc_attr_e( 'Search by name or SKU…', 'bogo-select' ); ?>" />
				</div>
			<?php endif; ?>

			<ul class="bogo-select__grid" data-bogo-grid="1">
				<?php
				// Server-rendered, already escaped card markup.
				echo self::render_choices( $results['ids'], $reward_qty, $selected ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				?>
			</ul>

			<p class="bogo-select__empty" data-bogo-empty="1" hidden></p>

			<?php if ( $paged ) : ?>
				<nav class="bogo-select__pagination" data-bogo-pagination="1">
					<button type="button" class="button bogo-select__page" data-bogo-page="prev" disabled="disabled">
						<?php esc_html_e( 'Previous', 'bogo-select' ); ?>
					</button>
					<span class="bogo-select__page-status" data-bogo-page-status="1" role="status">
						<?php
						printf(
							/* translators: 1: current page, 2: total pages. */
							esc_html__( 'Page %1$d of %2$d', 'bogo-select' ),
							(int) $results['page'],
							(int) $results['pages']
						);
						?>
					</span>
					<button type="button" class="button bogo-select__page" data-bogo-page="next">
						<?php esc_html_e( 'Next', 'bogo-select' ); ?>
					</button>
				</nav>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render one page of gift cards.
	 *
	 * Static so the AJAX paging endpoint returns markup identical to the initial
	 * server render.
	 *
	 * @param int[]    $product_ids Products to render.
	 * @param int      $reward_qty  Free units on offer.
	 * @param int|null $selected    Currently chosen product ID, or null to look it up.
	 * @return string Escaped HTML for the chooser grid.
	 */
	public static function render_choices( $product_ids, $reward_qty, $selected = null ) {
		if ( null === $selected ) {
			$selected = BOGO_Select_Engine::selected_product_id();
		}

		$ids        = array_map( 'intval', (array) $product_ids );
		$reward_qty = (int) $reward_qty;
		$selected   = (int) $selected;

		// Which card owns the selection is decided once, here, because it is the
		// only place the whole list is visible. A card cannot answer it alone:
		// two variations of one parent, each pinned as its own card, look
		// identical to a card that knows only its own reward pair.
		$owner = self::selected_card_id( $ids, $selected, BOGO_Select_Engine::selected_variation_id() );

		ob_start();

		foreach ( $ids as $product_id ) {
			self::print_choice( $product_id, $reward_qty, $owner, $selected );
		}

		return (string) ob_get_clean();
	}

	/**
	 * The card that should show as selected.
	 *
	 * A variation pinned as its own card wins over the parent card that could
	 * also offer it, being the more specific of the two. Everything else is named
	 * by the product ID the card was built from.
	 *
	 * @param int[] $ids       Product IDs on this page.
	 * @param int   $product   Selected product ID; the parent, for a variation.
	 * @param int   $variation Selected variation ID, or 0.
	 * @return int Zero when nothing on this page is selected.
	 */
	protected static function selected_card_id( $ids, $product, $variation ) {
		if ( ! $product ) {
			return 0;
		}

		if ( $variation && in_array( $variation, $ids, true ) ) {
			return $variation;
		}

		return $product;
	}

	/**
	 * Render one card.
	 *
	 * @param int $product_id Product to render.
	 * @param int $reward_qty Units on offer.
	 * @param int $owner      Product ID of the card that owns the selection, or 0.
	 * @param int $selected   Currently chosen product ID, parent for a variation.
	 * @return void
	 */
	protected static function print_choice( $product_id, $reward_qty, $owner, $selected ) {
		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return;
		}

		$is_variable = $product->is_type( 'variable' );

		// A card names the pair it would award. For a variable product that is
		// settled by the customer, so the card carries only the parent and the
		// selector supplies the rest.
		list( $card_product_id, $card_variation_id ) = BOGO_Select_Engine::reward_pair( $product_id );

		// Compared against the owning card rather than against the parent ID: two
		// pinned siblings share a parent, and comparing parents marked both
		// selected while leaving neither able to reach the other
		// (`CODEX-REVIEW.md` M-01).
		$is_selected = ( $product_id === $owner );

		$selected_variation = $is_selected ? BOGO_Select_Engine::selected_variation_id() : 0;

		// The reward being replaced is not competing with its own replacement.
		$exclude = $is_selected ? BOGO_Select_Engine::find_reward_key() : '';

		if ( $is_variable ) {
			$options = self::variation_options( $product, $reward_qty, $exclude );
			$reason  = self::variable_reason( $options );

			// Quote the option the customer is looking at, not the parent, whose
			// price is the low end of a range and need not be any variation's.
			$priced = self::default_option( $options, $selected_variation );
			$priced = $priced ? $priced['product'] : $product;
		} else {
			$options      = array();
			$priced       = $product;
			$other_demand = BOGO_Select_Engine::stock_demand( null, $product, $exclude );
			$reason       = BOGO_Select_Engine::unavailable_reason( $product, $reward_qty, $other_demand );
		}

		$classes = array( 'bogo-select__item' );

		if ( $is_selected ) {
			$classes[] = 'is-selected';
		}

		if ( $reason && ! $is_selected ) {
			$classes[] = 'is-unavailable';
		}
		?>
		<li class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
			data-bogo-card="<?php echo esc_attr( (string) $product_id ); ?>">
			<div class="bogo-select__thumb">
				<?php echo wp_kses_post( $product->get_image( 'woocommerce_thumbnail' ) ); ?>
			</div>
			<div class="bogo-select__info">
				<span class="bogo-select__name"><?php echo esc_html( $product->get_name() ); ?></span>
				<span class="bogo-select__price" data-bogo-price="1">
					<?php echo wp_kses_post( self::price_markup( $priced ) ); ?>
				</span>
				<?php if ( $is_variable && $options ) : ?>
					<label class="bogo-select__variation-label" for="bogo-select-variation-<?php echo esc_attr( (string) $product_id ); ?>">
						<?php esc_html_e( 'Choose an option', 'bogo-select' ); ?>
					</label>
					<select class="bogo-select__variation" data-bogo-variation="1"
						id="bogo-select-variation-<?php echo esc_attr( (string) $product_id ); ?>">
						<?php foreach ( $options as $option ) : ?>
							<option value="<?php echo esc_attr( $option['id'] ); ?>"
								data-price="<?php echo esc_attr( $option['price'] ); ?>"
								<?php disabled( true, (bool) $option['reason'] ); ?>
								<?php selected( $selected_variation, $option['id'] ); ?>>
								<?php echo esc_html( $option['label'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				<?php endif; ?>
				<?php if ( $reason && ! $is_selected ) : ?>
					<span class="bogo-select__reason"><?php echo esc_html( $reason ); ?></span>
				<?php endif; ?>
			</div>
			<div class="bogo-select__actions">
				<?php if ( $is_selected ) : ?>
					<span class="bogo-select__selected"><?php esc_html_e( 'Selected', 'bogo-select' ); ?></span>
					<?php if ( $is_variable && $options ) : ?>
						<button type="button" class="button bogo-select__choose"
							data-product-id="<?php echo esc_attr( (string) $card_product_id ); ?>">
							<?php esc_html_e( 'Change option', 'bogo-select' ); ?>
						</button>
					<?php endif; ?>
					<button type="button" class="bogo-select__remove" data-bogo-remove="1">
						<?php esc_html_e( 'Remove gift', 'bogo-select' ); ?>
					</button>
				<?php elseif ( $reason ) : ?>
					<button type="button" class="button" disabled="disabled" data-permanently-disabled="1">
						<?php esc_html_e( 'Unavailable', 'bogo-select' ); ?>
					</button>
				<?php else : ?>
					<button type="button" class="button bogo-select__choose"
						data-product-id="<?php echo esc_attr( (string) $card_product_id ); ?>"
						data-variation-id="<?php echo esc_attr( (string) $card_variation_id ); ?>">
						<?php echo $selected ? esc_html__( 'Choose this instead', 'bogo-select' ) : esc_html__( 'Select', 'bogo-select' ); ?>
					</button>
				<?php endif; ?>
			</div>
		</li>
		<?php
	}

	/**
	 * What a reward costs, struck through against what it would have cost.
	 *
	 * @param WC_Product $product Product being quoted.
	 * @return string
	 */
	protected static function price_markup( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return '';
		}

		$regular = wc_get_price_to_display( $product );
		$out     = '';

		if ( $regular > 0 ) {
			$out .= '<del aria-hidden="true">' . wc_price( $regular ) . '</del> ';
		}

		if ( BOGO_Select_Engine::is_free_reward() ) {
			return $out . '<strong>' . esc_html__( 'Free', 'bogo-select' ) . '</strong>';
		}

		return $out . '<strong>' . wc_price(
			wc_get_price_to_display(
				$product,
				array( 'price' => BOGO_Select_Engine::reward_price( (float) $product->get_price() ) )
			)
		) . '</strong>';
	}

	/**
	 * The variations a parent can offer, each with its own availability.
	 *
	 * @param WC_Product $parent     Variable parent.
	 * @param int        $reward_qty Units on offer.
	 * @param string     $exclude    Cart item key to leave out of stock demand.
	 * @return array<int,array<string,mixed>> Each with id, label, and reason.
	 */
	protected static function variation_options( $parent, $reward_qty, $exclude = '' ) {
		$options = array();

		foreach ( BOGO_Select_Engine::offerable_variations( $parent ) as $variation ) {
			$variation_id = $variation->get_id();
			$demand       = BOGO_Select_Engine::stock_demand( null, $variation, $exclude );
			$reason = BOGO_Select_Engine::unavailable_reason( $variation, $reward_qty, $demand );

			$options[] = array(
				'id'      => (int) $variation_id,
				'product' => $variation,
				// Built here, from the object already in hand. The option loop
				// below would otherwise reload every variation to price it, and
				// the card would reload one more to quote it (M-02).
				'price'   => self::price_markup( $variation ),
				'reason'  => $reason,
				'label'   => $reason
					? sprintf(
						/* translators: 1: variation name, 2: why it cannot be given. */
						__( '%1$s — %2$s', 'bogo-select' ),
						$variation->get_name(),
						$reason
					)
					: $variation->get_name(),
			);
		}

		return $options;
	}

	/**
	 * Why a variable card cannot be chosen, if it cannot.
	 *
	 * A variable product is unavailable only when none of its variations can be
	 * given — the parent reporting itself out of stock is a summary, not the
	 * whole story.
	 *
	 * @param array<int,array<string,mixed>> $options Variation options.
	 * @return string Empty when at least one variation is available.
	 */
	protected static function variable_reason( $options ) {
		if ( ! $options ) {
			return __( 'No options are available', 'bogo-select' );
		}

		foreach ( $options as $option ) {
			if ( ! $option['reason'] ) {
				return '';
			}
		}

		return __( 'No options are available in that quantity', 'bogo-select' );
	}

	/**
	 * The option a card should quote and preselect.
	 *
	 * The customer's current choice when there is one, otherwise the first that
	 * can actually be given.
	 *
	 * @param array<int,array<string,mixed>> $options  Variation options.
	 * @param int                            $selected Currently chosen variation ID.
	 * @return array<string,mixed>|null
	 */
	protected static function default_option( $options, $selected = 0 ) {
		foreach ( $options as $option ) {
			if ( $selected && $option['id'] === (int) $selected ) {
				return $option;
			}
		}

		foreach ( $options as $option ) {
			if ( ! $option['reason'] ) {
				return $option;
			}
		}

		return $options ? $options[0] : null;
	}

	/**
	 * Print a short notice on shop and product pages when the cart qualifies.
	 *
	 * @return void
	 */
	public function maybe_render_notice() {
		if ( 'yes' !== BOGO_Select_Settings::get( 'show_notice' ) ) {
			return;
		}

		if ( ! function_exists( 'is_cart' ) || is_cart() || is_checkout() ) {
			return;
		}

		if ( ! BOGO_Select_Engine::is_active() || ! BOGO_Select_Engine::qualifies() ) {
			return;
		}

		if ( BOGO_Select_Engine::selected_product_id() ) {
			return;
		}

		$message = sprintf(
			/* translators: 1: opening link tag, 2: closing link tag. */
			/* translators: 1: what the reward is called, 2: opening link tag, 3: closing link tag. */
			esc_html__( 'You have unlocked a %1$s. %2$sChoose it in your cart%3$s.', 'bogo-select' ),
			esc_html( BOGO_Select_Engine::reward_noun() ),
			'<a href="' . esc_url( wc_get_cart_url() ) . '">',
			'</a>'
		);

		wc_print_notice( $message, 'notice' );
	}
}
