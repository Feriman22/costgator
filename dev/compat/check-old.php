<?php
/**
 * Smoke test on the minimum versions (WordPress 6.5, PHP 7.4, WooCommerce 8.0, no native COGS).
 * Usage: wp eval-file /dev-scripts/compat/check-old.php
 */

use CostGator\Cost_Provider;
use CostGator\Scanner;

update_option( 'woocommerce_calc_taxes', 'no' );
foreach ( get_posts( array( 'post_type' => array( 'product', 'product_variation', 'shop_coupon' ), 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) {
	wp_delete_post( $id, true );
}

function cg_old_simple( $sku, $regular, $cost, $sale = null, $from = null ) {
	$p = new WC_Product_Simple();
	$p->set_name( $sku );
	$p->set_sku( $sku );
	$p->set_regular_price( (string) $regular );
	if ( null !== $sale ) {
		$p->set_sale_price( (string) $sale );
	}
	if ( null !== $from ) {
		$p->set_date_on_sale_from( $from );
	}
	$id = $p->save();
	if ( null !== $cost ) {
		update_post_meta( $id, '_wc_cog_cost', (string) $cost );
	}
}

cg_old_simple( 'OLD-OK', 100, 50 );
cg_old_simple( 'OLD-BELOW', 100, 120 );
cg_old_simple( 'OLD-LOW', 100, 95 );
cg_old_simple( 'OLD-NOCOST', 100, null );
cg_old_simple( 'OLD-FUTURE', 100, 60, 50, time() + 5 * DAY_IN_SECONDS );

$parent = new WC_Product_Variable();
$parent->set_name( 'OLD-VAR' );
$parent->set_sku( 'OLD-VAR' );
$attr = new WC_Product_Attribute();
$attr->set_name( 'Size' );
$attr->set_options( array( 'S', 'M' ) );
$attr->set_variation( true );
$parent->set_attributes( array( $attr ) );
$parent_id = $parent->save();
update_post_meta( $parent_id, '_alg_wc_cog_cost', '70' );
foreach ( array( 'S' => 100, 'M' => 60 ) as $size => $price ) {
	$v = new WC_Product_Variation();
	$v->set_parent_id( $parent_id );
	$v->set_attributes( array( 'size' => $size ) );
	$v->set_sku( "OLD-VAR-$size" );
	$v->set_regular_price( (string) $price );
	$v->save();
}
WC_Product_Variable::sync( $parent_id );

$c = new WC_Coupon();
$c->set_code( 'old40' );
$c->set_discount_type( 'percent' );
$c->set_amount( 40 );
$c->save();

$fails = array();
if ( Cost_Provider::native_enabled() ) {
	$fails[] = 'native COGS reported as enabled on WooCommerce ' . WC()->version;
}

$status = Scanner::start( 10.0 );
while ( ! $status['done'] ) {
	$status = Scanner::step( $status['scan_id'] );
	if ( is_wp_error( $status ) ) {
		WP_CLI::error( $status->get_error_message() );
	}
}
$scan  = Scanner::last_result();
$found = array();
foreach ( $scan['findings'] as $f ) {
	$found[ $f['sku'] ][] = $f['type'];
}
$expect = array(
	'OLD-BELOW'  => array( 'below_cost' ),
	'OLD-LOW'    => array( 'low_margin' ),
	'OLD-NOCOST' => array( 'missing_cost' ),
	'OLD-FUTURE' => array( 'future_below_cost' ),
	'OLD-VAR-M'  => array( 'below_cost' ),
);
foreach ( $expect as $sku => $types ) {
	if ( ( $found[ $sku ] ?? array() ) !== $types ) {
		$fails[] = "$sku findings " . wp_json_encode( $found[ $sku ] ?? array() ) . ' != ' . wp_json_encode( $types );
	}
}
foreach ( array( 'OLD-OK', 'OLD-VAR-S' ) as $sku ) {
	if ( isset( $found[ $sku ] ) ) {
		$fails[] = "$sku should have no findings";
	}
}
if ( 7 !== count( $scan['items'] ) ) {
	$fails[] = 'items ' . count( $scan['items'] ) . ' != 7';
}
$coupon = reset( $scan['coupons'] );
if ( ! $coupon || 'old40' !== $coupon['code'] ) {
	$fails[] = 'coupon old40 not flagged';
}
$pf = Scanner::preflight( $scan, 50, 'regular', 10.0 );
if ( 0 === count( $pf['below_cost'] ) ) {
	$fails[] = 'preflight found nothing';
}

if ( $fails ) {
	WP_CLI::error( implode( "\n", $fails ) );
}
WP_CLI::success( sprintf( 'WP %s, PHP %s, WC %s: scan, coupons and preflight OK.', get_bloginfo( 'version' ), PHP_VERSION, WC()->version ) );
