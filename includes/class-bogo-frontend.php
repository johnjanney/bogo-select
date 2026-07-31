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
					'confirm' => __( 'Remove your free gift?', 'bogo-select' ),
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
	 */
	public function render_chooser() {
		echo self::slot_html( 'classic' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped fragments.
	}

	/**
	 * Print the chooser slot above the classic checkout form.
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
	 * @param string $mode 'classic', 'checkout', or 'block'.
	 * @return string Empty string when the offer is switched off.
	 */
	public static function slot_html( $mode = 'classic' ) {
		if ( ! BOGO_Select_Engine::is_active() || self::$slot_rendered ) {
			return '';
		}

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
							/* translators: %d: number of free units. */
							esc_html__( 'You are getting %d free — pick a different gift below to change your mind.', 'bogo-select' ),
							(int) $reward_qty
						);
					} else {
						printf(
							/* translators: 1: number of free units, 2: number of options. */
							esc_html__( 'Your cart qualifies for %1$d free — choose 1 of %2$d options below.', 'bogo-select' ),
							(int) $reward_qty,
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

		$reward_qty = (int) $reward_qty;
		$selected   = (int) $selected;

		ob_start();

		foreach ( (array) $product_ids as $product_id ) {
			$product = wc_get_product( $product_id );

			if ( ! $product ) {
				continue;
			}

			$is_selected = ( (int) $product_id === $selected );

			// The gift being replaced is not competing with its own replacement.
			$exclude      = $is_selected ? BOGO_Select_Engine::find_reward_key() : '';
			$other_demand = BOGO_Select_Engine::stock_demand( null, $product, $exclude );
			$reason       = BOGO_Select_Engine::unavailable_reason( $product, $reward_qty, $other_demand );

			$classes = array( 'bogo-select__item' );

			if ( $is_selected ) {
				$classes[] = 'is-selected';
			}

			if ( $reason && ! $is_selected ) {
				$classes[] = 'is-unavailable';
			}
			?>
			<li class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
				<div class="bogo-select__thumb">
					<?php echo wp_kses_post( $product->get_image( 'woocommerce_thumbnail' ) ); ?>
				</div>
				<div class="bogo-select__info">
					<span class="bogo-select__name"><?php echo esc_html( $product->get_name() ); ?></span>
					<span class="bogo-select__price">
						<?php if ( wc_get_price_to_display( $product ) > 0 ) : ?>
							<del aria-hidden="true"><?php echo wp_kses_post( wc_price( wc_get_price_to_display( $product ) ) ); ?></del>
						<?php endif; ?>
						<strong><?php esc_html_e( 'Free', 'bogo-select' ); ?></strong>
					</span>
					<?php if ( $reason && ! $is_selected ) : ?>
						<span class="bogo-select__reason"><?php echo esc_html( $reason ); ?></span>
					<?php endif; ?>
				</div>
				<div class="bogo-select__actions">
					<?php if ( $is_selected ) : ?>
						<span class="bogo-select__selected"><?php esc_html_e( 'Selected', 'bogo-select' ); ?></span>
						<button type="button" class="bogo-select__remove" data-bogo-remove="1">
							<?php esc_html_e( 'Remove gift', 'bogo-select' ); ?>
						</button>
					<?php elseif ( $reason ) : ?>
						<button type="button" class="button" disabled="disabled" data-permanently-disabled="1">
							<?php esc_html_e( 'Unavailable', 'bogo-select' ); ?>
						</button>
					<?php else : ?>
						<button type="button" class="button bogo-select__choose" data-product-id="<?php echo esc_attr( $product_id ); ?>">
							<?php echo $selected ? esc_html__( 'Choose this instead', 'bogo-select' ) : esc_html__( 'Select', 'bogo-select' ); ?>
						</button>
					<?php endif; ?>
				</div>
			</li>
			<?php
		}

		return (string) ob_get_clean();
	}

	/**
	 * Print a short notice on shop and product pages when the cart qualifies.
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
			esc_html__( 'You have unlocked a free gift. %1$sChoose it in your cart%2$s.', 'bogo-select' ),
			'<a href="' . esc_url( wc_get_cart_url() ) . '">',
			'</a>'
		);

		wc_print_notice( $message, 'notice' );
	}
}
