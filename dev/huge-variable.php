<?php
// Adds (or with "delete" removes) one variable product with N variations. Usage: wp eval-file huge-variable.php <N>|delete
$existing = wc_get_product_id_by_sku( 'HUGE-VAR' );
if ( $existing ) {
	$p = wc_get_product( $existing );
	foreach ( $p->get_children() as $c ) {
		wp_delete_post( $c, true );
	}
	wp_delete_post( $existing, true );
}
if ( 'delete' === ( $args[0] ?? '' ) ) {
	WP_CLI::success( 'Removed.' );
	return;
}
$n    = (int) ( $args[0] ?? 2000 );
$t    = microtime( true );
$p    = new WC_Product_Variable();
$attr = new WC_Product_Attribute();
$opts = array();
for ( $i = 0; $i < $n; $i++ ) {
	$opts[] = "v$i";
}
$attr->set_name( 'Variant' );
$attr->set_options( $opts );
$attr->set_variation( true );
$p->set_name( 'Huge variable' );
$p->set_sku( 'HUGE-VAR' );
$p->set_attributes( array( $attr ) );
$p->set_cogs_value( 5000 );
$id = $p->save();
for ( $i = 0; $i < $n; $i++ ) {
	$v = new WC_Product_Variation();
	$v->set_parent_id( $id );
	$v->set_attributes( array( 'variant' => "v$i" ) );
	$v->set_regular_price( (string) ( 4000 + $i ) );
	$v->save();
}
WC_Product_Variable::sync( $id );
WP_CLI::success( sprintf( '%d variations in %.0fs', $n, microtime( true ) - $t ) );
