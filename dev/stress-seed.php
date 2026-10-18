<?php
/**
 * Randomized stress catalog with an independent oracle.
 *
 * Usage: wp eval-file /dev-scripts/stress-seed.php <config> <parents> <seed>
 *   config: huf_incl | eur_excl | eur_multi | huf_nocogs
 *
 * The oracle computes expected results from the generator's own parameters (gross price, tax rates,
 * cost rules, coupon restrictions) without calling the plugin, and stores them in the
 * cg_stress_expected option for stress-check.php.
 */

$config  = $args[0] ?? 'huf_incl';
$parents = (int) ( $args[1] ?? 500 );
$seed    = (int) ( $args[2] ?? 42 );
mt_srand( $seed );

$configs = array(
	// currency, decimals, prices include tax, COGS enabled, tax rates per class: [rate, compound, priority].
	'huf_incl'   => array( 'HUF', 0, true, true, array( '' => array( array( 27, false, 1 ) ), 'reduced-rate' => array( array( 5, false, 1 ) ), 'zero-rate' => array( array( 0, false, 1 ) ) ) ),
	'eur_excl'   => array( 'EUR', 2, false, true, array( '' => array( array( 19, false, 1 ) ), 'reduced-rate' => array( array( 7, false, 1 ) ), 'zero-rate' => array( array( 0, false, 1 ) ) ) ),
	'eur_multi'  => array( 'EUR', 2, true, true, array( '' => array( array( 20, false, 1 ), array( 7, false, 2 ) ), 'reduced-rate' => array( array( 10, false, 1 ), array( 5, true, 2 ) ), 'zero-rate' => array() ) ),
	'huf_nocogs' => array( 'HUF', 0, true, false, array( '' => array( array( 27, false, 1 ) ), 'reduced-rate' => array( array( 5, false, 1 ) ), 'zero-rate' => array( array( 0, false, 1 ) ) ) ),
);
list( $currency, $decimals, $incl, $cogs, $tax_rates ) = $configs[ $config ];
// eval-file runs inside a function, so helpers need explicit globals.
foreach ( array( 'currency', 'decimals', 'incl', 'cogs', 'tax_rates' ) as $cg_var ) {
	$GLOBALS[ $cg_var ] = $$cg_var;
}
$GLOBALS['now'] = time();

$t0 = microtime( true );
wp_defer_term_counting( true );
wp_defer_comment_counting( true );

// Store settings.
update_option( 'woocommerce_feature_cost_of_goods_sold_enabled', $cogs ? 'yes' : 'no' );
update_option( 'woocommerce_default_country', 'HU' );
update_option( 'woocommerce_currency', $currency );
update_option( 'woocommerce_price_num_decimals', $decimals );
update_option( 'woocommerce_calc_taxes', 'yes' );
update_option( 'woocommerce_prices_include_tax', $incl ? 'yes' : 'no' );
update_option( 'woocommerce_tax_based_on', 'base' );
update_option( 'woocommerce_tax_classes', "Reduced rate\nZero rate" );
delete_option( 'costgator_last_scan' );
delete_option( 'costgator_scan_running' );
update_option( 'costgator_settings', array( 'threshold' => 10.0 ), false );

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->prefix}woocommerce_tax_rates" );
foreach ( $tax_rates as $class => $rates ) {
	foreach ( $rates as $r ) {
		WC_Tax::_insert_tax_rate(
			array(
				'tax_rate_country'  => 'HU',
				'tax_rate'          => number_format( $r[0], 4, '.', '' ),
				'tax_rate_name'     => "T{$r[0]}",
				'tax_rate_priority' => $r[2],
				'tax_rate_compound' => $r[1] ? 1 : 0,
				'tax_rate_class'    => $class,
			)
		);
	}
}

// Wipe catalog quickly.
$wpdb->query( "DELETE p, pm FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID WHERE p.post_type IN ('product','product_variation','shop_coupon')" );
$wpdb->query( "DELETE FROM {$wpdb->prefix}wc_product_meta_lookup" );
$wpdb->query( "DELETE tr FROM {$wpdb->term_relationships} tr LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE p.ID IS NULL" );
wp_cache_flush();

// Categories.
$cats = array();
for ( $i = 0; $i < 12; $i++ ) {
	$name   = "Stress Cat $i";
	$term   = term_exists( $name, 'product_cat' ) ?: wp_insert_term( $name, 'product_cat' );
	$cats[] = (int) $term['term_id'];
}

/** Random pick helpers. */
function cg_pick( array $weighted ) {
	$total = array_sum( $weighted );
	$r     = mt_rand( 1, $total );
	foreach ( $weighted as $key => $w ) {
		$r -= $w;
		if ( $r <= 0 ) {
			return $key;
		}
	}
	return array_key_first( $weighted );
}
function cg_rand_float( $min, $max ) {
	return $min + mt_rand() / mt_getrandmax() * ( $max - $min );
}

$names_pool = array( 'Póló', 'Kávé ☕', 'Füzet "A5"', "O'Brien mug", '<b>Bold</b> & co', 'Çédille', '日本茶', 'Tee', 'Lámpa', 'Würfel' );

/**
 * Net price from gross with the configured rates, mirroring the order WooCommerce applies them
 * (compound rates removed first, then the summed regular rates). Independent of the plugin.
 */
function cg_oracle_net( $gross, $class, $taxable ) {
	global $incl, $tax_rates;
	if ( ! $incl || ! $taxable ) {
		return $gross;
	}
	$rates = $tax_rates[ $class ] ?? array();
	$net   = $gross;
	foreach ( array_reverse( $rates ) as $r ) {
		if ( $r[1] ) {
			$net = $net / ( 1 + $r[0] / 100 );
		}
	}
	$regular = 0;
	foreach ( $rates as $r ) {
		if ( ! $r[1] ) {
			$regular += $r[0];
		}
	}
	return $net / ( 1 + $regular / 100 );
}

/** Gross price for a target net (rounded to store decimals, like a merchant would type it). */
function cg_gross_for_net( $net, $class, $taxable ) {
	global $decimals;
	$factor = $net > 0 ? cg_oracle_net( $net, $class, $taxable ) / $net : 1;
	return round( $net / $factor, $decimals );
}

/** Oracle classification. Null when too close to a boundary to be meaningful. */
function cg_oracle_status( $net, $cost, $threshold ) {
	global $decimals;
	$unit   = pow( 10, -$decimals );
	$profit = $net - $cost;
	if ( abs( $profit ) < 2 * $unit ) {
		return null;
	}
	if ( $profit < 0 ) {
		return 'below_cost';
	}
	$margin = $net > 0 ? $profit / $net * 100 : 0;
	if ( abs( $margin - $threshold ) < 0.05 ) {
		return null;
	}
	return $margin < $threshold ? 'low_margin' : 'ok';
}

$GLOBALS['expected'] = array(
	'config'    => $config,
	'items'     => array(), // sku => expectation.
	'skipped'   => array(), // skus that must not appear.
	'coupons'   => array(),
	'parents'   => 0,
	'threshold' => 10.0,
);
$expected   = &$GLOBALS['expected'];
$now        = $GLOBALS['now'];
$sku_n      = 0;
$catalog    = array(); // For coupon oracle: sellable sku => [id, parent_id, cats, on_sale, net_current, cost].
$base_price = 'HUF' === $currency ? array( 500, 200000 ) : array( 1, 2000 );

/**
 * Cost setup for one product. Returns [expected cost or null, expected source, setter callback].
 */
function cg_cost_plan( $net_reference, $allow_native ) {
	global $cogs, $decimals;
	$kind = cg_pick(
		array(
			'none'         => 8,
			'native'       => 50,
			'native_zero'  => 3,
			'skyverge'     => 10,
			'wpfactory'    => 6,
			'native+meta'  => 6,
			'meta_weird'   => 4,
			'meta_space'   => 3,
			'meta_zero'    => 2,
			'meta_neg'     => 2,
		)
	);
	if ( ! $allow_native && in_array( $kind, array( 'native', 'native_zero', 'native+meta' ), true ) ) {
		$kind = 'none';
	}
	// Margin target spread so every status occurs.
	$target_margin = cg_pick( array( -40 => 10, -5 => 8, 3 => 8, 8 => 6, 15 => 10, 35 => 30, 60 => 28 ) );
	$cost          = round( max( 0.01, $net_reference * ( 1 - $target_margin / 100 ) * cg_rand_float( 0.97, 1.03 ) ), $decimals ?: 0 );
	if ( $cost <= 0 ) {
		$cost = 1;
	}
	$plan = array(
		'kind'   => $kind,
		'native' => null,
		'meta'   => array(),
		'cost'   => null,
		'source' => null,
	);
	switch ( $kind ) {
		case 'native':
			$plan['native'] = $cost;
			if ( $cogs ) {
				$plan['cost']   = $cost;
				$plan['source'] = 'native';
			}
			break;
		case 'native_zero':
			$plan['native'] = 0.0; // Stored as null on simple products.
			break;
		case 'skyverge':
			$plan['meta']['_wc_cog_cost'] = (string) $cost;
			$plan['cost']                 = $cost;
			$plan['source']               = 'Cost of Goods (SkyVerge)';
			break;
		case 'wpfactory':
			$plan['meta']['_alg_wc_cog_cost'] = (string) $cost;
			$plan['cost']                     = $cost;
			$plan['source']                   = 'Cost of Goods (WPFactory)';
			break;
		case 'native+meta':
			$plan['native']               = $cost;
			$plan['meta']['_wc_cog_cost'] = (string) ( $cost * 3 );
			$plan['cost']                 = $cogs ? $cost : $cost * 3;
			$plan['source']               = $cogs ? 'native' : 'Cost of Goods (SkyVerge)';
			break;
		case 'meta_weird':
			$plan['meta']['_wc_cog_cost'] = str_replace( '.', ',', number_format( $cost + 0.5, 2, '.', '' ) ); // "12,50" is not numeric.
			break;
		case 'meta_space':
			$plan['meta']['_wc_cog_cost'] = ' ' . $cost . ' ';
			$plan['cost']                 = (float) $cost;
			$plan['source']               = 'Cost of Goods (SkyVerge)';
			break;
		case 'meta_zero':
			$plan['meta']['_wc_cog_cost'] = '0';
			$plan['cost']                 = 0.0;
			$plan['source']               = 'Cost of Goods (SkyVerge)';
			break;
		case 'meta_neg':
			$plan['meta']['_wc_cog_cost'] = '-5';
			$plan['cost']                 = -5.0;
			$plan['source']               = 'Cost of Goods (SkyVerge)';
			break;
	}
	return $plan;
}

/** Sale plan: returns [sale gross or null, from, to, active, future]. */
function cg_sale_plan( $regular_gross ) {
	global $now, $decimals;
	$kind = cg_pick( array( 'none' => 55, 'active' => 15, 'active_dated' => 8, 'future' => 10, 'expired' => 7, 'sale_eq_regular' => 2, 'sale_gt_regular' => 3 ) );
	$sale = round( $regular_gross * cg_rand_float( 0.3, 0.9 ), $decimals );
	switch ( $kind ) {
		case 'active':
			return array( $sale, null, null, true, false, $kind );
		case 'active_dated':
			return array( $sale, $now - 5 * DAY_IN_SECONDS, $now + 5 * DAY_IN_SECONDS, true, false, $kind );
		case 'future':
			return array( $sale, $now + mt_rand( 2, 30 ) * DAY_IN_SECONDS, null, false, true, $kind );
		case 'expired':
			return array( $sale, $now - 20 * DAY_IN_SECONDS, $now - 2 * DAY_IN_SECONDS, false, false, $kind );
		case 'sale_eq_regular':
			return array( $regular_gross, null, null, false, false, $kind );
		case 'sale_gt_regular':
			return array( round( $regular_gross * 1.2, $decimals ), null, null, false, false, $kind );
	}
	return array( null, null, null, false, false, $kind );
}

function cg_apply_cost( WC_Product $p, array $plan ) {
	global $cogs;
	if ( $cogs && null !== $plan['native'] ) {
		$p->set_cogs_value( (float) $plan['native'] );
	}
}

function cg_apply_sale( WC_Product $p, array $sale ) {
	if ( null === $sale[0] ) {
		return;
	}
	$p->set_sale_price( (string) $sale[0] );
	if ( $sale[1] ) {
		$p->set_date_on_sale_from( $sale[1] );
	}
	if ( $sale[2] ) {
		$p->set_date_on_sale_to( $sale[2] );
	}
}

/** Build the oracle expectation for a sellable item. */
function cg_expect( $sku, $regular_gross, array $sale, $class, $taxable, $cost, $source ) {
	global $expected;
	$current_gross = $sale[3] ? $sale[0] : $regular_gross;
	$net_current   = cg_oracle_net( $current_gross, $class, $taxable );
	$e             = array(
		'net_current' => $net_current,
		'net_regular' => cg_oracle_net( $regular_gross, $class, $taxable ),
		'on_sale'     => $sale[3],
		'cost'        => $cost,
		'source'      => $source,
		'status'      => null,
		'future'      => null,
		'sale_kind'   => $sale[5],
	);
	if ( null === $cost || $cost <= 0 ) {
		$e['status'] = 'missing_cost';
	} else {
		$e['status'] = cg_oracle_status( $net_current, $cost, $expected['threshold'] );
		if ( $sale[4] ) {
			$e['future'] = cg_oracle_status( cg_oracle_net( $sale[0], $class, $taxable ), $cost, $expected['threshold'] );
		} else {
			$e['future'] = 'none';
		}
	}
	return $e;
}

$tax_classes = array( '' => 70, 'reduced-rate' => 20, 'zero-rate' => 10 );

for ( $n = 0; $n < $parents; $n++ ) {
	$type   = cg_pick( array( 'simple' => 62, 'variable' => 25, 'external' => 3, 'grouped' => 2, 'noprice' => 3, 'free' => 2, 'hidden' => 3 ) );
	$status = cg_pick( array( 'publish' => 90, 'draft' => 4, 'private' => 3, 'pending' => 3 ) );
	$name   = $names_pool[ array_rand( $names_pool ) ] . " #$n";
	$pcats  = array_rand( array_flip( $cats ), mt_rand( 1, 3 ) );
	$pcats  = (array) $pcats;
	$class  = cg_pick( $tax_classes );
	$tstat  = cg_pick( array( 'taxable' => 85, 'none' => 10, 'shipping' => 5 ) );
	$sku    = sprintf( 'S%05d', ++$sku_n );

	if ( 'variable' === $type ) {
		$p = new WC_Product_Variable();
	} elseif ( 'external' === $type ) {
		$p = new WC_Product_External();
	} elseif ( 'grouped' === $type ) {
		$p = new WC_Product_Grouped();
	} else {
		$p = new WC_Product_Simple();
	}
	$p->set_name( $name );
	$p->set_sku( $sku );
	$p->set_status( $status );
	$p->set_category_ids( $pcats );
	$p->set_tax_class( $class );
	$p->set_tax_status( $tstat );
	if ( 'hidden' === $type ) {
		$p->set_catalog_visibility( 'hidden' );
	}
	$taxable   = 'taxable' === $tstat;
	$published = 'publish' === $status;

	if ( 'variable' !== $type ) {
		$net_ref = round( cg_rand_float( $base_price[0], $base_price[1] ), $decimals );
		$regular = cg_gross_for_net( $net_ref, $class, $taxable );
		$plan    = cg_cost_plan( $net_ref, true );
		$sale    = array( null, null, null, false, false, 'none' );
		if ( 'noprice' === $type ) {
			$regular = '';
		} elseif ( 'free' === $type ) {
			$regular = 0;
		} elseif ( 'grouped' !== $type ) {
			$sale = cg_sale_plan( (float) $regular );
		}
		if ( 'grouped' !== $type ) {
			$p->set_regular_price( (string) $regular );
			cg_apply_sale( $p, $sale );
		}
		cg_apply_cost( $p, $plan );
		$id = $p->save();
		foreach ( $plan['meta'] as $k => $v ) {
			update_post_meta( $id, $k, $v );
		}

		if ( ! $published || in_array( $type, array( 'external', 'grouped', 'noprice' ), true ) ) {
			$expected['skipped'][] = $sku;
		} else {
			$e                          = cg_expect( $sku, (float) $regular, $sale, $class, $taxable, $plan['cost'], $plan['source'] );
			$expected['items'][ $sku ]  = $e;
			$catalog[ $sku ]            = array( $id, 0, $pcats, $sale[3], $e['net_current'], $plan['cost'] );
		}
		if ( $published && ! in_array( $type, array( 'external', 'grouped' ), true ) ) {
			++$expected['parents'];
		}
		continue;
	}

	// Variable product.
	$var_count = cg_pick( array( 1 => 10, 3 => 30, 8 => 30, 20 => 20, 60 => 8, 250 => 2 ) );
	$opts      = array();
	for ( $i = 0; $i < $var_count; $i++ ) {
		$opts[] = "o$i";
	}
	$attr = new WC_Product_Attribute();
	$attr->set_name( 'Option' );
	$attr->set_options( $opts );
	$attr->set_visible( true );
	$attr->set_variation( true );
	$p->set_attributes( array( $attr ) );
	$parent_net  = round( cg_rand_float( $base_price[0], $base_price[1] ), $decimals );
	$parent_plan = cg_cost_plan( $parent_net, true );
	cg_apply_cost( $p, $parent_plan );
	$parent_id = $p->save();
	foreach ( $parent_plan['meta'] as $k => $v ) {
		update_post_meta( $parent_id, $k, $v );
	}
	if ( $published ) {
		++$expected['parents'];
	}
	// Native parent effective value: null/0 counts as 0 for additive maths. Parent zero is stored as null.
	$parent_native = ( $cogs && null !== $parent_plan['native'] && $parent_plan['native'] > 0 ) ? (float) $parent_plan['native'] : null;
	// Parent meta (first matching key in provider order).
	$parent_meta = null;
	foreach ( array( '_wc_cog_cost' => 'Cost of Goods (SkyVerge)', '_alg_wc_cog_cost' => 'Cost of Goods (WPFactory)' ) as $k => $label ) {
		if ( isset( $parent_plan['meta'][ $k ] ) && is_numeric( $parent_plan['meta'][ $k ] ) ) {
			$parent_meta = array( (float) $parent_plan['meta'][ $k ], $label );
			break;
		}
	}

	for ( $i = 0; $i < $var_count; $i++ ) {
		$vsku    = $sku . '-' . $i;
		$vstatus = cg_pick( array( 'publish' => 90, 'private' => 10 ) );
		$vclass  = cg_pick( array( 'parent' => 70, '' => 15, 'reduced-rate' => 10, 'zero-rate' => 5 ) );
		$eff_cls = 'parent' === $vclass ? $class : $vclass;
		$net_ref = round( $parent_net * cg_rand_float( 0.7, 1.3 ), $decimals );
		$regular = cg_gross_for_net( $net_ref, $eff_cls, $taxable );
		$sale    = cg_sale_plan( $regular );
		$vkind   = cg_pick( array( 'inherit' => 35, 'own' => 35, 'additive' => 12, 'own_zero' => 5, 'meta' => 13 ) );
		$v       = new WC_Product_Variation();
		$v->set_parent_id( $parent_id );
		$v->set_attributes( array( 'option' => "o$i" ) );
		$v->set_sku( $vsku );
		$v->set_status( $vstatus );
		$v->set_tax_class( $vclass );
		$v->set_regular_price( (string) $regular );
		cg_apply_sale( $v, $sale );

		$own_cost  = round( $net_ref * cg_rand_float( 0.4, 1.2 ), $decimals ?: 0 );
		$add_cost  = round( $net_ref * cg_rand_float( 0.05, 0.4 ), $decimals ?: 0 );
		$cost      = null;
		$source    = null;
		$vmeta     = array();
		if ( 'own' === $vkind && $cogs ) {
			$v->set_cogs_value( $own_cost );
		} elseif ( 'additive' === $vkind && $cogs ) {
			$v->set_cogs_value( $add_cost );
			$v->set_cogs_value_is_additive( true );
		} elseif ( 'own_zero' === $vkind && $cogs ) {
			$v->set_cogs_value( 0.0 ); // Variations keep an explicit zero.
		} elseif ( 'meta' === $vkind ) {
			$vmeta['_wc_cog_cost'] = (string) $own_cost;
		}
		$vid = $v->save();
		foreach ( $vmeta as $k => $val ) {
			update_post_meta( $vid, $k, $val );
		}

		// Oracle for the variation cost: native wins when the variation or the parent has a native value.
		$var_native = null;
		if ( $cogs ) {
			if ( 'own' === $vkind ) {
				$var_native = $own_cost;
			} elseif ( 'additive' === $vkind ) {
				$var_native = ( $parent_native ?? 0 ) + $add_cost;
			} elseif ( 'own_zero' === $vkind ) {
				$var_native = 0.0;
			} elseif ( null !== $parent_native ) {
				$var_native = $parent_native;
			}
		}
		if ( null !== $var_native ) {
			$cost   = $var_native;
			$source = 'native';
		} elseif ( 'meta' === $vkind ) {
			$cost   = (float) $own_cost;
			$source = 'Cost of Goods (SkyVerge)';
		} elseif ( null !== $parent_meta ) {
			$cost   = $parent_meta[0];
			$source = $parent_meta[1];
		}

		if ( ! $published || 'publish' !== $vstatus ) {
			$expected['skipped'][] = $vsku;
			continue;
		}
		$e                         = cg_expect( $vsku, (float) $regular, $sale, $eff_cls, $taxable, $cost, $source );
		$expected['items'][ $vsku ] = $e;
		$catalog[ $vsku ]           = array( $vid, $parent_id, $pcats, $sale[3], $e['net_current'], $cost );
	}
	WC_Product_Variable::sync( $parent_id );
	if ( 0 === $n % 100 ) {
		wp_cache_flush();
	}
}

// Coupons with restrictions, and an oracle replicating WC_Coupon::is_valid_for_product.
$skus = array_keys( $catalog );
for ( $c = 0; $c < 40; $c++ ) {
	$code   = "stress$c";
	$kind   = cg_pick( array( 'percent' => 75, 'fixed_cart' => 10, 'fixed_product' => 10, 'expired' => 3, 'used_up' => 2 ) );
	$amount = cg_pick( array( 5 => 3, 10 => 3, 15 => 2, 25 => 3, 40 => 2, 70 => 1, 100 => 1 ) );
	$cp     = new WC_Coupon();
	$cp->set_code( $code );
	$cp->set_discount_type( in_array( $kind, array( 'fixed_cart', 'fixed_product' ), true ) ? $kind : 'percent' );
	$cp->set_amount( $amount );
	$incl_ids = $excl_ids = $incl_cats = $excl_cats = array();
	if ( $skus && mt_rand( 1, 100 ) <= 30 ) {
		foreach ( (array) array_rand( $skus, min( count( $skus ), mt_rand( 1, 15 ) ) ) as $k ) {
			$row        = $catalog[ $skus[ $k ] ];
			$incl_ids[] = ( $row[1] && mt_rand( 0, 1 ) ) ? $row[1] : $row[0]; // Parent or variation id.
		}
	}
	if ( mt_rand( 1, 100 ) <= 30 ) {
		$incl_cats = (array) array_rand( array_flip( $cats ), mt_rand( 1, 3 ) );
	}
	if ( $skus && mt_rand( 1, 100 ) <= 20 ) {
		foreach ( (array) array_rand( $skus, min( count( $skus ), mt_rand( 1, 20 ) ) ) as $k ) {
			$row        = $catalog[ $skus[ $k ] ];
			$excl_ids[] = ( $row[1] && mt_rand( 0, 1 ) ) ? $row[1] : $row[0];
		}
	}
	if ( mt_rand( 1, 100 ) <= 20 ) {
		$excl_cats = (array) array_rand( array_flip( $cats ), 1 );
	}
	$excl_sale = mt_rand( 1, 100 ) <= 25;
	$cp->set_product_ids( $incl_ids );
	$cp->set_excluded_product_ids( $excl_ids );
	$cp->set_product_categories( $incl_cats );
	$cp->set_excluded_product_categories( $excl_cats );
	$cp->set_exclude_sale_items( $excl_sale );
	if ( 'expired' === $kind ) {
		$cp->set_date_expires( $now - DAY_IN_SECONDS );
	}
	if ( 'used_up' === $kind ) {
		$cp->set_usage_limit( 3 );
		$cp->set_usage_count( 3 );
	}
	$cp->save();

	$exp = array(
		'kind'       => $kind,
		'below_cost' => 0,
		'low_margin' => 0,
		'ambiguous'  => 0,
	);
	if ( 'percent' === $kind ) {
		foreach ( $catalog as $sku => $row ) {
			list( $id, $parent_id, $pc, $on_sale, $net, $cost ) = $row;
			$ids   = array( $id, $parent_id );
			$valid = false;
			if ( $incl_ids && array_intersect( $ids, $incl_ids ) ) {
				$valid = true;
			}
			if ( $incl_cats && array_intersect( $pc, $incl_cats ) ) {
				$valid = true;
			}
			if ( ! $incl_ids && ! $incl_cats ) {
				$valid = true;
			}
			if ( $excl_ids && array_intersect( $ids, $excl_ids ) ) {
				$valid = false;
			}
			if ( $excl_cats && array_intersect( $pc, $excl_cats ) ) {
				$valid = false;
			}
			if ( $excl_sale && $on_sale ) {
				$valid = false;
			}
			if ( ! $valid || null === $cost || $cost <= 0 ) {
				continue;
			}
			$st = cg_oracle_status( $net * ( 1 - $amount / 100 ), $cost, $expected['threshold'] );
			if ( null === $st ) {
				++$exp['ambiguous'];
			} elseif ( 'ok' !== $st ) {
				++$exp[ $st ];
			}
		}
	}
	$expected['coupons'][ $code ] = $exp;
}

wp_defer_term_counting( false );
update_option( 'cg_stress_expected', $expected, false );

$var_total = count( $expected['items'] );
WP_CLI::success( sprintf( '%s: %d parents, %d expected sellable items, %d skipped, %.1fs', $config, $parents, $var_total, count( $expected['skipped'] ), microtime( true ) - $t0 ) );
