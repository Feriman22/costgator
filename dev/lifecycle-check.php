<?php
// Scan lifecycle edge cases on whatever catalog is loaded. Usage: wp eval-file /dev-scripts/lifecycle-check.php
use CostGator\Scanner;

global $wpdb;
$fails = 0;
$ok    = function ( $label, $cond ) use ( &$fails ) {
	WP_CLI::log( ( $cond ? '  ok   ' : '  FAIL ' ) . $label );
	$fails += $cond ? 0 : 1;
};
$chunks = function () use ( $wpdb ) {
	return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'costgator\\_chunk\\_%'" );
};
$run = function ( $status ) {
	while ( ! is_wp_error( $status ) && ! $status['done'] ) {
		$status = Scanner::step( $status['scan_id'] );
		wp_cache_flush();
	}
	return $status;
};

// 1. Complete scan as reference.
$ref       = $run( Scanner::start( 10.0 ) );
$ref_scan  = Scanner::last_result();
$ref_count = count( $ref_scan['items'] );
$ref_chunk = $chunks();
$ok( "reference scan: $ref_count items in $ref_chunk chunks", $ref_count > 0 && $ref_chunk === $ref_scan['chunks'] );

// 2. Abandoned scan: two batches, then a new scan replaces it. Old result must stay readable meanwhile.
$a = Scanner::start( 10.0 );
Scanner::step( $a['scan_id'] );
Scanner::step( $a['scan_id'] );
$ok( 'old result still readable while a scan runs', count( Scanner::last_result()['items'] ) === $ref_count );
$b = Scanner::start( 10.0 );
$ok( 'abandoned scan rejected after replacement', is_wp_error( Scanner::step( $a['scan_id'] ) ) );
$run( $b );
$ok( 'after replacement only the new result chunks remain', $chunks() === Scanner::last_result()['chunks'] );
$ok( 'replacement result is complete', count( Scanner::last_result()['items'] ) === $ref_count );

// 3. Interrupted completion: result published but running state left behind. A new start must keep the result.
$published = get_option( Scanner::OPTION_RESULT );
update_option( Scanner::OPTION_RUNNING, $published, false );
$c = Scanner::start( 10.0 );
$ok( 'published result survives a leftover running state', count( Scanner::last_result()['items'] ) === $ref_count );
$run( $c );

// 4. Orphan chunk from a crash is removed on the next publish.
update_option( 'costgator_chunk_deadbeef0000_0', array( 'items' => array(), 'findings' => array() ), false );
$run( Scanner::start( 10.0 ) );
$ok( 'crash orphan chunk cleaned up', false === get_option( 'costgator_chunk_deadbeef0000_0' ) );

// 5. Catalog changes during a scan: delete a product that is still ahead, add a new one.
$d    = Scanner::start( 10.0 );
$ids  = get_option( Scanner::OPTION_IDS );
$gone = end( $ids );
wp_trash_post( $gone );
$new = new WC_Product_Simple();
$new->set_name( 'Added mid-scan' );
$new->set_regular_price( '1000' );
$new_id = $new->save();
$status = $run( $d );
$res    = Scanner::last_result();
$ok( 'scan with a deleted product completes', ! is_wp_error( $status ) && $status['done'] );
$ok( 'deleted product is not reported', ! in_array( $gone, wp_list_pluck( $res['items'], 'id' ), true ) && ! in_array( $gone, wp_list_pluck( $res['items'], 'parent_id' ), true ) );
$ok( 'product added mid-scan waits for the next scan', ! in_array( $new_id, wp_list_pluck( $res['items'], 'id' ), true ) );
wp_untrash_post( $gone );
wp_publish_post( $gone );
wp_delete_post( $new_id, true );

// 6. A huge variable product unpublished while paused mid-way must not shift the next product's variations.
$make_var = function ( $sku, $n ) {
	$p    = new WC_Product_Variable();
	$attr = new WC_Product_Attribute();
	$opts = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$opts[] = "v$i";
	}
	$attr->set_name( 'V' );
	$attr->set_options( $opts );
	$attr->set_variation( true );
	$p->set_name( $sku );
	$p->set_sku( $sku );
	$p->set_attributes( array( $attr ) );
	$id = $p->save();
	for ( $i = 0; $i < $n; $i++ ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $id );
		$v->set_attributes( array( 'v' => "v$i" ) );
		$v->set_regular_price( '1000' );
		$v->save();
	}
	WC_Product_Variable::sync( $id );
	return $id;
};
$huge  = $make_var( 'LC-HUGE', 250 );
$after = $make_var( 'LC-AFTER', 5 );
$e     = Scanner::start( 10.0 );
$guard = 0;
do {
	$e     = Scanner::step( $e['scan_id'] );
	$state = get_option( Scanner::OPTION_RUNNING );
	$ids   = get_option( Scanner::OPTION_IDS );
	wp_cache_flush();
} while ( ! $e['done'] && ! ( $state['child_offset'] > 0 && $ids[ $state['processed_products'] ] === $huge ) && $guard++ < 1000 );
$ok( 'paused inside the huge product', ! $e['done'] && $state['child_offset'] > 0 );
wp_trash_post( $huge );
$run( $e );
$after_items = array_filter( Scanner::last_result()['items'], function ( $i ) use ( $after ) { return $i['parent_id'] === $after; } );
$ok( 'next product keeps all variations after skipped huge product (' . count( $after_items ) . '/5)', 5 === count( $after_items ) );
foreach ( array( $huge, $after ) as $vid ) {
	foreach ( wc_get_product( $vid )->get_children() as $child ) {
		wp_delete_post( $child, true );
	}
	wp_delete_post( $vid, true );
}

// 7. Uninstall removes everything.
define( 'WP_UNINSTALL_PLUGIN', 'costgator/costgator.php' );
include WP_PLUGIN_DIR . '/costgator/uninstall.php';
$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'costgator\\_%'" );
$ok( "uninstall leaves no options ($left left)", 0 === $left );

$fails ? WP_CLI::error( "$fails lifecycle check(s) failed." ) : WP_CLI::success( 'Lifecycle checks passed.' );
