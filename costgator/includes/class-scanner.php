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
	 * @return array Public scan status.
	 */
	public static function start( float $threshold ): array {
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
			'skipped_no_price'    => 0,
			'chunks'              => 0,
			'coupons'             => array(),
		);
		// The ID snapshot never changes during a scan, so it is written once instead of with every batch.
		update_option( self::OPTION_IDS, $ids, false );
		self::save_running( $scan );
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
		if ( ! is_array( $scan ) || $scan['scan_id'] !== $scan_id ) {
			return new \WP_Error( 'costgator_stale_scan', __( 'This scan was replaced by a newer one.', 'costgator' ) );
		}

		$ids            = get_option( self::OPTION_IDS );
		$auditor        = new Auditor( (float) $scan['threshold'], $scan['coupon_ids'] );
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
			if ( ! is_array( $scan ) || ! isset( $scan['chunks'] ) ) {
				return null;
			}

			$scan['items']    = array();
			$scan['findings'] = array();
			for ( $i = 0; $i < $scan['chunks']; $i++ ) {
				$chunk = get_option( self::chunk_name( $scan, $i ) );
				if ( ! is_array( $chunk ) ) {
					continue 2;
				}
				array_push( $scan['items'], ...$chunk['items'] );
				array_push( $scan['findings'], ...$chunk['findings'] );
			}
			return $scan;
		}
		return null;
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
			if ( is_array( $scan ) && isset( $scan['scan_id'] ) ) {
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
		$decimals = wc_get_price_decimals();

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
				$out[ $status ][] = $item + array(
					'new_price'  => $new,
					'new_margin' => Margin::percent( $new, $item['cost'] ),
				);
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
