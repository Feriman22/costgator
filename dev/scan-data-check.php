<?php
/**
 * Checks that damaged scan data fails safely and that disabled coupons are not reported.
 * Run: wp eval-file /dev-scripts/scan-data-check.php (on the seeded test store)
 */

use CostGator\Auditor;
use CostGator\Scanner;

$failed = 0;
$check  = static function ( string $label, bool $ok ) use ( &$failed ) {
	$failed += $ok ? 0 : 1;
	WP_CLI::log( sprintf( '  %s %s', $ok ? 'ok  ' : 'FAIL', $label ) );
};
$finish = static function ( array $status ) {
	while ( ! is_wp_error( $status ) && ! $status['done'] ) {
		$status = Scanner::step( $status['scan_id'] );
	}
	return $status;
};

// A running scan whose ID list is gone must stop instead of finishing as an empty, clean looking result.
$status = Scanner::start( 10.0 );
$check( 'scan starts', ! is_wp_error( $status ) && $status['total'] > 0 );
delete_option( Scanner::OPTION_IDS );
$step = Scanner::step( $status['scan_id'] );
$check( 'missing ID list stops the scan', is_wp_error( $step ) && 'costgator_scan_data' === $step->get_error_code() );
$check( 'stopped scan is cleared', false === get_option( Scanner::OPTION_RUNNING ) );

// A shortened ID list is caught the same way.
$status = Scanner::start( 10.0 );
update_option( Scanner::OPTION_IDS, array_slice( get_option( Scanner::OPTION_IDS ), 1 ), false );
$step = Scanner::step( $status['scan_id'] );
$check( 'shortened ID list stops the scan', is_wp_error( $step ) && 'costgator_scan_data' === $step->get_error_code() );

// A full scan, then a damaged chunk: the result reads as missing instead of breaking the screen.
$status = $finish( Scanner::start( 10.0 ) );
$check( 'full scan completes', ! is_wp_error( $status ) && $status['done'] );
$result = Scanner::last_result();
$check( 'result loads', is_array( $result ) && $result['items'] );
$check( 'result stores the price decimals', isset( $result['decimals'] ) && wc_get_price_decimals() === $result['decimals'] );
$stored = get_option( Scanner::OPTION_RESULT );
$names  = $GLOBALS['wpdb']->get_col( $GLOBALS['wpdb']->prepare( "SELECT option_name FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE %s ORDER BY option_name", $GLOBALS['wpdb']->esc_like( Scanner::CHUNK_PREFIX ) . '%' ) );
$chunk  = get_option( $names[0] );
update_option( $names[0], array( 'items' => null, 'findings' => array() ), false );
$check( 'damaged chunk reads as no result', null === Scanner::last_result() );
update_option( $names[0], $chunk, false );
update_option( Scanner::OPTION_RESULT, array( 'scan_id' => $stored['scan_id'], 'chunks' => '1' ), false );
$check( 'damaged result header reads as no result', null === Scanner::last_result() );
update_option( Scanner::OPTION_RESULT, array( 'threshold' => array() ) + $stored, false );
$check( 'non-numeric header value reads as no result', null === Scanner::last_result() );
update_option( Scanner::OPTION_RESULT, $stored, false );
// Every damaged row or header value must read as no result, never as a broken screen or a clean report.
$loss = null;
foreach ( $chunk['findings'] as $index => $finding ) {
	if ( 'below_cost' === $finding['type'] ) {
		$loss = $index;
		break;
	}
}
$check( 'first chunk has a below cost finding', null !== $loss );
$chunk_damage  = array(
	'finding that is not an array'   => static function ( $c ) {
		$c['findings'][] = 'broken';
		return $c;
	},
	'item price that is not a number' => static function ( $c ) {
		$c['items'][0]['net_current'] = array();
		return $c;
	},
	'item without a cost field'      => static function ( $c ) {
		unset( $c['items'][0]['cost'] );
		return $c;
	},
	'finding of an unknown type'     => static function ( $c ) use ( $loss ) {
		$c['findings'][ $loss ]['type'] = 'below-cost';
		return $c;
	},
	'finding type that is true'      => static function ( $c ) use ( $loss ) {
		$c['findings'][ $loss ]['type'] = true;
		return $c;
	},
	'finding without a margin field' => static function ( $c ) use ( $loss ) {
		unset( $c['findings'][ $loss ]['margin'] );
		return $c;
	},
	'finding source that is an object' => static function ( $c ) use ( $loss ) {
		$c['findings'][ $loss ]['source'] = new stdClass();
		return $c;
	},
	'finding sku that is an array'   => static function ( $c ) use ( $loss ) {
		$c['findings'][ $loss ]['sku'] = array();
		return $c;
	},
);
foreach ( $chunk_damage as $label => $damage ) {
	update_option( $names[0], $damage( $chunk ), false );
	$check( "$label reads as no result", null === Scanner::last_result() );
}
update_option( $names[0], $chunk, false );
$check( 'restored chunk loads again', is_array( Scanner::last_result() ) );
// A scheduled sale with an infinite start date would crash the date output.
$future_chunk = null;
foreach ( $names as $name ) {
	$candidate = get_option( $name );
	foreach ( $candidate['findings'] as $index => $finding ) {
		if ( 0 === strpos( $finding['type'], 'future_' ) ) {
			$future_chunk = array( $name, $candidate, $index );
			break 2;
		}
	}
}
$check( 'result has a scheduled sale finding', null !== $future_chunk );
if ( $future_chunk ) {
	list( $name, $original, $index ) = $future_chunk;
	$damaged                          = $original;
	$damaged['findings'][ $index ]['from'] = INF;
	update_option( $name, $damaged, false );
	$check( 'sale start that is infinite reads as no result', null === Scanner::last_result() );
	update_option( $name, $original, false );
}
$damaged = $chunk;
$damaged['findings'][ $loss ]['from'] = INF;
update_option( $names[0], $damaged, false );
$check( 'infinite date on a current price finding reads as no result', null === Scanner::last_result() );
update_option( $names[0], $chunk, false );
$check( 'restored chunk loads after the date checks', is_array( Scanner::last_result() ) );
// Valid rows under string keys used to break argument unpacking. They now load like any other rows.
$keyed          = $chunk;
$keyed['items'] = array( 'array' => $chunk['items'][0] );
update_option( $names[0], $keyed, false );
$keyed_result = Scanner::last_result();
$check( 'item rows with string keys load without an error', is_array( $keyed_result ) && $keyed_result['items'] );
update_option( $names[0], $chunk, false );
$coupon_id     = key( $stored['coupons'] );
$header_damage = array(
	'tax setting that is not a boolean' => array( 'prices_include_tax' => 'yes' ),
	'empty currency'                    => array( 'currency' => '' ),
	'negative decimals'                 => array( 'decimals' => -1 ),
	'scan time that is infinite'        => array( 'finished' => INF ),
	'coupon example name that is an object' => array( 'coupons' => array( $coupon_id => array( 'examples' => array( array( 'name' => new stdClass() ) ) ) + $stored['coupons'][ $coupon_id ] ) ),
);
$check( 'stored result has coupons', null !== $coupon_id );
foreach ( $header_damage as $label => $damage ) {
	update_option( Scanner::OPTION_RESULT, $damage + $stored, false );
	$check( "$label reads as no result", null === Scanner::last_result() );
}
update_option( Scanner::OPTION_RESULT, $stored, false );
$check( 'restored result loads again', is_array( Scanner::last_result() ) );

// A result whose scan ID is not a string must not block the next scan.
update_option( Scanner::OPTION_RESULT, array( 'scan_id' => array() ) + $stored, false );
$status = $finish( Scanner::start( 10.0 ) );
$check( 'a new scan replaces a result with a broken scan ID', ! is_wp_error( $status ) && $status['done'] && is_array( Scanner::last_result() ) );

// Preflight classifies with the precision of the scan, not the current store setting.
$item = array( 'id' => 1, 'name' => 'X', 'cost' => 10.004, 'net_current' => 10.0, 'net_regular' => 10.0 );
$two   = Scanner::preflight( array( 'items' => array( $item ), 'decimals' => 2 ), 0.0, 'current', 10.0 );
$three = Scanner::preflight( array( 'items' => array( $item ), 'decimals' => 3 ), 0.0, 'current', 10.0 );
$check( 'preflight uses the scan precision', 0 === count( $two['below_cost'] ) && 1 === count( $three['below_cost'] ) );
$stale = Scanner::preflight( array( 'items' => array( array( 'new_price' => array() ) + $item ), 'decimals' => 3 ), 0.0, 'current', 10.0 );
$check( 'preflight values are calculated, never taken from stored rows', 10.0 === $stale['below_cost'][0]['new_price'] );

// With coupons turned off in WooCommerce no coupon is analyzed or counted.
$coupons_setting = get_option( 'woocommerce_enable_coupons' );
$before          = Auditor::find_coupons();
update_option( 'woocommerce_enable_coupons', 'no' );
$off = Auditor::find_coupons();
update_option( 'woocommerce_enable_coupons', $coupons_setting );
$check( 'test store has coupons', (bool) $before['ids'] );
$check( 'disabled coupons are not analyzed', array() === $off['ids'] && 0 === $off['unsupported'] );

if ( $failed ) {
	WP_CLI::error( "$failed scan data check(s) failed." );
}
WP_CLI::success( 'Scan data checks ok.' );
