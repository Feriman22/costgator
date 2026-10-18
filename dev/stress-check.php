<?php
/**
 * Scans the stress catalog, compares every item with the oracle and reports performance.
 * Usage: wp eval-file /dev-scripts/stress-check.php
 */

use CostGator\Scanner;

$expected = get_option( 'cg_stress_expected' );
if ( ! $expected ) {
	WP_CLI::error( 'Run stress-seed.php first.' );
}

$t0     = microtime( true );
$status = Scanner::start( (float) $expected['threshold'] );
$start  = microtime( true ) - $t0;
$times  = array();
$mems   = array();
while ( ! $status['done'] ) {
	$t      = microtime( true );
	$status = Scanner::step( $status['scan_id'] );
	if ( is_wp_error( $status ) ) {
		WP_CLI::error( $status->get_error_message() );
	}
	$times[] = microtime( true ) - $t;
	$mems[]  = memory_get_peak_usage( true );
	wp_cache_flush(); // Each AJAX request starts with a cold object cache.
}
$total = microtime( true ) - $t0;
$scan  = Scanner::last_result();

$fails    = array();
$fail     = function ( $msg ) use ( &$fails ) {
	$fails[] = $msg;
};
$decimals = wc_get_price_decimals();

// Index plugin output.
$items = array();
foreach ( $scan['items'] as $item ) {
	if ( isset( $items[ $item['sku'] ] ) ) {
		$fail( "duplicate item {$item['sku']}" );
	}
	$items[ $item['sku'] ] = $item;
}
$findings = array();
foreach ( $scan['findings'] as $f ) {
	$findings[ $f['sku'] ][ $f['type'] ] = $f;
}

if ( $scan['total_products'] !== $expected['parents'] ) {
	$fail( "total_products {$scan['total_products']} != expected {$expected['parents']}" );
}

foreach ( $expected['skipped'] as $sku ) {
	if ( isset( $items[ $sku ] ) ) {
		$fail( "$sku should be skipped but was scanned" );
	}
}

$ambiguous = 0;
$stats     = array();
foreach ( $expected['items'] as $sku => $e ) {
	if ( ! isset( $items[ $sku ] ) ) {
		$fail( "$sku missing from scan" );
		continue;
	}
	$it  = $items[ $sku ];
	$tol = max( 0.0001, pow( 10, -$decimals ) / 100 );
	if ( abs( $it['net_current'] - $e['net_current'] ) > max( 0.01, abs( $e['net_current'] ) * 1e-6 ) ) {
		$fail( sprintf( '%s net_current %.4f != oracle %.4f (sale %s)', $sku, $it['net_current'], $e['net_current'], $e['sale_kind'] ) );
	}
	if ( null === $e['cost'] ) {
		if ( null !== $it['cost'] ) {
			$fail( "$sku cost should be unknown, got {$it['cost']} from {$it['cost_source']}" );
		}
	} elseif ( null === $it['cost'] || abs( $it['cost'] - $e['cost'] ) > $tol ) {
		$fail( sprintf( '%s cost %s != oracle %s', $sku, var_export( $it['cost'], true ), var_export( $e['cost'], true ) ) );
	} elseif ( $it['cost_source'] !== $e['source'] ) {
		$fail( "$sku source {$it['cost_source']} != oracle {$e['source']}" );
	}
	if ( (bool) $it['on_sale'] !== (bool) $e['on_sale'] ) {
		$fail( "$sku on_sale mismatch (sale {$e['sale_kind']})" );
	}

	$got = $findings[ $sku ] ?? array();
	if ( null === $e['status'] ) {
		++$ambiguous;
	} else {
		$want = 'ok' === $e['status'] ? array() : array( $e['status'] );
		$have = array_values( array_intersect( array_keys( $got ), array( 'below_cost', 'low_margin', 'missing_cost' ) ) );
		if ( $want !== $have ) {
			$fail( sprintf( '%s status %s != oracle %s (net %.2f cost %s)', $sku, wp_json_encode( $have ), wp_json_encode( $want ), $e['net_current'], var_export( $e['cost'], true ) ) );
		}
		$stats[ $e['status'] ] = ( $stats[ $e['status'] ] ?? 0 ) + 1;
	}
	if ( 'none' === $e['future'] || 'missing_cost' === $e['status'] ) {
		if ( isset( $got['future_below_cost'] ) || isset( $got['future_low_margin'] ) ) {
			$fail( "$sku unexpected future finding" );
		}
	} elseif ( null !== $e['future'] ) {
		$want = 'ok' === $e['future'] ? array() : array( 'future_' . $e['future'] );
		$have = array_values( array_intersect( array_keys( $got ), array( 'future_below_cost', 'future_low_margin' ) ) );
		if ( $want !== $have ) {
			$fail( "$sku future " . wp_json_encode( $have ) . ' != oracle ' . wp_json_encode( $want ) );
		}
		$stats[ 'future_' . $e['future'] ] = ( $stats[ 'future_' . $e['future'] ] ?? 0 ) + 1;
	}
}
foreach ( $items as $sku => $it ) {
	if ( ! isset( $expected['items'][ $sku ] ) ) {
		$fail( "$sku scanned but not expected" );
	}
}

// Coupons.
$by_code     = array();
$unsupported = 0;
foreach ( $scan['coupons'] as $c ) {
	$by_code[ $c['code'] ] = $c;
}
foreach ( $expected['coupons'] as $code => $e ) {
	if ( in_array( $e['kind'], array( 'fixed_cart', 'fixed_product' ), true ) ) {
		++$unsupported;
	}
	$got = $by_code[ $code ] ?? array( 'below_cost' => 0, 'low_margin' => 0 );
	if ( 'percent' !== $e['kind'] ) {
		if ( isset( $by_code[ $code ] ) ) {
			$fail( "coupon $code ({$e['kind']}) should not be analyzed" );
		}
		continue;
	}
	$sum_got  = $got['below_cost'] + $got['low_margin'];
	$sum_want = $e['below_cost'] + $e['low_margin'];
	if ( $got['below_cost'] < $e['below_cost'] || $got['below_cost'] > $e['below_cost'] + $e['ambiguous']
		|| $sum_got < $sum_want || $sum_got > $sum_want + $e['ambiguous'] ) {
		$fail( sprintf( 'coupon %s below/low %d/%d != oracle %d/%d (+%d ambiguous)', $code, $got['below_cost'], $got['low_margin'], $e['below_cost'], $e['low_margin'], $e['ambiguous'] ) );
	}
}
if ( $unsupported !== $scan['unsupported_coupons'] ) {
	$fail( "unsupported coupons {$scan['unsupported_coupons']} != $unsupported" );
}

// Preflight timing on the full result.
$t  = microtime( true );
$pf = Scanner::preflight( $scan, 25, 'regular', 10.0 );
$pt = microtime( true ) - $t;

sort( $times );
$size = strlen( serialize( $scan ) );
WP_CLI::log( sprintf( 'Config %s: %d parents, %d items, %d findings, %d coupons flagged', $expected['config'], $scan['total_products'], count( $scan['items'] ), count( $scan['findings'] ), count( $scan['coupons'] ) ) );
WP_CLI::log( '  oracle status counts: ' . wp_json_encode( $stats ) . ", ambiguous skipped: $ambiguous" );
WP_CLI::log( sprintf( '  scan: total %.1fs, start %.2fs, %d batches, batch median %.2fs, max %.2fs, peak memory %.0f MB', $total, $start, count( $times ), $times[ (int) ( count( $times ) / 2 ) ] ?? 0, end( $times ), max( $mems ) / 1048576 ) );
WP_CLI::log( sprintf( '  stored result: %.2f MB serialized, preflight %.3fs (%d below, %d low)', $size / 1048576, $pt, count( $pf['below_cost'] ), count( $pf['low_margin'] ) ) );

if ( $fails ) {
	WP_CLI::log( '  ' . count( $fails ) . ' FAILURES, first 40:' );
	foreach ( array_slice( $fails, 0, 40 ) as $f ) {
		WP_CLI::log( "    $f" );
	}
	WP_CLI::error( 'Stress check failed.' );
}
WP_CLI::success( 'Every item matches the oracle.' );
