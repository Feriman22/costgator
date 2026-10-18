<?php
/**
 * Seeds a test store. Run with: wp eval-file /dev-scripts/seed.php
 *
 * Store: HUF (0 decimals), prices include tax, base HU, standard 27%, reduced 5%.
 * Expected findings at 10% threshold are listed next to each product and asserted in check.php.
 */

// Store settings.
update_option( 'woocommerce_feature_cost_of_goods_sold_enabled', 'yes' );
update_option( 'woocommerce_default_country', 'HU' );
update_option( 'woocommerce_currency', 'HUF' );
update_option( 'woocommerce_price_num_decimals', 0 );
update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_prices_include_tax', 'yes' );
update_option( 'woocommerce_tax_based_on', 'base' );
update_option( 'woocommerce_tax_classes', "Reduced rate\nZero rate" );
delete_option( 'costgator_last_scan' );
delete_option( 'costgator_settings' );

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_tax_rates" );
WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'HU', 'tax_rate' => '27.0000', 'tax_rate_name' => 'AFA', 'tax_rate_priority' => 1, 'tax_rate_class' => '' ) );
WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => 'HU', 'tax_rate' => '5.0000', 'tax_rate_name' => 'AFA 5', 'tax_rate_priority' => 1, 'tax_rate_class' => 'reduced-rate' ) );

// Remove previous seed data.
foreach ( get_posts( array( 'post_type' => array( 'product', 'shop_coupon' ), 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) {
	$p = wc_get_product( $id );
	if ( $p && $p->is_type( 'variable' ) ) {
		foreach ( $p->get_children() as $child ) {
			wp_delete_post( $child, true );
		}
	}
	wp_delete_post( $id, true );
}

$clearance = term_exists( 'Clearance', 'product_cat' ) ?: wp_insert_term( 'Clearance', 'product_cat' );

/**
 * Create a simple product.
 */
function cg_simple( $sku, $args ) {
	$p = new WC_Product_Simple();
	$p->set_name( $args['name'] ?? $sku );
	$p->set_sku( $sku );
	$p->set_status( $args['status'] ?? 'publish' );
	$p->set_regular_price( (string) $args['regular'] );
	if ( isset( $args['sale'] ) ) {
		$p->set_sale_price( (string) $args['sale'] );
	}
	if ( isset( $args['sale_from'] ) ) {
		$p->set_date_on_sale_from( $args['sale_from'] );
	}
	if ( isset( $args['sale_to'] ) ) {
		$p->set_date_on_sale_to( $args['sale_to'] );
	}
	if ( isset( $args['cost'] ) ) {
		$p->set_cogs_value( (float) $args['cost'] );
	}
	if ( isset( $args['tax_status'] ) ) {
		$p->set_tax_status( $args['tax_status'] );
	}
	if ( isset( $args['tax_class'] ) ) {
		$p->set_tax_class( $args['tax_class'] );
	}
	if ( isset( $args['cats'] ) ) {
		$p->set_category_ids( $args['cats'] );
	}
	$id = $p->save();
	foreach ( $args['meta'] ?? array() as $k => $v ) {
		update_post_meta( $id, $k, $v );
	}
	return $id;
}

cg_simple( 'CG-OK', array( 'regular' => 12700, 'cost' => 5000 ) );                 // net 10000, 50%: ok.
cg_simple( 'CG-BELOW', array( 'regular' => 6350, 'cost' => 6000 ) );               // net 5000: below_cost.
cg_simple( 'CG-TAXTRAP', array( 'regular' => 12700, 'cost' => 11000 ) );           // net 10000 < 11000 < gross: below_cost.
cg_simple( 'CG-SALE', array( 'regular' => 25400, 'sale' => 12700, 'cost' => 12000 ) ); // active sale net 10000: below_cost, on sale.
cg_simple( 'CG-LOW', array( 'regular' => 12700, 'cost' => 9500 ) );                // 5%: low_margin.
cg_simple( 'CG-NOCOST', array( 'regular' => 12700 ) );                             // missing_cost.
cg_simple( 'CG-ZEROCOST', array( 'regular' => 12700, 'cost' => 0 ) );              // native 0 == null: missing_cost.
cg_simple( 'CG-FUTURE', array( 'regular' => 25400, 'sale' => 8890, 'sale_from' => time() + 10 * DAY_IN_SECONDS, 'cost' => 8000 ) ); // now 60%, future net 7000: future_below_cost.
cg_simple( 'CG-SKYV', array( 'regular' => 7620, 'meta' => array( '_wc_cog_cost' => '9000' ) ) ); // net 6000 < 9000 from SkyVerge meta: below_cost.
cg_simple( 'CG-NOTAX', array( 'regular' => 10000, 'cost' => 9800, 'tax_status' => 'none' ) );   // not taxed, 2%: low_margin.
cg_simple( 'CG-REDUCED', array( 'regular' => 10500, 'cost' => 9500, 'tax_class' => 'reduced-rate' ) ); // 5% VAT, net 10000, 5%: low_margin.
cg_simple( 'CG-BREAKEVEN', array( 'regular' => 12700, 'cost' => 10000 ) );         // 0%: low_margin, not below.
cg_simple( 'CG-FREE', array( 'regular' => 0, 'cost' => 3000 ) );                 // priced 0 with a cost: below_cost.
cg_simple( 'CG-BADSALE', array( 'regular' => 12700, 'sale' => 1000, 'sale_from' => time() + 5 * DAY_IN_SECONDS, 'sale_to' => time() + 2 * DAY_IN_SECONDS, 'cost' => 5000 ) ); // end before start: no future finding.
cg_simple( 'CG-DRAFT', array( 'regular' => 100, 'cost' => 5000, 'status' => 'draft' ) ); // not scanned.
cg_simple( 'CG-CLEAR', array( 'regular' => 12700, 'cost' => 6000, 'cats' => array( (int) $clearance['term_id'] ) ) ); // 40%: ok.

// Variable product, parent cost 5000.
$parent = new WC_Product_Variable();
$parent->set_name( 'CG Variable Tee' );
$parent->set_sku( 'CG-VAR' );
$parent->set_cogs_value( 5000 );
$attr = new WC_Product_Attribute();
$attr->set_name( 'Size' );
$attr->set_options( array( 'S', 'M', 'L', 'XL' ) );
$attr->set_visible( true );
$attr->set_variation( true );
$parent->set_attributes( array( $attr ) );
$parent_id = $parent->save();

$variations = array(
	array( 'S', 'CG-VAR-A', 12700, null, false, 'publish' ),  // inherits 5000: ok.
	array( 'M', 'CG-VAR-B', 12700, 11000, false, 'publish' ), // own 11000 > net 10000: below_cost.
	array( 'L', 'CG-VAR-C', 7620, 2000, true, 'publish' ),    // additive 5000 + 2000 > net 6000: below_cost.
	array( 'XL', 'CG-VAR-D', 100, 5000, false, 'private' ),   // disabled: not scanned.
);
foreach ( $variations as $v ) {
	$var = new WC_Product_Variation();
	$var->set_parent_id( $parent_id );
	$var->set_attributes( array( 'size' => $v[0] ) );
	$var->set_sku( $v[1] );
	$var->set_regular_price( (string) $v[2] );
	$var->set_status( $v[5] );
	if ( null !== $v[3] ) {
		$var->set_cogs_value( (float) $v[3] );
	}
	$var->set_cogs_value_is_additive( $v[4] );
	$var->save();
}
WC_Product_Variable::sync( $parent_id );

// Variable product whose parent has a SkyVerge cost and child a WPFactory cost: the child's own cost wins.
$parent2 = new WC_Product_Variable();
$parent2->set_name( 'CG Meta Mug' );
$parent2->set_sku( 'CG-VM' );
$attr2 = new WC_Product_Attribute();
$attr2->set_name( 'Color' );
$attr2->set_options( array( 'Red' ) );
$attr2->set_visible( true );
$attr2->set_variation( true );
$parent2->set_attributes( array( $attr2 ) );
$parent2_id = $parent2->save();
update_post_meta( $parent2_id, '_wc_cog_cost', '1000' );
$var2 = new WC_Product_Variation();
$var2->set_parent_id( $parent2_id );
$var2->set_attributes( array( 'color' => 'Red' ) );
$var2->set_sku( 'CG-VM-RED' );
$var2->set_regular_price( '7620' );
$var2_id = $var2->save();
update_post_meta( $var2_id, '_alg_wc_cog_cost', '9000' ); // net 6000 < 9000: below_cost from WPFactory.
WC_Product_Variable::sync( $parent2_id );

/**
 * Create a coupon.
 */
function cg_coupon( $code, $type, $amount, $extra = array() ) {
	$c = new WC_Coupon();
	$c->set_code( $code );
	$c->set_discount_type( $type );
	$c->set_amount( $amount );
	foreach ( $extra as $method => $value ) {
		$c->{"set_$method"}( $value );
	}
	return $c->save();
}

cg_coupon( 'save30', 'percent', 30 );
cg_coupon( 'clear50', 'percent', 50, array( 'product_categories' => array( (int) $clearance['term_id'] ) ) );
cg_coupon( 'save15nosale', 'percent', 15, array( 'exclude_sale_items' => true ) );
cg_coupon( 'zero0', 'percent', 0 ); // 0% coupon: ignored.
cg_coupon( 'fix1000', 'fixed_cart', 1000 );
cg_coupon( 'old90', 'percent', 90, array( 'date_expires' => time() - DAY_IN_SECONDS ) );

WP_CLI::success( 'Seeded test store.' );
