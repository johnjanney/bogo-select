<?php
/**
 * Switch the fixture store's offer from a free gift to a percentage discount.
 *
 * Run through `wp eval-file` between the two browser tests, so the same seeded
 * store exercises both pricing modes without being rebuilt. Prints the saved
 * discount as JSON on the last line.
 *
 * @package BOGO_Select
 */

$settings = get_option( 'bogo_select_settings', array() );
$settings = is_array( $settings ) ? $settings : array();

$settings['get_discount_type']  = 'percent';
$settings['get_discount_value'] = 50;

// The heading is what the rendered-page check greps for, and it must not start
// claiming the reward is free now that it is not.
$settings['offer_title'] = 'CHOOSER-HEADING-XYZ';

update_option( 'bogo_select_settings', $settings );

echo wp_json_encode(
	array(
		'type'  => $settings['get_discount_type'],
		'value' => $settings['get_discount_value'],
	)
) . "\n";
