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
 * It does assume `setup-store.php` has already run, which is what takes
 * WooCommerce out of its "coming soon" mode. Without that every page in the
 * store serves a launch screen, and every assertion here fails for a reason
 * that has nothing to do with the plugin.
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

// A variable reward as well, so the classic lane exercises the variation
// selector and not only a simple product (`CODEX-REVIEW.md` L-02). The chooser
// markup is shared with the blocks, but the transport is not: classic goes over
// admin-ajax, and nothing had driven a variation through it.
$variable = wp_insert_post(
	array(
		'post_type'   => 'product',
		'post_title'  => 'Classic Variable Thing',
		'post_status' => 'publish',
	)
);

wp_set_object_terms( $variable, 'variable', 'product_type' );

update_post_meta(
	$variable,
	'_product_attributes',
	array(
		'size' => array(
			'name'         => 'Size',
			'value'        => 'Small | Large',
			'position'     => 0,
			'is_visible'   => 1,
			'is_variation' => 1,
			'is_taxonomy'  => 0,
		),
	)
);

/**
 * Create one variation of the classic fixture's variable product.
 *
 * @param int    $parent Parent product ID.
 * @param string $size   Attribute value.
 * @param int    $price  Price.
 * @return int Variation ID.
 */
function bogo_classic_variation( $parent, $size, $price ) {
	$id = wp_insert_post(
		array(
			'post_type'   => 'product_variation',
			'post_parent' => $parent,
			'post_title'  => 'Classic Variable Thing - ' . $size,
			'post_status' => 'publish',
		)
	);

	update_post_meta( $id, 'attribute_size', $size );
	update_post_meta( $id, '_price', $price );
	update_post_meta( $id, '_regular_price', $price );
	update_post_meta( $id, '_virtual', 'yes' );
	update_post_meta( $id, '_manage_stock', 'no' );
	update_post_meta( $id, '_stock_status', 'instock' );

	return $id;
}

// Priced apart so the cart shows which one was chosen.
$small = bogo_classic_variation( $variable, 'Small', 30 );
$large = bogo_classic_variation( $variable, 'Large', 50 );

if ( class_exists( 'WC_Product_Variable' ) ) {
	WC_Product_Variable::sync( $variable );
}

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
		// The two variations are listed individually as well as through their
		// parent, which is the layout CODEX-REVIEW.md M-01 broke: both pinned
		// cards claimed the selection and neither could reach the other.
		'get_products'       => array( $reward, $variable, $small, $large ),
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
		'variable'      => (int) $variable,
		'small'         => (int) $small,
		'large'         => (int) $large,
		'cart_path'     => '/classic-cart/',
		'checkout_path' => '/classic-checkout/',
	)
) . "\n";
