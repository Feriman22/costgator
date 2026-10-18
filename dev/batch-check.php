<?php
// Adds 60 filler products, checks that a multi-batch scan sees every item, then removes them.
use CostGator\Scanner;
$ids = array();
for ( $i = 0; $i < 60; $i++ ) {
	$p = new WC_Product_Simple();
	$p->set_name( "Filler $i" );
	$p->set_regular_price( '12700' );
	$p->set_cogs_value( 1000 );
	$ids[] = $p->save();
}
$status = Scanner::start( 10.0 );
$steps  = 0;
while ( ! $status['done'] ) {
	$status = Scanner::step( $status['scan_id'] );
	++$steps;
}
$stale = Scanner::step( 'not-a-scan' );
$scan  = Scanner::last_result();
foreach ( $ids as $id ) {
	wp_delete_post( $id, true );
}
$ok = 79 === count( $scan['items'] ) && 77 === $scan['total_products'] && 4 === $steps && is_wp_error( $stale ) && false === get_option( Scanner::OPTION_RUNNING );
WP_CLI::log( sprintf( 'items=%d total=%d steps=%d stale_rejected=%s', count( $scan['items'] ), $scan['total_products'], $steps, is_wp_error( $stale ) ? 'yes' : 'no' ) );
$ok ? WP_CLI::success( 'Batching ok.' ) : WP_CLI::error( 'Batching check failed.' );
