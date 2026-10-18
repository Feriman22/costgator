<?php
/**
 * Seeds a realistic USD demo store for the wordpress.org screenshots.
 * Run with: wp eval-file /dev-scripts/demo-seed.php (run seed.php afterwards to restore the test store).
 */

update_option( 'woocommerce_feature_cost_of_goods_sold_enabled', 'yes' );
update_option( 'woocommerce_default_country', 'US:CA' );
update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_currency_pos', 'left' );
update_option( 'woocommerce_price_num_decimals', 2 );
update_option( 'woocommerce_price_thousand_sep', ',' );
update_option( 'woocommerce_price_decimal_sep', '.' );
update_option( 'woocommerce_calc_taxes', 'no' );
update_option( 'woocommerce_prices_include_tax', 'no' );
delete_option( 'costgator_last_scan' );
delete_option( 'costgator_settings' );

foreach ( get_posts( array( 'post_type' => array( 'product', 'product_variation', 'shop_coupon' ), 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) {
	wp_delete_post( $id, true );
}

$skus = 0;
$add  = function ( $name, $regular, $cost, $sale = null, $sale_from = null ) use ( &$skus ) {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_sku( sprintf( 'DEMO-%02d', ++$skus ) );
	$p->set_regular_price( (string) $regular );
	if ( null !== $sale ) {
		$p->set_sale_price( (string) $sale );
	}
	if ( null !== $sale_from ) {
		$p->set_date_on_sale_from( $sale_from );
	}
	if ( null !== $cost ) {
		$p->set_cogs_value( (float) $cost );
	}
	$p->save();
};

$add( 'Organic Cotton T-Shirt', 24.00, 9.50 );
$add( 'Merino Wool Beanie', 18.00, 19.40 );
$add( 'Ceramic Pour-Over Set', 49.00, 31.20, 29.00 );
$add( 'Bamboo Cutting Board', 32.00, 29.90 );
$add( 'Leather Card Holder', 39.00, 14.00 );
$add( 'Linen Kitchen Apron', 28.00, 26.10 );
$add( 'Glass Water Bottle', 22.00, 13.80, 15.00, time() + 7 * DAY_IN_SECONDS );
$add( 'Soy Scented Candle', 26.00, 13.50, 12.00, time() + 3 * DAY_IN_SECONDS );
$add( 'Wireless Charging Pad', 35.00, null );
$add( 'Canvas Tote Bag', 19.00, null );
$add( 'Stainless Steel Tumbler', 30.00, 24.50 );
$add( 'Wool Throw Blanket', 89.00, 41.00 );
$add( 'Espresso Cup Set', 36.00, 17.00 );
$add( 'Dotted Notebook A5', 14.00, 4.20 );
$add( 'Polarized Sunglasses', 65.00, 21.00 );
$add( 'Cork Yoga Mat', 58.00, 44.50 );

$parent = new WC_Product_Variable();
$parent->set_name( 'Everyday Hoodie' );
$parent->set_sku( 'DEMO-HOODIE' );
$parent->set_cogs_value( 22.00 );
$attr = new WC_Product_Attribute();
$attr->set_name( 'Size' );
$attr->set_options( array( 'S', 'M', 'L', 'XXL' ) );
$attr->set_visible( true );
$attr->set_variation( true );
$parent->set_attributes( array( $attr ) );
$parent_id = $parent->save();
foreach ( array( 'S' => null, 'M' => null, 'L' => null, 'XXL' => 56.80 ) as $size => $cost ) {
	$v = new WC_Product_Variation();
	$v->set_parent_id( $parent_id );
	$v->set_attributes( array( 'size' => $size ) );
	$v->set_sku( "DEMO-HOODIE-$size" );
	$v->set_regular_price( '54.00' );
	if ( null !== $cost ) {
		$v->set_cogs_value( $cost );
	}
	$v->save();
}
WC_Product_Variable::sync( $parent_id );

foreach ( array( array( 'WELCOME10', 'percent', 10 ), array( 'SPRING25', 'percent', 25 ), array( 'BLACKFRIDAY40', 'percent', 40 ), array( 'FREESHIP10', 'fixed_cart', 10 ) ) as $c ) {
	$coupon = new WC_Coupon();
	$coupon->set_code( $c[0] );
	$coupon->set_discount_type( $c[1] );
	$coupon->set_amount( $c[2] );
	$coupon->save();
}

WP_CLI::success( 'Seeded demo store.' );
