<?php
/**
 * What the chooser costs on a large catalogue.
 *
 * `CODEX-REVIEW.md` M-03 asked for wall time, database queries, CPU time, and
 * peak memory measured against a realistic catalogue, and was explicit that the
 * product-load counts the unit suite holds are not latency and must not be
 * reported as though they were. Nothing here converts one into the other: the
 * loads are counted by `ChooserSearchCostTest`, and these are seconds, queries,
 * and bytes.
 *
 * Run through `wp eval-file ... [catalogue] [curated]`. Defaults to 2,000
 * products with a curated list of 500, which is a large store rather than an
 * enormous one — the numbers are per-request costs, so a reader can scale them.
 *
 * Three paths are measured, because they cost differently:
 *
 * - **All Products search.** Bounded by `search_limit()` at 200 candidates
 *   however large the catalogue is, so this measures the ceiling.
 * - **All Products browse.** One catalogue query per page, filtered afterwards.
 * - **Select Products, cold and warm.** The curated list's eligibility is
 *   O(N) to build and then cached in a transient. The cold build is the one
 *   worth knowing, since it is what a store pays after any product is saved.
 *
 * Prints a table and a JSON summary on the last line.
 *
 * @package BOGO_Select
 */

$bogo_bench_catalogue = isset( $args[0] ) ? max( 1, (int) $args[0] ) : 2000;
$bogo_bench_curated   = isset( $args[1] ) ? max( 1, (int) $args[1] ) : 500;

/**
 * One measured run of a callable.
 *
 * @param callable $fn What to measure.
 * @return array Wall seconds, queries, CPU seconds, peak bytes, and the result.
 */
function bogo_bench_once( $fn ) {
	global $wpdb;

	$queries_before = (int) $wpdb->num_queries;
	$rusage_before  = function_exists( 'getrusage' ) ? getrusage() : null;
	$peak_before    = memory_get_peak_usage( true );
	$started        = microtime( true );

	$result = $fn();

	$elapsed      = microtime( true ) - $started;
	$rusage_after = function_exists( 'getrusage' ) ? getrusage() : null;

	$cpu = null;

	if ( $rusage_before && $rusage_after ) {
		$user   = ( $rusage_after['ru_utime.tv_sec'] - $rusage_before['ru_utime.tv_sec'] )
			+ ( ( $rusage_after['ru_utime.tv_usec'] - $rusage_before['ru_utime.tv_usec'] ) / 1e6 );
		$system = ( $rusage_after['ru_stime.tv_sec'] - $rusage_before['ru_stime.tv_sec'] )
			+ ( ( $rusage_after['ru_stime.tv_usec'] - $rusage_before['ru_stime.tv_usec'] ) / 1e6 );
		$cpu    = $user + $system;
	}

	return array(
		'seconds' => round( $elapsed, 4 ),
		'queries' => (int) $wpdb->num_queries - $queries_before,
		'cpu'     => null === $cpu ? null : round( $cpu, 4 ),
		'peak_mb' => round( max( 0, memory_get_peak_usage( true ) - $peak_before ) / 1048576, 2 ),
		'result'  => $result,
	);
}

/**
 * Measure a callable cold, then again with everything it warmed still warm.
 *
 * @param string   $label What is being measured.
 * @param callable $fn    What to measure.
 * @param callable $reset Called before the cold run to drop caches.
 * @return array
 */
function bogo_bench( $label, $fn, $reset = null ) {
	if ( is_callable( $reset ) ) {
		$reset();
	}

	$cold = bogo_bench_once( $fn );
	$warm = bogo_bench_once( $fn );

	printf(
		"%-34s cold %7.3fs %5d queries %7.3fs cpu %6.2f MB   warm %7.3fs %5d queries\n",
		$label,
		$cold['seconds'],
		$cold['queries'],
		null === $cold['cpu'] ? 0 : $cold['cpu'],
		$cold['peak_mb'],
		$warm['seconds'],
		$warm['queries']
	);

	unset( $cold['result'], $warm['result'] );

	return array(
		'cold' => $cold,
		'warm' => $warm,
	);
}

// --- Seed ------------------------------------------------------------------

echo "Seeding {$bogo_bench_catalogue} products...\n";

wp_defer_term_counting( true );
wp_suspend_cache_invalidation( true );

$bogo_bench_ids   = array();
$bogo_bench_start = microtime( true );

for ( $bogo_bench_i = 0; $bogo_bench_i < $bogo_bench_catalogue; $bogo_bench_i++ ) {
	$bogo_bench_product = new WC_Product_Simple();

	// Every name carries "Gift" so a broad search matches the whole catalogue
	// and the 200-candidate ceiling is actually reached.
	$bogo_bench_product->set_name( sprintf( 'Bench Gift %05d', $bogo_bench_i ) );
	$bogo_bench_product->set_sku( sprintf( 'BENCH-%05d', $bogo_bench_i ) );
	$bogo_bench_product->set_regular_price( '10.00' );
	$bogo_bench_product->set_price( '10.00' );
	$bogo_bench_product->set_virtual( true );
	$bogo_bench_product->set_catalog_visibility( 'visible' );
	$bogo_bench_product->set_status( 'publish' );

	$bogo_bench_ids[] = $bogo_bench_product->save();

	if ( 0 === ( $bogo_bench_i + 1 ) % 250 ) {
		echo '  ' . ( $bogo_bench_i + 1 ) . " seeded\n";
	}
}

wp_suspend_cache_invalidation( false );
wp_defer_term_counting( false );
wp_cache_flush();

printf( "Seeded in %.1fs\n\n", microtime( true ) - $bogo_bench_start );

$bogo_bench_curated_ids = array_slice( $bogo_bench_ids, 0, $bogo_bench_curated );

// --- Measure ----------------------------------------------------------------

/**
 * Put the plugin into a known state for one scenario.
 *
 * @param string $scope       'all' or 'select'.
 * @param int[]  $get_products Curated list, when the scope is 'select'.
 * @return void
 */
function bogo_bench_configure( $scope, $get_products = array() ) {
	$settings = get_option( 'bogo_select_settings', array() );
	$settings = is_array( $settings ) ? $settings : array();

	$settings['enabled']      = 'yes';
	$settings['buy_scope']    = 'all';
	$settings['get_scope']    = $scope;
	$settings['get_products'] = $get_products;
	$settings['start_date']   = '';
	$settings['end_date']     = '';

	update_option( 'bogo_select_settings', $settings );

	BOGO_Select_Settings::flush();
	BOGO_Select_Engine::flush_choice_cache();
}

$bogo_bench_results = array();

printf( "%-34s %s\n", 'Path', 'measurements' );
echo str_repeat( '-', 118 ) . "\n";

bogo_bench_configure( 'all' );

$bogo_bench_results['all_search'] = bogo_bench(
	'All Products, search "Gift"',
	function () {
		return BOGO_Select_Engine::get_choice_page( array( 'search' => 'Gift' ) );
	},
	function () {
		BOGO_Select_Engine::flush_choice_cache();
		wp_cache_flush();
	}
);

$bogo_bench_results['all_search_sku'] = bogo_bench(
	'All Products, search one SKU',
	function () {
		return BOGO_Select_Engine::get_choice_page( array( 'search' => 'BENCH-01234' ) );
	},
	function () {
		BOGO_Select_Engine::flush_choice_cache();
		wp_cache_flush();
	}
);

$bogo_bench_results['all_browse'] = bogo_bench(
	'All Products, browse page 1',
	function () {
		return BOGO_Select_Engine::get_choice_page( array( 'page' => 1 ) );
	},
	function () {
		BOGO_Select_Engine::flush_choice_cache();
		wp_cache_flush();
	}
);

$bogo_bench_results['all_browse_deep'] = bogo_bench(
	'All Products, browse page 20',
	function () {
		return BOGO_Select_Engine::get_choice_page( array( 'page' => 20 ) );
	},
	function () {
		BOGO_Select_Engine::flush_choice_cache();
		wp_cache_flush();
	}
);

bogo_bench_configure( 'select', $bogo_bench_curated_ids );

// The transient is the point of this one: cold is what a store pays on the
// first request after any product is saved, warm is every request after.
$bogo_bench_results['curated_cold'] = bogo_bench(
	sprintf( 'Select Products, %d curated', count( $bogo_bench_curated_ids ) ),
	function () {
		return BOGO_Select_Engine::get_choice_page( array( 'page' => 1 ) );
	},
	function () {
		BOGO_Select_Engine::flush_choice_cache();
		wp_cache_flush();
	}
);

$bogo_bench_results['curated_search'] = bogo_bench(
	'Select Products, search "Gift"',
	function () {
		return BOGO_Select_Engine::get_choice_page( array( 'search' => 'Gift' ) );
	},
	function () {
		BOGO_Select_Engine::flush_choice_cache();
		wp_cache_flush();
	}
);

echo "\n";

echo wp_json_encode(
	array(
		'catalogue'      => $bogo_bench_catalogue,
		'curated'        => count( $bogo_bench_curated_ids ),
		'search_limit'   => BOGO_Select_Engine::search_limit(),
		'per_page'       => BOGO_Select_Engine::choices_per_page(),
		'persistent_obj' => wp_using_ext_object_cache() ? 'yes' : 'no',
		'php'            => PHP_VERSION,
		'wc'             => defined( 'WC_VERSION' ) ? WC_VERSION : 'unknown',
		'results'        => $bogo_bench_results,
	)
) . "\n";
