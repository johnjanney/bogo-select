<?php
/**
 * Customer-facing chooser and notices.
 *
 * @package BOGO_Select
 */

defined( 'ABSPATH' ) || exit;

/**
 * Renders the gift chooser on the cart page and the notice elsewhere.
 */
class BOGO_Select_Frontend {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'woocommerce_before_cart_table', array( $this, 'render_chooser' ) );
		add_action( 'woocommerce_before_main_content', array( $this, 'maybe_render_notice' ), 20 );
	}

	/**
	 * Load assets on the cart page only.
	 */
	public function enqueue() {
		if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
			return;
		}

		wp_enqueue_style(
			'bogo-select',
			BOGO_SELECT_URL . 'assets/css/bogo-select.css',
			array(),
			BOGO_SELECT_VERSION
		);

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
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'bogo-select' ),
				'i18n'    => array(
					'working'  => __( 'Adding…', 'bogo-select' ),
					'error'    => __( 'Something went wrong. Please refresh and try again.', 'bogo-select' ),
					'confirm'  => __( 'Remove your free gift?', 'bogo-select' ),
					'loading'  => __( 'Loading gifts…', 'bogo-select' ),
					/* translators: 1: current page, 2: total pages. */
					'pageOf'   => __( 'Page %1$d of %2$d', 'bogo-select' ),
					'noPages'  => __( 'No results', 'bogo-select' ),
				),
			)
		);
	}

	/**
	 * Render the chooser above the cart table.
	 */
	public function render_chooser() {
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
