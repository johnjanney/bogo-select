<?php
/**
 * Two users and a non-UTC clock, for exercising the real settings screen.
 *
 * The settings screen is the plugin's largest blind spot. `AdminSettingsTest`
 * calls the same sanitize callback WordPress calls and asserts what it returns,
 * which is where the schedule rules live — but it cannot exercise the thing
 * `options.php` does before ever reaching that callback, which is check a
 * capability. That check is what `CODEX-REVIEW.md` M-02 was about: the menu and
 * the page ask for `manage_woocommerce`, `options.php` asks for
 * `manage_options` unless told otherwise, and a Shop Manager could fill the
 * form in and be refused on submit.
 *
 * A non-UTC timezone because `DECISION.md` D-019 says the schedule is whole days
 * in the site's own zone, and every test of that has run against a stub clock.
 * The dates are computed here with `current_time()` so the browser and the
 * store agree on what "today" is even when UTC disagrees with both.
 *
 * Prints the credentials, the timezone, and three site-local dates as JSON on
 * the last line.
 *
 * @package BOGO_Select
 */

// Far enough east that its date differs from UTC's for ten hours of every day,
// which is what makes "whole days in the site's timezone" a claim with teeth
// rather than a restatement of UTC.
$bogo_admin_timezone = 'Pacific/Auckland';

update_option( 'timezone_string', $bogo_admin_timezone );
update_option( 'gmt_offset', '' );

/**
 * Create a user with a known password, replacing any earlier one.
 *
 * @param string $login Username.
 * @param string $role  Role slug.
 * @param string $pass  Password.
 * @return int User ID.
 */
function bogo_admin_user( $login, $role, $pass ) {
	// wp_delete_user() lives in wp-admin, which eval-file does not load for us.
	if ( ! function_exists( 'wp_delete_user' ) ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
	}

	$existing = get_user_by( 'login', $login );

	if ( $existing ) {
		wp_delete_user( $existing->ID );
	}

	$id = wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => $pass,
			'user_email' => $login . '@example.test',
			'role'       => $role,
		)
	);

	return is_wp_error( $id ) ? 0 : (int) $id;
}

$bogo_admin_pass = 'bogo-integration-pass';

// Shop Manager is the role WooCommerce gives manage_woocommerce, and the one
// the settings screen's own capability checks admit.
$bogo_admin_manager = bogo_admin_user( 'bogo_shop_manager', 'shop_manager', $bogo_admin_pass );

// Editor has neither manage_woocommerce nor manage_options, so it is the
// negative case: able to reach wp-admin, and not this page.
$bogo_admin_editor = bogo_admin_user( 'bogo_editor', 'editor', $bogo_admin_pass );

// Site-local dates. current_time() reads the option set above, so these are the
// store's own calendar rather than the container's.
$bogo_admin_today     = current_time( 'Y-m-d' );
$bogo_admin_yesterday = gmdate( 'Y-m-d', strtotime( $bogo_admin_today . ' -1 day' ) );
$bogo_admin_next_week = gmdate( 'Y-m-d', strtotime( $bogo_admin_today . ' +7 days' ) );

// A known starting schedule, so a refused submission has something to fall back
// to and the test can tell "kept" from "cleared".
$bogo_admin_settings = get_option( 'bogo_select_settings', array() );

$bogo_admin_settings = is_array( $bogo_admin_settings ) ? $bogo_admin_settings : array();

$bogo_admin_settings['start_date'] = $bogo_admin_today;
$bogo_admin_settings['end_date']   = $bogo_admin_next_week;

// Enabled, because the screen only reports a window as past or future for an
// offer that would otherwise be running — which is the behaviour under test.
$bogo_admin_settings['enabled'] = 'yes';

update_option( 'bogo_select_settings', $bogo_admin_settings );

echo wp_json_encode(
	array(
		'manager'   => $bogo_admin_manager,
		'editor'    => $bogo_admin_editor,
		'pass'      => $bogo_admin_pass,
		'timezone'  => $bogo_admin_timezone,
		'utc_date'  => gmdate( 'Y-m-d' ),
		'today'     => $bogo_admin_today,
		'yesterday' => $bogo_admin_yesterday,
		'next_week' => $bogo_admin_next_week,
	)
) . "\n";
