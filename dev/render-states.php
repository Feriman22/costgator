<?php
/**
 * Renders the admin screen for edge-case scan results and checks the shown state.
 * Run: wp eval-file /dev-scripts/render-states.php
 */
require_once WP_PLUGIN_DIR . '/costgator/includes/class-admin.php';
wp_set_current_user( 1 );
$base   = array(
	'finished'            => time(),
	'threshold'           => 10.0,
	'currency'            => 'USD',
	'prices_include_tax'  => false,
	'skipped_no_price'    => 0,
	'unsupported_coupons' => 0,
	'coupons'             => array(),
	'findings'            => array(),
	'items'               => array(),
);
$item   = array( 'id' => 1, 'item_id' => 1, 'parent_id' => 0, 'name' => 'X', 'sku' => '', 'cost' => null, 'net_current' => 10.0, 'net_regular' => 10.0 );
$states = array(
	'all costs missing'  => array(
		'scan'   => array( 'items' => array( $item ), 'findings' => array( array( 'type' => 'missing_cost' ) + $item ) ),
		'expect' => array( 'is-none', 'could not be checked', 'id="costgator-missing" open' ),
		'reject' => array( 'is-ok', 'No issues found' ),
	),
	'clean'              => array(
		'scan'   => array( 'items' => array( array( 'cost' => 5.0 ) + $item ) ),
		'expect' => array( 'is-ok', 'Every checked product has a cost' ),
		'reject' => array( 'is-none' ),
	),
	'fixed coupons only' => array(
		'scan'   => array( 'items' => array( array( 'cost' => 5.0 ) + $item ), 'unsupported_coupons' => 2 ),
		'expect' => array( 'costgator-check is-info" id="costgator-coupons">', 'Fixed amount coupons not analyzed: 2' ),
		'reject' => array( 'Every checked product has a cost', 'id="costgator-coupons" open' ),
	),
	'mixed coupons'      => array(
		'scan'   => array(
			'items'               => array( array( 'cost' => 5.0 ) + $item ),
			'unsupported_coupons' => 1,
			'coupons'             => array( 7 => array( 'code' => 'bf40', 'percent' => 40.0, 'below_cost' => 1, 'low_margin' => 0, 'examples' => array( array( 'name' => 'X' ) ) ) ),
		),
		'expect' => array( 'costgator-check is-warning" id="costgator-coupons" open', 'Fixed amount coupons not analyzed: 1' ),
		'reject' => array(),
	),
	'low margin only'    => array(
		'scan'   => array(
			'items'    => array( array( 'cost' => 5.0 ) + $item ),
			'findings' => array( array( 'type' => 'future_low_margin', 'cost' => 5.0, 'price' => 5.2, 'margin' => 4.0, 'from' => time() + DAY_IN_SECONDS, 'source' => 'cogs' ) + $item ),
			'coupons'  => array( 7 => array( 'code' => 'bf5', 'percent' => 5.0, 'below_cost' => 0, 'low_margin' => 1, 'examples' => array( array( 'name' => 'X' ) ) ) ),
		),
		'expect' => array( 'id="costgator-future">', 'id="costgator-coupons">' ),
		'reject' => array( 'id="costgator-future" open', 'id="costgator-coupons" open' ),
	),
	'scheduled loss'     => array(
		'scan'   => array(
			'items'    => array( array( 'cost' => 5.0 ) + $item ),
			'findings' => array( array( 'type' => 'future_below_cost', 'cost' => 5.0, 'price' => 4.0, 'margin' => -25.0, 'from' => time() + DAY_IN_SECONDS, 'source' => 'cogs' ) + $item ),
		),
		'expect' => array( 'id="costgator-future" open' ),
		'reject' => array(),
	),
	'scan currency'      => array(
		'scan'   => array(
			'currency' => 'EUR',
			'decimals' => 2,
			'items'    => array( array( 'cost' => 5.25 ) + $item ),
			'findings' => array( array( 'type' => 'below_cost', 'cost' => 5.25, 'price' => 4.0, 'margin' => -31.25, 'source' => 'cogs' ) + $item ),
			'coupons'  => array( 7 => array( 'code' => 'half', 'percent' => 12.5, 'below_cost' => 1, 'low_margin' => 0, 'examples' => array( array( 'name' => 'X' ) ) ) ),
		),
		// The test store uses 0 decimals, so 5.25 and 1.25 only show when the scan precision is used.
		'expect' => array( '&euro;', '>12.5%<', '5.25<', '1.25<' ),
		'reject' => array( 'Ft', '>13%<' ),
	),
	'long coupon percent' => array(
		'scan'   => array(
			'items'   => array( array( 'cost' => 5.0 ) + $item ),
			'coupons' => array( 7 => array( 'code' => 'tiny', 'percent' => 0.004, 'below_cost' => 0, 'low_margin' => 1, 'examples' => array( array( 'name' => 'X' ) ) ) ),
		),
		'expect' => array( '>0.004%<' ),
		'reject' => array( '>0%<' ),
	),
	'no items'           => array(
		'scan'   => array(),
		'expect' => array( 'No products could be checked', 'is-none' ),
		'reject' => array( 'No issues found' ),
	),
);
$failed = 0;
foreach ( $states as $label => $state ) {
	$scan      = array_merge( $base, $state['scan'] );
	$threshold = 10.0;
	ob_start();
	include WP_PLUGIN_DIR . '/costgator/views/admin-page.php';
	$html = ob_get_clean();
	foreach ( $state['expect'] as $needle ) {
		$ok      = false !== strpos( $html, $needle );
		$failed += $ok ? 0 : 1;
		printf( "  %s %s: shows '%s'\n", $ok ? 'ok  ' : 'FAIL', $label, $needle );
	}
	foreach ( $state['reject'] as $needle ) {
		$ok      = false === strpos( $html, $needle );
		$failed += $ok ? 0 : 1;
		printf( "  %s %s: hides '%s'\n", $ok ? 'ok  ' : 'FAIL', $label, $needle );
	}
}
$failed ? WP_CLI::error( "$failed render checks failed." ) : WP_CLI::success( 'Render states ok.' );
