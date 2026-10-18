<?php
/**
 * Reads unit costs from WooCommerce native COGS or common cost plugins.
 *
 * @package CostGator
 */

namespace CostGator;

use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the unit cost of a product and remembers where it came from.
 */
final class Cost_Provider {

	const SOURCE_NATIVE = 'native';

	/**
	 * Whether the WooCommerce Cost of Goods Sold feature is available and enabled.
	 *
	 * @return bool
	 */
	public static function native_enabled(): bool {
		return class_exists( FeaturesUtil::class )
			&& method_exists( 'WC_Product', 'get_cogs_total_value' )
			&& FeaturesUtil::feature_is_enabled( 'cost_of_goods_sold' );
	}

	/**
	 * Meta keys of third-party cost plugins, checked in order when no native cost exists.
	 *
	 * @return array<string,string> Meta key => source label.
	 */
	public static function meta_sources(): array {
		$sources = array(
			'_wc_cog_cost'     => 'Cost of Goods (SkyVerge)',
			'_alg_wc_cog_cost' => 'Cost of Goods (WPFactory)',
		);

		/**
		 * Filters the product meta keys that hold a unit cost.
		 *
		 * @param array<string,string> $sources Meta key => human readable source label.
		 */
		return (array) apply_filters( 'costgator_cost_meta_sources', $sources );
	}

	/**
	 * Get the unit cost of a product.
	 *
	 * Native COGS wins when a value is defined on the product or, for variations, on the parent.
	 * Native COGS stores a zero cost on simple products as "not set", so zero and missing look alike there.
	 *
	 * @param \WC_Product $product Product or variation.
	 * @return array{cost: ?float, source: ?string} Cost is null when unknown.
	 */
	public static function get( \WC_Product $product ): array {
		if ( self::native_enabled() && self::has_native_value( $product ) ) {
			return array(
				'cost'   => (float) $product->get_cogs_total_value(),
				'source' => self::SOURCE_NATIVE,
			);
		}

		$ids = array( $product->get_id() );
		if ( $product->is_type( 'variation' ) ) {
			$ids[] = $product->get_parent_id();
		}

		// The product's own cost wins over its parent's, whichever plugin stored it.
		foreach ( $ids as $id ) {
			foreach ( self::meta_sources() as $key => $label ) {
				$value = get_post_meta( $id, $key, true );
				if ( '' !== $value && is_numeric( $value ) ) {
					return array(
						'cost'   => (float) $value,
						'source' => $label,
					);
				}
			}
		}

		return array(
			'cost'   => null,
			'source' => null,
		);
	}

	/**
	 * Whether the product, or the parent of a variation, has a native cost defined.
	 *
	 * @param \WC_Product $product Product or variation.
	 * @return bool
	 */
	private static function has_native_value( \WC_Product $product ): bool {
		if ( null !== $product->get_cogs_value() ) {
			return true;
		}
		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			return $parent && null !== $parent->get_cogs_value();
		}
		return false;
	}
}
