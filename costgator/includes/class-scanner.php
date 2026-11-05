<?php
/**
 * Batched store scan and result storage.
 *
 * @package CostGator
 */

namespace CostGator;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the audit in small batches so large catalogs do not hit request time limits.
 *
 * Each batch stores its items and findings in its own non-autoloaded option ("chunk"), so a batch
 * write stays small no matter how large the catalog is, and no single row nears the database packet limit.
 */
final class Scanner {

	const OPTION_RUNNING  = 'costgator_scan_running';
	const OPTION_RESULT   = 'costgator_last_scan';
	const OPTION_IDS      = 'costgator_scan_ids';
	const CHUNK_PREFIX    = 'costgator_chunk_';
	const BATCH_SIZE      = 25;
	const BATCH_ITEMS     = 200;
	const MAX_COUPON_HITS = 5;

	/**
	 * Start a new scan, discarding any unfinished one.
	 *
	 * @param float $threshold Minimum margin in percent.
	 * @return array|\WP_Error Public scan status.
	 */
	public static function start( float $threshold ) {
		$coupons = Auditor::find_coupons();
		$ids     = self::product_ids();

		$scan = array(
			'scan_id'             => wp_generate_uuid4(),
			'started'             => time(),
			'finished'            => null,
			'threshold'           => $threshold,
			'total_products'      => count( $ids ),
			'processed_products'  => 0,
			'child_offset'        => 0,
			'coupon_ids'          => $coupons['ids'],
			'unsupported_coupons' => $coupons['unsupported'],
			'native_cogs'         => Cost_Provider::native_enabled(),
			'prices_include_tax'  => wc_tax_enabled() && wc_prices_include_tax(),
			'currency'            => get_woocommerce_currency(),
			'decimals'            => wc_get_price_decimals(),
			'skipped_no_price'    => 0,
			'chunks'              => 0,
			'coupons'             => array(),
		);
		// The ID snapshot never changes during a scan, so it is written once instead of with every batch.
		// It is deleted first, because update_option() cannot tell a failed write from an unchanged value.
		delete_option( self::OPTION_IDS );
		if ( ! add_option( self::OPTION_IDS, $ids, '', false ) || ! self::save_running( $scan ) ) {
			// Never leave an older running scan next to the new ID list.
			delete_option( self::OPTION_RUNNING );
			delete_option( self::OPTION_IDS );
			return new \WP_Error( 'costgator_write_failed', __( 'Could not save scan results.', 'costgator' ) );
		}
		self::delete_orphan_chunks();

		return self::status( $scan );
	}

	/**
	 * Process the next batch of a running scan.
	 *
	 * @param string $scan_id Scan the client is driving.
	 * @return array|\WP_Error Public scan status.
	 */
	public static function step( string $scan_id ) {
		$scan = get_option( self::OPTION_RUNNING );
		if ( ! is_array( $scan ) || ! isset( $scan['scan_id'] ) || $scan['scan_id'] !== $scan_id ) {
			return new \WP_Error( 'costgator_stale_scan', __( 'This scan was replaced by a newer one.', 'costgator' ) );
		}

		// A missing or shortened ID list would skip products and still end in a result that looks complete.
		$ids = get_option( self::OPTION_IDS );
		if ( ! is_array( $ids ) || ! isset( $scan['total_products'], $scan['processed_products'] ) || count( $ids ) !== (int) $scan['total_products'] ) {
			delete_option( self::OPTION_RUNNING );
			delete_option( self::OPTION_IDS );
			return new \WP_Error( 'costgator_scan_data', __( 'The scan data is incomplete. Please start a new scan.', 'costgator' ) );
		}

		$auditor        = new Auditor( (float) $scan['threshold'], $scan['coupon_ids'], self::decimals( $scan ) );
		$items          = 0;
		$batch_products = 0;
		$chunk          = array(
			'items'    => array(),
			'findings' => array(),
		);

		// The ID list is fixed at start, so catalog changes during the scan cannot shift later batches.
		// A batch ends after BATCH_SIZE products or BATCH_ITEMS variations, whichever comes first.
		// A variable product with more variations than that continues in the next batch from child_offset.
		while ( $scan['processed_products'] < $scan['total_products'] ) {
			if ( $batch_products >= self::BATCH_SIZE || $items >= self::BATCH_ITEMS ) {
				break;
			}
			++$batch_products;
			$product = isset( $ids[ $scan['processed_products'] ] ) ? wc_get_product( $ids[ $scan['processed_products'] ] ) : null;
			// Products deleted or unpublished since the scan started are skipped.
			if ( ! $product || 'publish' !== $product->get_status() ) {
				++$scan['processed_products'];
				$scan['child_offset'] = 0;
				continue;
			}
			$slice = Auditor::sellable_items( $product, (int) $scan['child_offset'], self::BATCH_ITEMS - $items );
			if ( null === $slice['next'] ) {
				++$scan['processed_products'];
				$scan['child_offset'] = 0;
			} else {
				$scan['child_offset'] = $slice['next'];
				$items                = self::BATCH_ITEMS;
			}
			foreach ( $slice['items'] as $sellable ) {
				++$items;
				$result = $auditor->audit( $sellable );
				if ( null === $result['item'] ) {
					++$scan['skipped_no_price'];
					continue;
				}
				$chunk['items'][] = $result['item'];
				foreach ( $result['findings'] as $finding ) {
					$chunk['findings'][] = $finding;
				}
				foreach ( $result['coupon_hits'] as $hit ) {
					self::add_coupon_hit( $scan, $hit );
				}
			}
		}

		// Another scan may have been started while this batch ran. Do not overwrite it.
		wp_cache_delete( self::OPTION_RUNNING, 'options' );
		$current = get_option( self::OPTION_RUNNING );
		if ( ! is_array( $current ) || $current['scan_id'] !== $scan_id ) {
			return new \WP_Error( 'costgator_stale_scan', __( 'This scan was replaced by a newer one.', 'costgator' ) );
		}

		if ( $chunk['items'] ) {
			// Chunk names are new for every batch, so a false return means the write failed.
			if ( ! update_option( self::chunk_name( $scan, $scan['chunks'] ), $chunk, false ) ) {
				return new \WP_Error( 'costgator_write_failed', __( 'Could not save scan results.', 'costgator' ) );
			}
			++$scan['chunks'];
		}

		if ( $scan['processed_products'] >= $scan['total_products'] ) {
			$scan['finished'] = time();
			if ( ! update_option( self::OPTION_RESULT, $scan, false ) ) {
				return new \WP_Error( 'costgator_write_failed', __( 'Could not save scan results.', 'costgator' ) );
			}
			delete_option( self::OPTION_RUNNING );
			delete_option( self::OPTION_IDS );
			self::delete_orphan_chunks();
		} elseif ( ! self::save_running( $scan ) ) {
			return new \WP_Error( 'costgator_write_failed', __( 'Could not save scan results.', 'costgator' ) );
		}

		return self::status( $scan );
	}

	/**
	 * The last completed scan.
	 *
	 * @return array|null
	 */
	public static function last_result(): ?array {
		// A scan finishing in another request can replace the result while it is read. Retry once on a missing chunk.
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			wp_cache_delete( self::OPTION_RESULT, 'options' );
			$scan = get_option( self::OPTION_RESULT );
			// A damaged result reads as no result, so the screen still loads and a new scan can replace it.
			if ( ! self::valid_result( $scan ) ) {
				return null;
			}

			$scan['items']    = array();
			$scan['findings'] = array();
			for ( $i = 0; $i < $scan['chunks']; $i++ ) {
				$chunk = get_option( self::chunk_name( $scan, $i ) );
				if ( false === $chunk ) {
					continue 2;
				}
				if ( ! is_array( $chunk ) || ! isset( $chunk['items'], $chunk['findings'] ) || ! is_array( $chunk['items'] ) || ! is_array( $chunk['findings'] ) ) {
					return null;
				}
				foreach ( $chunk['items'] as $row ) {
					if ( ! self::valid_item( $row ) ) {
						return null;
					}
					$scan['items'][] = $row;
				}
				foreach ( $chunk['findings'] as $row ) {
					if ( ! self::valid_finding( $row ) ) {
						return null;
					}
					$scan['findings'][] = $row;
				}
			}
			return $scan;
		}
		return null;
	}

	/**
	 * Whether a stored result header has the fields and types the results screen relies on.
	 *
	 * @param mixed $scan Stored result.
	 * @return bool
	 */
	private static function valid_result( $scan ): bool {
		if ( ! is_array( $scan ) || ! self::has_keys( $scan, array( 'scan_id', 'chunks', 'finished', 'threshold', 'currency', 'coupons', 'unsupported_coupons', 'prices_include_tax', 'skipped_no_price' ) ) ) {
			return false;
		}
		if ( ! is_string( $scan['scan_id'] ) || '' === $scan['scan_id'] || ! is_string( $scan['currency'] ) || '' === $scan['currency'] || ! is_bool( $scan['prices_include_tax'] ) ) {
			return false;
		}
		if ( ! is_int( $scan['chunks'] ) || $scan['chunks'] < 0 || ! is_int( $scan['finished'] ) || ! is_numeric( $scan['threshold'] ) || ! self::count_value( $scan['unsupported_coupons'] ) || ! self::count_value( $scan['skipped_no_price'] ) ) {
			return false;
		}
		if ( array_key_exists( 'decimals', $scan ) && ! self::count_value( $scan['decimals'] ) ) {
			return false;
		}
		if ( ! is_array( $scan['coupons'] ) ) {
			return false;
		}
		foreach ( $scan['coupons'] as $coupon ) {
			if ( ! is_array( $coupon ) || ! self::has_keys( $coupon, array( 'code', 'percent', 'below_cost', 'low_margin', 'examples' ) ) ) {
				return false;
			}
			if ( ! is_scalar( $coupon['code'] ) || ! is_numeric( $coupon['percent'] ) || ! self::count_value( $coupon['below_cost'] ) || ! self::count_value( $coupon['low_margin'] ) || ! is_array( $coupon['examples'] ) ) {
				return false;
			}
			foreach ( $coupon['examples'] as $example ) {
				if ( ! is_array( $example ) || ! isset( $example['name'] ) || ! is_scalar( $example['name'] ) ) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * Whether a stored item row has every field the Auditor writes, with the expected types.
	 *
	 * @param mixed $row Item row.
	 * @return bool
	 */
	private static function valid_item( $row ): bool {
		return is_array( $row )
			&& self::has_keys( $row, array( 'id', 'parent_id', 'name', 'sku', 'net_current', 'net_regular', 'on_sale', 'cost', 'cost_source' ) )
			&& is_numeric( $row['id'] ) && is_numeric( $row['parent_id'] ) && is_scalar( $row['name'] ) && is_scalar( $row['sku'] )
			&& is_numeric( $row['net_current'] ) && self::number_or_null( $row['net_regular'] ) && self::number_or_null( $row['cost'] )
			&& is_scalar( $row['on_sale'] ) && ( null === $row['cost_source'] || is_string( $row['cost_source'] ) );
	}

	/**
	 * Whether a stored finding row has every field the Auditor writes for its type, with the expected types.
	 * An unknown type is rejected, because no category would show it and a loss could disappear.
	 *
	 * @param mixed $row Finding row.
	 * @return bool
	 */
	private static function valid_finding( $row ): bool {
		if ( ! is_array( $row ) || ! self::has_keys( $row, array( 'item_id', 'parent_id', 'name', 'sku', 'cost', 'source', 'type' ) ) ) {
			return false;
		}
		if ( ! is_numeric( $row['item_id'] ) || ! is_numeric( $row['parent_id'] ) || ! is_scalar( $row['name'] ) || ! is_scalar( $row['sku'] )
			|| ! self::number_or_null( $row['cost'] ) || ! ( null === $row['source'] || is_string( $row['source'] ) ) ) {
			return false;
		}
		// A switch compares loosely, so a non-string type such as true would match the first case.
		if ( ! is_string( $row['type'] ) ) {
			return false;
		}
		// The tables show a sale start date for any finding that has one.
		if ( array_key_exists( 'from', $row ) && ! is_int( $row['from'] ) ) {
			return false;
		}
		switch ( $row['type'] ) {
			case 'missing_cost':
				return true;
			case Margin::STATUS_BELOW_COST:
			case Margin::STATUS_LOW_MARGIN:
				return self::has_keys( $row, array( 'price', 'margin', 'on_sale' ) ) && is_numeric( $row['cost'] ) && is_numeric( $row['price'] ) && self::number_or_null( $row['margin'] ) && is_scalar( $row['on_sale'] );
			case 'future_' . Margin::STATUS_BELOW_COST:
			case 'future_' . Margin::STATUS_LOW_MARGIN:
				return self::has_keys( $row, array( 'price', 'margin', 'from' ) ) && is_numeric( $row['cost'] ) && is_numeric( $row['price'] ) && self::number_or_null( $row['margin'] ) && is_int( $row['from'] );
		}
		return false;
	}

	/**
	 * Whether every key exists, null values included.
	 *
	 * @param array    $row  Row.
	 * @param string[] $keys Keys.
	 * @return bool
	 */
	private static function has_keys( array $row, array $keys ): bool {
		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $row ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether a value is null or numeric.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function number_or_null( $value ): bool {
		return null === $value || is_numeric( $value );
	}

	/**
	 * Whether a value is a whole number of zero or more.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private static function count_value( $value ): bool {
		return is_numeric( $value ) && (float) $value >= 0 && floor( (float) $value ) === (float) $value;
	}

	/**
	 * Price decimals a scan was made with. Scans before 1.0.4 did not store them.
	 *
	 * @param array $scan Scan state.
	 * @return int
	 */
	public static function decimals( array $scan ): int {
		return isset( $scan['decimals'] ) ? (int) $scan['decimals'] : wc_get_price_decimals();
	}

	/**
	 * Option name of a result chunk.
	 *
	 * @param array $scan  Scan state.
	 * @param int   $index Chunk index.
	 * @return string
	 */
	private static function chunk_name( array $scan, int $index ): string {
		return self::chunk_prefix( $scan ) . $index;
	}

	/**
	 * Option name prefix shared by all chunks of a scan.
	 *
	 * @param array $scan Scan state.
	 * @return string
	 */
	private static function chunk_prefix( array $scan ): string {
		return self::CHUNK_PREFIX . substr( md5( $scan['scan_id'] ), 0, 12 ) . '_';
	}

	/**
	 * Delete chunks that belong neither to the published result nor to the running scan.
	 * This also catches chunks left behind by abandoned or crashed scans.
	 */
	public static function delete_orphan_chunks() {
		global $wpdb;

		$keep = array();
		foreach ( array( self::OPTION_RESULT, self::OPTION_RUNNING ) as $option ) {
			wp_cache_delete( $option, 'options' );
			$scan = get_option( $option );
			if ( is_array( $scan ) && isset( $scan['scan_id'] ) && is_string( $scan['scan_id'] ) ) {
				$keep[] = self::chunk_prefix( $scan );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- options have no API to list names by prefix.
		$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( self::CHUNK_PREFIX ) . '%' ) );
		foreach ( $names as $name ) {
			foreach ( $keep as $prefix ) {
				if ( 0 === strpos( $name, $prefix ) ) {
					continue 2;
				}
			}
			delete_option( $name );
		}
	}

	/**
	 * Simulate a store-wide percentage discount on the items of the last scan.
	 *
	 * @param array  $scan      Completed scan.
	 * @param float  $percent   Discount in percent.
	 * @param string $basis     'current' or 'regular' price.
	 * @param float  $threshold Minimum margin in percent.
	 * @return array{below_cost: array[], low_margin: array[], checked: int, no_cost: int}
	 */
	public static function preflight( array $scan, float $percent, string $basis, float $threshold ): array {
		$out      = array(
			'below_cost' => array(),
			'low_margin' => array(),
			'checked'    => 0,
			'no_cost'    => 0,
		);
		$decimals = self::decimals( $scan );

		foreach ( $scan['items'] as $item ) {
			if ( null === $item['cost'] || $item['cost'] <= 0 ) {
				++$out['no_cost'];
				continue;
			}
			$base = 'regular' === $basis && null !== $item['net_regular'] ? $item['net_regular'] : $item['net_current'];
			$new  = Margin::discounted( $base, $percent );
			++$out['checked'];

			$status = Margin::classify( $new, $item['cost'], $threshold, $decimals );
			if ( Margin::STATUS_OK !== $status ) {
				// The calculated values come first, so stored keys of the same name cannot replace them.
				$out[ $status ][] = array(
					'new_price'  => $new,
					'new_margin' => Margin::percent( $new, $item['cost'] ),
				) + $item;
			}
		}

		return $out;
	}

	/**
	 * IDs of published catalog products, excluding variations, external and grouped products.
	 *
	 * @return int[]
	 */
	private static function product_ids(): array {
		/**
		 * Filters the product types that are audited. Variable products are expanded into their variations.
		 *
		 * @param string[] $types Product types.
		 */
		$types = (array) apply_filters( 'costgator_product_types', array( 'simple', 'variable' ) );

		$ids = wc_get_products(
			array(
				'status'  => 'publish',
				'type'    => $types,
				'limit'   => -1,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'return'  => 'ids',
			)
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * Aggregate a coupon hit, keeping a bounded list of example products per coupon.
	 *
	 * @param array $scan Scan state, modified in place.
	 * @param array $hit  Coupon hit from the auditor.
	 */
	private static function add_coupon_hit( array &$scan, array $hit ) {
		$id = $hit['coupon_id'];
		if ( ! isset( $scan['coupons'][ $id ] ) ) {
			$scan['coupons'][ $id ] = array(
				'code'       => $hit['code'],
				'percent'    => $hit['percent'],
				'below_cost' => 0,
				'low_margin' => 0,
				'examples'   => array(),
			);
		}
		++$scan['coupons'][ $id ][ $hit['status'] ];
		if ( count( $scan['coupons'][ $id ]['examples'] ) < self::MAX_COUPON_HITS ) {
			$scan['coupons'][ $id ]['examples'][] = array( 'name' => $hit['name'] );
		}
	}

	/**
	 * Persist the running scan without autoloading it.
	 *
	 * @param array $scan Scan state.
	 * @return bool False when the write failed.
	 */
	private static function save_running( array $scan ): bool {
		return update_option( self::OPTION_RUNNING, $scan, false );
	}

	/**
	 * Status payload for the browser.
	 *
	 * @param array $scan Scan state.
	 * @return array
	 */
	private static function status( array $scan ): array {
		return array(
			'scan_id'   => $scan['scan_id'],
			'processed' => $scan['processed_products'],
			'total'     => $scan['total_products'],
			'done'      => null !== $scan['finished'],
		);
	}
}
