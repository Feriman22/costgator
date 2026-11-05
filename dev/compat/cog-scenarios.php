<?php
/**
 * Checks the cost CostGator reads in the Cost of Goods (SkyVerge) scenarios, with native COGS on or off.
 * Run: wp eval-file /dev-scripts/compat/cog-scenarios.php on|off
 * The products it creates are deleted at the end.
 */

use CostGator\Cost_Provider;

$mode     = isset( $args[0] ) ? $args[0] : 'off';
$original = get_option( 'woocommerce_feature_cost_of_goods_sold_enabled' );
update_option( 'woocommerce_feature_cost_of_goods_sold_enabled', 'on' === $mode ? 'yes' : 'no' );
$native = Cost_Provider::native_enabled();
if ( ( 'on' === $mode ) !== $native ) {
	WP_CLI::error( "Native COGS state does not match mode '$mode'." );
}

$created = array();
$simple  = static function ( string $sku, ?string $cog, ?float $native_cost = null ) use ( &$created ) {
	$p = new WC_Product_Simple();
	$p->set_name( $sku );
	$p->set_sku( 'COGTEST-' . $sku );
	$p->set_regular_price( '50' );
	$p->set_status( 'publish' );
	if ( null !== $native_cost ) {
		$p->set_cogs_value( $native_cost );
	}
	$id = $p->save();
	if ( null !== $cog ) {
		update_post_meta( $id, '_wc_cog_cost', $cog );
	}
	$created[] = $id;
	return wc_get_product( $id );
};
$variable = static function ( string $sku, ?string $parent_cog, array $variation_cogs ) use ( &$created ) {
	$parent = new WC_Product_Variable();
	$parent->set_name( $sku );
	$parent->set_sku( 'COGTEST-' . $sku );
	$parent->set_status( 'publish' );
	$attr = new WC_Product_Attribute();
	$attr->set_name( 'Size' );
	$attr->set_options( array_keys( $variation_cogs ) );
	$attr->set_variation( true );
	$parent->set_attributes( array( $attr ) );
	$parent_id = $parent->save();
	if ( null !== $parent_cog ) {
		update_post_meta( $parent_id, '_wc_cog_cost', $parent_cog );
	}
	$created[] = $parent_id;
	$out       = array();
	foreach ( $variation_cogs as $size => $cog ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $parent_id );
		$v->set_attributes( array( 'size' => $size ) );
		$v->set_regular_price( '50' );
		$v->set_status( 'publish' );
		$vid = $v->save();
		if ( null !== $cog ) {
			update_post_meta( $vid, '_wc_cog_cost', $cog );
		}
		$created[]    = $vid;
		$out[ $size ] = wc_get_product( $vid );
	}
	return $out;
};

$sky       = 'Cost of Goods (SkyVerge)';
$var_own   = $variable( 'VAR-PARENT', '20', array( 'Own' => '25', 'Default' => null ) );
$var_bare  = $variable( 'VAR-NOPARENT', null, array( 'Own' => '8', 'None' => null ) );
$scenarios = array(
	'simple product with a cost'                     => array( $simple( 'SIMPLE', '12' ), 12.0, $sky ),
	'simple product with a zero cost'                => array( $simple( 'ZERO', '0' ), 0.0, $sky ),
	'simple product without a cost'                  => array( $simple( 'NONE', null ), null, null ),
	'variation with its own cost'                    => array( $var_own['Own'], 25.0, $sky ),
	'variation using the parent default cost'        => array( $var_own['Default'], 20.0, $sky ),
	'variation with its own cost, parent without'    => array( $var_bare['Own'], 8.0, $sky ),
	'variation and parent without a cost'            => array( $var_bare['None'], null, null ),
	'native cost and Cost of Goods cost on one item' => array( $simple( 'BOTH', '12', 30.0 ), $native ? 30.0 : 12.0, $native ? Cost_Provider::SOURCE_NATIVE : $sky ),
);

// WooCommerce 9.5 and 9.6 had no nullable getter and returned 0 for an unset native cost.
// A product class with that signature must still fall back to the Cost of Goods value.
$legacy_id = $simple( 'LEGACY', '12' )->get_id();
$legacy    = new class( $legacy_id ) extends WC_Product_Simple {
	public function get_cogs_value(): float {
		return 0.0;
	}
};
$scenarios['unset native cost read as 0 by WooCommerce 9.5 and 9.6'] = array( $legacy, 12.0, $sky );

$failed = 0;
foreach ( $scenarios as $label => list( $product, $cost, $source ) ) {
	$got = Cost_Provider::get( $product );
	$ok  = $got['cost'] === $cost && $got['source'] === $source;
	$failed += $ok ? 0 : 1;
	WP_CLI::log( sprintf( '  %s native %s, %s: cost %s from %s', $ok ? 'ok  ' : 'FAIL', $mode, $label, var_export( $got['cost'], true ), var_export( $got['source'], true ) ) );
}

foreach ( array_reverse( $created ) as $id ) {
	wp_delete_post( $id, true );
}
update_option( 'woocommerce_feature_cost_of_goods_sold_enabled', $original );

if ( $failed ) {
	WP_CLI::error( "$failed scenario(s) failed with native COGS $mode." );
}
WP_CLI::success( "Cost of Goods scenarios ok with native COGS $mode." );
