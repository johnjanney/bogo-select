<?php
/**
 * Add a variable product to the fixture store and offer it as the reward.
 *
 * Run through `wp eval-file` after the discounted scenario, so the same store
 * exercises a variable reward without being rebuilt. Prints the parent and
 * variation IDs as JSON on the last line.
 *
 * The discount is set here rather than inherited, so this scenario does not
 * depend on which of the earlier ones ran.
 *
 * @package BOGO_Select
 */

$parent = wp_insert_post(
	array(
		'post_type'   => 'product',
		'post_title'  => 'Variable Tee',
		'post_status' => 'publish',
	)
);

wp_set_object_terms( $parent, 'variable', 'product_type' );

// A custom (non-taxonomy) attribute, which variations match through the
// attribute_size meta key below.
update_post_meta(
	$parent,
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
 * Create one variation of the fixture parent.
 *
 * @param int    $parent Parent product ID.
 * @param string $size   Attribute value.
 * @param int    $price  Price.
 * @return int Variation ID.
 */
function bogo_fixture_variation( $parent, $size, $price ) {
	$id = wp_insert_post(
		array(
			'post_type'   => 'product_variation',
			'post_parent' => $parent,
			'post_title'  => 'Variable Tee - ' . $size,
			'post_status' => 'publish',
		)
	);

	update_post_meta( $id, 'attribute_size', $size );
	update_post_meta( $id, '_price', $price );
	update_post_meta( $id, '_regular_price', $price );
	update_post_meta( $id, '_manage_stock', 'no' );
	update_post_meta( $id, '_stock_status', 'instock' );

	return $id;
}

// Deliberately different prices. The parent reports the low end of the range,
// so charging the wrong one is visible in the totals rather than silent.
$small = bogo_fixture_variation( $parent, 'Small', 12 );
$large = bogo_fixture_variation( $parent, 'Large', 18 );

if ( class_exists( 'WC_Product_Variable' ) ) {
	WC_Product_Variable::sync( $parent );
}

$settings = get_option( 'bogo_select_settings', array() );
$settings = is_array( $settings ) ? $settings : array();

$settings['get_scope']          = 'select';
$settings['get_products']       = array( $parent );
$settings['get_discount_type']  = 'percent';
$settings['get_discount_value'] = 50;
$settings['offer_title']        = 'CHOOSER-HEADING-XYZ';

update_option( 'bogo_select_settings', $settings );

echo wp_json_encode(
	array(
		'parent' => (int) $parent,
		'small'  => (int) $small,
		'large'  => (int) $large,
	)
) . "\n";
