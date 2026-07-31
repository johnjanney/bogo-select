<?php
/**
 * Uninstall routine.
 *
 * Removes the plugin's options. Orders, products, and stock levels are left
 * untouched.
 *
 * @package BOGO_Select
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'bogo_select_settings' );
delete_option( 'bogo_select_version' );

// Multisite: clean each site in the network.
if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( $site_id );
		delete_option( 'bogo_select_settings' );
		delete_option( 'bogo_select_version' );
		restore_current_blog();
	}
}
