<?php
/**
 * Switch the fixture store to classic shortcode cart and checkout pages.
 *
 * Run through `wp eval-file`. WooCommerce provisions block-based cart and
 * checkout pages on a fresh install, so every browser assertion so far has been
 * against the blocks. The classic path is genuinely different code — the chooser
 * arrives through `woocommerce_before_cart_table` and
 * `woocommerce_before_checkout_form` rather than the `render_block` filter, and
 * selection goes over admin-ajax rather than the Store API.
 *
 * This repoints the store at shortcode pages and gives the scenario its own
 * products, so it neither depends on nor disturbs the stock the earlier lanes
 * accounted for. Run it last: the page IDs it sets are global.
 *
 * Prints the page paths and product IDs as JSON on the last line.
 *
 * @package BOGO_Select
 */

/**
 * Create a published page carrying one shortcode.
 *
 * @param string $title     Page title.
 * @param string $slug      Page slug.
 * @param string $shortcode Shortcode to place in it.
 * @return int Page ID.
 */
function bogo_classic_page( $title, $slug, $shortcode ) {
	$existing = get_page_by_path( $slug );

	if ( $existing ) {
		wp_delete_post( $existing->ID, true );
	}

	return wp_insert_post(
		array(
			'post_type'    => 'page',
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_status'  => 'publish',
			'post_content' => $shortcode,
		)
	);
}

$cart_page     = bogo_classic_page( 'Classic Cart', 'classic-cart', '[woocommerce_cart]' );
$checkout_page = bogo_classic_page( 'Classic Checkout', 'classic-checkout', '[woocommerce_checkout]' );

update_option( 'woocommerce_cart_page_id', $cart_page );
update_option( 'woocommerce_checkout_page_id', $checkout_page );

/**
 * Create a virtual simple product.
 *
 * @param string $title Product name.
 * @param int    $price Price.
 * @return int Product ID.
 */
function bogo_classic_product( $title, $price ) {
	$id = wp_insert_post(
		array(
			'post_type'   => 'product',
			'post_title'  => $title,
			'post_status' => 'publish',
		)
	);

	wp_set_object_terms( $id, 'simple', 'product_type' );
	update_post_meta( $id, '_price', $price );
	update_post_meta( $id, '_regular_price', $price );
	update_post_meta( $id, '_virtual', 'yes' );
	update_post_meta( $id, '_manage_stock', 'no' );
	update_post_meta( $id, '_stock_status', 'instock' );
	update_post_meta( $id, '_sku', 'classic-sku-' . $id );

	return $id;
}

$paid   = bogo_classic_product( 'Classic Paid Thing', 40 );
$reward = bogo_classic_product( 'Classic Reward Thing', 24 );

// Discounted rather than free, so the classic cart line has a real figure to
// render and the badge has something to say beyond "Free".
update_option(
	'bogo_select_settings',
	array(
		'enabled'            => 'yes',
		'offer_title'        => 'CHOOSER-HEADING-XYZ',
		'buy_qty'            => 1,
		'get_qty'            => 1,
		'buy_scope'          => 'all',
		'get_scope'          => 'select',
		'get_products'       => array( $reward ),
		'get_discount_type'  => 'percent',
		'get_discount_value' => 50,
		'repeat'             => 'no',
		'show_notice'        => 'yes',
	)
);

echo wp_json_encode(
	array(
		'paid'          => (int) $paid,
		'reward'        => (int) $reward,
		'cart_path'     => '/classic-cart/',
		'checkout_path' => '/classic-checkout/',
	)
) . "\n";
