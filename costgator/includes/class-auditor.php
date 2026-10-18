<?php
/**
 * Turns WooCommerce products and coupons into audit items and findings.
 *
 * @package CostGator
 */

namespace CostGator;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only product and coupon checks.
 */
final class Auditor {

	/**
	 * Minimum margin threshold in percent.
	 *
	 * @var float
	 */
	private $threshold;

	/**
	 * Store price decimals.
	 *
	 * @var int
	 */
	private $decimals;

	/**
	 * Percentage coupons to check, loaded once per batch.
	 *
	 * @var \WC_Coupon[]
	 */
	private $coupons;

	/**
	 * Constructor.
	 *
	 * @param float $threshold  Minimum margin in percent.
	 * @param int[] $coupon_ids Percentage coupon IDs to evaluate.
	 */
	public function __construct( float $threshold, array $coupon_ids ) {
		$this->threshold = $threshold;
		$this->decimals  = wc_get_price_decimals();
		$this->coupons   = array();
		foreach ( $coupon_ids as $coupon_id ) {
			$coupon = new \WC_Coupon( $coupon_id );
			if ( $coupon->get_id() ) {
				$this->coupons[] = $coupon;
			}
		}
	}

	/**
	 * Find coupons that can be analyzed (active percentage coupons) and count those that cannot.
	 *
	 * @return array{ids: int[], unsupported: int}
	 */
	public static function find_coupons(): array {
		$ids         = get_posts(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		$supported   = array();
		$unsupported = 0;
		$now         = time();

		foreach ( $ids as $id ) {
			$coupon  = new \WC_Coupon( $id );
			$expires = $coupon->get_date_expires();
			if ( $expires && $expires->getTimestamp() < $now ) {
				continue;
			}
			if ( $coupon->get_usage_limit() > 0 && $coupon->get_usage_count() >= $coupon->get_usage_limit() ) {
				continue;
			}
			if ( $coupon->is_type( 'percent' ) && (float) $coupon->get_amount() <= 0 ) {
				continue;
			}
			if ( $coupon->is_type( 'percent' ) ) {
				$supported[] = (int) $id;
			} else {
				++$unsupported;
			}
		}

		return array(
			'ids'         => $supported,
			'unsupported' => $unsupported,
		);
	}

	/**
	 * Sellable products behind a catalog entry: the product itself, or the enabled variations of a variable product.
	 *
	 * Variations are returned in slices so a product with thousands of variations is spread over several batches.
	 *
	 * @param \WC_Product $product Catalog product.
	 * @param int         $offset  Index of the first variation to return.
	 * @param int         $limit   Maximum number of variations to look at.
	 * @return array{items: \WC_Product[], next: ?int} Next is the offset to continue from, null when done.
	 */
	public static function sellable_items( \WC_Product $product, int $offset = 0, int $limit = PHP_INT_MAX ): array {
		if ( ! $product->is_type( 'variable' ) ) {
			return array(
				'items' => array( $product ),
				'next'  => null,
			);
		}
		$children = $product->get_children();
		$items    = array();
		foreach ( array_slice( $children, $offset, max( 1, $limit ) ) as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( $variation && 'publish' === $variation->get_status() ) {
				$items[] = $variation;
			}
		}
		$next = $offset + max( 1, $limit );
		return array(
			'items' => $items,
			'next'  => $next < count( $children ) ? $next : null,
		);
	}

	/**
	 * Convert a price to its net value using the store base tax rates.
	 *
	 * The customer location and VAT exemption of the current session are deliberately ignored,
	 * so the result does not depend on who runs the scan.
	 *
	 * @param \WC_Product $product Product the price belongs to.
	 * @param float       $price   Price as entered in the store.
	 * @return float
	 */
	public static function net_price( \WC_Product $product, float $price ): float {
		if ( ! wc_tax_enabled() || ! wc_prices_include_tax() || 'taxable' !== $product->get_tax_status() ) {
			return $price;
		}
		$rates = \WC_Tax::get_base_tax_rates( $product->get_tax_class() );
		$taxes = \WC_Tax::calc_tax( $price, $rates, true );
		return $price - array_sum( $taxes );
	}

	/**
	 * Audit one sellable product.
	 *
	 * @param \WC_Product $product Simple product or variation.
	 * @return array{item: ?array, findings: array[], coupon_hits: array[]} Item is null for products without a price.
	 */
	public function audit( \WC_Product $product ): array {
		$result = array(
			'item'        => null,
			'findings'    => array(),
			'coupon_hits' => array(),
		);

		// An empty price means the product cannot be bought. A zero price with a cost is a real loss and is kept.
		$price = $product->get_price( 'edit' );
		if ( '' === $price || ! is_numeric( $price ) || (float) $price < 0 ) {
			return $result;
		}

		$cost_info = Cost_Provider::get( $product );
		$cost      = $cost_info['cost'];
		$regular   = $product->get_regular_price( 'edit' );
		$on_sale   = $product->is_on_sale( 'edit' );

		$item = array(
			'id'          => $product->get_id(),
			'parent_id'   => $product->get_parent_id(),
			'name'        => wp_strip_all_tags( $product->get_name() ),
			'sku'         => $product->get_sku(),
			'net_current' => self::net_price( $product, (float) $price ),
			'net_regular' => is_numeric( $regular ) ? self::net_price( $product, (float) $regular ) : null,
			'on_sale'     => $on_sale,
			'cost'        => $cost,
			'cost_source' => $cost_info['source'],
		);

		$result['item'] = $item;
		$base           = array(
			'item_id'   => $item['id'],
			'parent_id' => $item['parent_id'],
			'name'      => $item['name'],
			'sku'       => $item['sku'],
			'cost'      => $cost,
			'source'    => $cost_info['source'],
		);

		if ( null === $cost || $cost <= 0 ) {
			$result['findings'][] = $base + array(
				'type' => 'missing_cost',
				'zero' => null !== $cost,
			);
			return $result;
		}

		$status = Margin::classify( $item['net_current'], $cost, $this->threshold, $this->decimals );
		if ( Margin::STATUS_OK !== $status ) {
			$result['findings'][] = $base + array(
				'type'    => $status,
				'price'   => $item['net_current'],
				'margin'  => Margin::percent( $item['net_current'], $cost ),
				'on_sale' => $on_sale,
			);
		}

		$future = $this->future_sale( $product );
		if ( null !== $future ) {
			$net_sale      = self::net_price( $product, $future['price'] );
			$future_status = Margin::classify( $net_sale, $cost, $this->threshold, $this->decimals );
			if ( Margin::STATUS_OK !== $future_status ) {
				$result['findings'][] = $base + array(
					'type'   => 'future_' . $future_status,
					'price'  => $net_sale,
					'margin' => Margin::percent( $net_sale, $cost ),
					'from'   => $future['from'],
				);
			}
		}

		foreach ( $this->coupons as $coupon ) {
			if ( ! $coupon->is_valid_for_product( $product ) ) {
				continue;
			}
			$discounted    = Margin::discounted( $item['net_current'], (float) $coupon->get_amount() );
			$coupon_status = Margin::classify( $discounted, $cost, $this->threshold, $this->decimals );
			if ( Margin::STATUS_OK !== $coupon_status ) {
				$result['coupon_hits'][] = array(
					'coupon_id' => $coupon->get_id(),
					'code'      => $coupon->get_code(),
					'percent'   => (float) $coupon->get_amount(),
					'status'    => $coupon_status,
					'item_id'   => $item['id'],
					'name'      => $item['name'],
					'price'     => $discounted,
					'margin'    => Margin::percent( $discounted, $cost ),
				);
			}
		}

		return $result;
	}

	/**
	 * A scheduled sale that has not started yet.
	 *
	 * @param \WC_Product $product Product or variation.
	 * @return array{price: float, from: int}|null
	 */
	private function future_sale( \WC_Product $product ): ?array {
		$sale_price = $product->get_sale_price( 'edit' );
		$from       = $product->get_date_on_sale_from( 'edit' );
		$to         = $product->get_date_on_sale_to( 'edit' );
		if ( '' === $sale_price || ! is_numeric( $sale_price ) || ! $from || $from->getTimestamp() <= time() ) {
			return null;
		}
		// A sale that ends before it starts never runs.
		if ( $to && $to->getTimestamp() <= $from->getTimestamp() ) {
			return null;
		}
		return array(
			'price' => (float) $sale_price,
			'from'  => $from->getTimestamp(),
		);
	}
}
