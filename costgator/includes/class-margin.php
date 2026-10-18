<?php
/**
 * Pure margin math, independent of WordPress so it can be unit tested.
 *
 * @package CostGator
 */

namespace CostGator;

defined( 'ABSPATH' ) || exit;

/**
 * Gross margin helpers. All prices passed in are expected to be net of tax.
 */
final class Margin {

	const STATUS_BELOW_COST = 'below_cost';
	const STATUS_LOW_MARGIN = 'low_margin';
	const STATUS_OK         = 'ok';

	/**
	 * Gross margin in percent: (price - cost) / price * 100.
	 *
	 * @param float $net_price Net selling price.
	 * @param float $cost      Unit cost.
	 * @return float|null Null when the price is not positive.
	 */
	public static function percent( float $net_price, float $cost ): ?float {
		if ( $net_price <= 0 ) {
			return null;
		}
		return ( $net_price - $cost ) / $net_price * 100;
	}

	/**
	 * Classify a price against a cost and a minimum margin threshold.
	 *
	 * The profit is rounded to the store's price precision first, so a price that equals
	 * the cost after rounding is treated as break-even (low margin), not as a loss.
	 *
	 * @param float $net_price Net selling price.
	 * @param float $cost      Unit cost, must be positive.
	 * @param float $threshold Minimum acceptable margin in percent.
	 * @param int   $decimals  Price decimals of the store currency.
	 * @return string One of the STATUS_* constants.
	 */
	public static function classify( float $net_price, float $cost, float $threshold, int $decimals = 2 ): string {
		$profit = round( $net_price - $cost, $decimals );
		if ( $profit < 0 ) {
			return self::STATUS_BELOW_COST;
		}

		$margin = self::percent( $net_price, $cost );
		if ( null === $margin || round( $margin, 2 ) < $threshold ) {
			return self::STATUS_LOW_MARGIN;
		}

		return self::STATUS_OK;
	}

	/**
	 * Apply a percentage discount to a price.
	 *
	 * @param float $price   Price before discount.
	 * @param float $percent Discount in percent, clamped to 0..100.
	 * @return float
	 */
	public static function discounted( float $price, float $percent ): float {
		$percent = max( 0.0, min( 100.0, $percent ) );
		return $price * ( 1 - $percent / 100 );
	}
}
