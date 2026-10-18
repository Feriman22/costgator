<?php
/**
 * Runs a full scan on the seeded store and asserts the expected findings.
 * Run with: wp eval-file /dev-scripts/check.php
 */

use CostGator\Scanner;

$status = Scanner::start( 10.0 );
$guard  = 0;
while ( ! $status['done'] && $guard++ < 100 ) {
	$status = Scanner::step( $status['scan_id'] );
	if ( is_wp_error( $status ) ) {
		WP_CLI::error( $status->get_error_message() );
	}
}
$scan = Scanner::last_result();

$failures = 0;
function cg_assert( $label, $expected, $actual ) {
	global $failures;
	if ( $expected === $actual ) {
		WP_CLI::log( "  ok   $label" );
		return;
	}
	++$failures;
	WP_CLI::log( "  FAIL $label\n       expected: " . wp_json_encode( $expected ) . "\n       actual:   " . wp_json_encode( $actual ) );
}

$by_type = array();
foreach ( $scan['findings'] as $f ) {
	$by_type[ $f['type'] ][] = $f['sku'];
}
foreach ( $by_type as &$skus ) {
	sort( $skus );
}
unset( $skus );

$skus = wp_list_pluck( $scan['items'], 'sku' );
sort( $skus );

WP_CLI::log( 'Scanned items' );
cg_assert(
	'sellable items (drafts and disabled variations excluded)',
	array( 'CG-BADSALE', 'CG-BELOW', 'CG-BREAKEVEN', 'CG-CLEAR', 'CG-FREE', 'CG-FUTURE', 'CG-LOW', 'CG-NOCOST', 'CG-NOTAX', 'CG-OK', 'CG-REDUCED', 'CG-SALE', 'CG-SKYV', 'CG-TAXTRAP', 'CG-VAR-A', 'CG-VAR-B', 'CG-VAR-C', 'CG-VM-RED', 'CG-ZEROCOST' ),
	$skus
);

WP_CLI::log( 'Product findings' );
cg_assert( 'below_cost', array( 'CG-BELOW', 'CG-FREE', 'CG-SALE', 'CG-SKYV', 'CG-TAXTRAP', 'CG-VAR-B', 'CG-VAR-C', 'CG-VM-RED' ), $by_type['below_cost'] ?? array() );
cg_assert( 'low_margin', array( 'CG-BREAKEVEN', 'CG-LOW', 'CG-NOTAX', 'CG-REDUCED' ), $by_type['low_margin'] ?? array() );
cg_assert( 'missing_cost', array( 'CG-NOCOST', 'CG-ZEROCOST' ), $by_type['missing_cost'] ?? array() );
cg_assert( 'future_below_cost', array( 'CG-FUTURE' ), $by_type['future_below_cost'] ?? array() );
cg_assert( 'future_low_margin', array(), $by_type['future_low_margin'] ?? array() );

$find = function ( $sku, $type ) use ( $scan ) {
	foreach ( $scan['findings'] as $f ) {
		if ( $f['sku'] === $sku && $f['type'] === $type ) {
			return $f;
		}
	}
	return null;
};
cg_assert( 'TAXTRAP net price is 10000', 10000.0, round( $find( 'CG-TAXTRAP', 'below_cost' )['price'], 2 ) );
cg_assert( 'REDUCED uses 5% class, net 10000', 10000.0, round( $find( 'CG-REDUCED', 'low_margin' )['price'], 2 ) );
cg_assert( 'NOTAX is not de-taxed', 10000.0, round( $find( 'CG-NOTAX', 'low_margin' )['price'], 2 ) );
cg_assert( 'VAR-C additive cost 7000', 7000.0, $find( 'CG-VAR-C', 'below_cost' )['cost'] );
cg_assert( 'VAR-B own cost 11000', 11000.0, $find( 'CG-VAR-B', 'below_cost' )['cost'] );
cg_assert( 'child WPFactory cost beats parent SkyVerge', 'Cost of Goods (WPFactory)', $find( 'CG-VM-RED', 'below_cost' )['source'] );
cg_assert( 'SKYV cost source', 'Cost of Goods (SkyVerge)', $find( 'CG-SKYV', 'below_cost' )['source'] );
cg_assert( 'SALE flagged as on sale', true, $find( 'CG-SALE', 'below_cost' )['on_sale'] );
cg_assert( 'BREAKEVEN margin is 0', 0.0, round( $find( 'CG-BREAKEVEN', 'low_margin' )['margin'], 2 ) );
cg_assert( 'FUTURE sale net 7000', 7000.0, round( $find( 'CG-FUTURE', 'future_below_cost' )['price'], 2 ) );

WP_CLI::log( 'Coupons' );
$coupons = array();
foreach ( $scan['coupons'] as $c ) {
	$coupons[ $c['code'] ] = $c;
}
ksort( $coupons );
cg_assert( 'risky coupons (expired and fixed excluded)', array( 'clear50', 'save15nosale', 'save30' ), array_keys( $coupons ) );
cg_assert( 'save30 below cost count', 12, $coupons['save30']['below_cost'] ?? null );
cg_assert( 'save30 low margin count', 0, $coupons['save30']['low_margin'] ?? null );
cg_assert( 'clear50 only hits Clearance', array( 'CG-CLEAR' ), wp_list_pluck( $coupons['clear50']['examples'] ?? array(), 'name' ) );
cg_assert( 'save15nosale below cost count', 11, $coupons['save15nosale']['below_cost'] ?? null );
cg_assert( 'save15nosale skips sale items', false, in_array( 'CG-SALE', wp_list_pluck( $coupons['save15nosale']['examples'] ?? array(), 'name' ), true ) );
cg_assert( 'unsupported coupons', 1, $scan['unsupported_coupons'] );

WP_CLI::log( 'Promotion Preflight' );
$pf = Scanner::preflight( $scan, 20, 'current', 10.0 );
cg_assert( 'checked items with cost', 17, $pf['checked'] );
cg_assert( 'no cost skipped', 2, $pf['no_cost'] );
// 20% off current: OK 8000/5000 ok, CLEAR 8000/6000 25% ok, VAR-A ok, FUTURE 16000/8000 ok. Rest below cost.
cg_assert( '20% current: below cost', 12, count( $pf['below_cost'] ) );
$pf = Scanner::preflight( $scan, 50, 'regular', 10.0 );
// 50% off regular: SALE regular net 20000 -> 10000 < 12000, FUTURE 10000/8000 = 20% ok, OK 5000/5000 break-even low, CLEAR 5000 < 6000.
cg_assert( '50% regular: OK is break-even low margin', true, in_array( 'CG-OK', wp_list_pluck( $pf['low_margin'], 'sku' ), true ) );
cg_assert( '50% regular: FUTURE stays ok', false, in_array( 'CG-FUTURE', array_merge( wp_list_pluck( $pf['low_margin'], 'sku' ), wp_list_pluck( $pf['below_cost'], 'sku' ) ), true ) );

if ( $failures ) {
	WP_CLI::error( "$failures assertion(s) failed." );
}
WP_CLI::success( 'All integration checks passed.' );
