<?php
/**
 * Admin screen template.
 *
 * @package CostGator
 *
 * @var array|null $scan      Last completed scan.
 * @var float      $threshold Current margin threshold.
 */

use CostGator\Admin;
use CostGator\Cost_Provider;
use CostGator\Scanner;

defined( 'ABSPATH' ) || exit;


// Read-only display parameters, so no nonce is needed.
// phpcs:disable WordPress.Security.NonceVerification.Recommended
$costgator_preflight_percent = isset( $_GET['preflight'] ) ? max( 0.0, min( 100.0, (float) wc_format_decimal( sanitize_text_field( wp_unslash( $_GET['preflight'] ) ) ) ) ) : null;
$costgator_preflight_basis   = isset( $_GET['basis'] ) && 'regular' === sanitize_key( wp_unslash( $_GET['basis'] ) ) ? 'regular' : 'current';
// phpcs:enable

$costgator_features_url = admin_url( 'admin.php?page=wc-settings&tab=advanced&section=features' );
?>
<div class="wrap costgator-wrap">
	<h1><?php esc_html_e( 'CostGator', 'costgator' ); ?></h1>
	<p class="costgator-subtitle"><?php esc_html_e( 'Finds prices, sales and coupons that can make you sell below cost.', 'costgator' ); ?></p>

	<?php if ( isset( $_GET['settings-updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved. Run a new scan to apply them.', 'costgator' ); ?></p></div>
	<?php endif; ?>

	<?php if ( ! Cost_Provider::native_enabled() ) : ?>
		<div class="notice notice-info">
			<p>
				<?php
				printf(
					/* translators: %s: link to the WooCommerce features settings */
					esc_html__( 'WooCommerce Cost of Goods Sold is not enabled. Enable it under %s and enter product costs, or use a supported cost plugin.', 'costgator' ),
					'<a href="' . esc_url( $costgator_features_url ) . '">' . esc_html__( 'WooCommerce > Settings > Advanced > Features', 'costgator' ) . '</a>'
				);
				?>
			</p>
		</div>
	<?php endif; ?>

	<div class="costgator-scanbar">
		<button type="button" class="button button-primary button-hero" id="costgator-scan"><?php esc_html_e( 'Scan My Store', 'costgator' ); ?></button>
		<div class="costgator-progress" id="costgator-progress" hidden>
			<div class="costgator-progress-track"><div class="costgator-progress-fill" id="costgator-progress-fill"></div></div>
			<p class="costgator-progress-text" id="costgator-progress-text" aria-live="polite"></p>
		</div>
		<p class="costgator-readonly">
			<span class="dashicons dashicons-lock" aria-hidden="true"></span>
			<?php esc_html_e( 'Read-only audit. No prices, products, orders or coupons are modified.', 'costgator' ); ?>
		</p>
	</div>

	<?php if ( null === $scan ) : ?>
		<div class="costgator-empty">
			<p><?php esc_html_e( 'No scan yet. Click Scan My Store to check your catalog.', 'costgator' ); ?></p>
		</div>
	<?php else : ?>
		<?php
		$costgator_below      = Admin::findings( $scan, array( 'below_cost' ) );
		$costgator_low        = Admin::findings( $scan, array( 'low_margin' ) );
		$costgator_future     = Admin::findings( $scan, array( 'future_below_cost', 'future_low_margin' ) );
		$costgator_missing    = Admin::findings( $scan, array( 'missing_cost' ) );
		$costgator_coupons    = $scan['coupons'];
		$costgator_at_risk    = count( array_unique( wp_list_pluck( array_merge( $costgator_below, $costgator_low, $costgator_future ), 'item_id' ) ) );
		$costgator_cards      = array(
			array( __( 'Products checked', 'costgator' ), count( $scan['items'] ), '' ),
			array( __( 'Below cost', 'costgator' ), count( $costgator_below ), count( $costgator_below ) ? 'is-critical' : '' ),
			array( __( 'Products at risk', 'costgator' ), $costgator_at_risk, $costgator_at_risk ? 'is-warning' : '' ),
			array( __( 'Missing cost', 'costgator' ), count( $costgator_missing ), count( $costgator_missing ) ? 'is-warning' : '' ),
			array( __( 'Risky coupons', 'costgator' ), count( $costgator_coupons ), count( $costgator_coupons ) ? 'is-warning' : '' ),
		);
		?>
		<?php $costgator_review_count = Admin::review_request_count( $scan ); ?>
		<?php if ( $costgator_review_count ) : ?>
			<div class="costgator-review" id="costgator-review">
				<p>
					<?php
					printf(
						/* translators: %s: number of products with a known cost */
						esc_html( _n( 'CostGator checked %s product with a known cost. If it helped you, a short review on WordPress.org helps other store owners find it.', 'CostGator checked %s products with a known cost. If it helped you, a short review on WordPress.org helps other store owners find it.', $costgator_review_count, 'costgator' ) ),
						esc_html( number_format_i18n( $costgator_review_count ) )
					);
					?>
				</p>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( Admin::review_action_url( 'review' ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Leave a review', 'costgator' ); ?></a>
					<a class="button" href="<?php echo esc_url( Admin::review_action_url( 'dismiss' ) ); ?>"><?php esc_html_e( "Don't ask again", 'costgator' ); ?></a>
				</p>
			</div>
		<?php endif; ?>

		<p class="costgator-meta">
			<?php
			printf(
				/* translators: 1: date and time of the scan, 2: margin threshold */
				esc_html__( 'Last scan: %1$s. Low margin threshold: %2$s.', 'costgator' ),
				esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $scan['finished'] ) ),
				esc_html( Admin::format_margin( $scan['threshold'] ) )
			);
			?>
		</p>

		<div class="costgator-cards">
			<?php foreach ( $costgator_cards as $costgator_card ) : ?>
				<div class="costgator-card <?php echo esc_attr( $costgator_card[2] ); ?>">
					<span class="costgator-card-label"><?php echo esc_html( $costgator_card[0] ); ?></span>
					<span class="costgator-card-value"><?php echo esc_html( number_format_i18n( $costgator_card[1] ) ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>

		<p class="costgator-assumptions">
			<?php
			if ( $scan['prices_include_tax'] ) {
				esc_html_e( 'Prices include tax in this store. They are converted to net using your store base tax rates before comparison.', 'costgator' );
			} else {
				esc_html_e( 'Prices are entered without tax in this store and are compared as entered.', 'costgator' );
			}
			echo ' ';
			printf(
				/* translators: %s: currency code */
				esc_html__( 'Costs are assumed to be net of tax, in %s. Only product price and cost are compared. Shipping, payment fees, marketing and other costs are not included.', 'costgator' ),
				esc_html( $scan['currency'] )
			);
			?>
		</p>

		<?php
		$costgator_sections = array(
			array(
				'title' => __( 'Selling below cost', 'costgator' ),
				'desc'  => __( 'The current price is lower than the cost. Every sale loses money on the product itself.', 'costgator' ),
				'rows'  => $costgator_below,
				'class' => 'is-critical',
			),
			array(
				'title' => __( 'Scheduled sales at risk', 'costgator' ),
				'desc'  => __( 'A sale that has not started yet will push the price below cost or below your margin threshold.', 'costgator' ),
				'rows'  => $costgator_future,
				'class' => 'is-warning',
			),
			array(
				'title' => __( 'Low margin', 'costgator' ),
				'desc'  => __( 'The current price is above cost, but the margin is below your threshold. Review before running more promotions.', 'costgator' ),
				'rows'  => $costgator_low,
				'class' => 'is-warning',
			),
		);
		foreach ( $costgator_sections as $costgator_section ) :
			if ( empty( $costgator_section['rows'] ) ) {
				continue;
			}
			?>
			<div class="costgator-section <?php echo esc_attr( $costgator_section['class'] ); ?>">
				<h2><?php echo esc_html( $costgator_section['title'] ); ?> <span class="costgator-count"><?php echo esc_html( number_format_i18n( count( $costgator_section['rows'] ) ) ); ?></span></h2>
				<p class="description"><?php echo esc_html( $costgator_section['desc'] ); ?></p>
				<table class="widefat striped costgator-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Product', 'costgator' ); ?></th>
							<th class="num"><?php esc_html_e( 'Net price', 'costgator' ); ?></th>
							<th class="num"><?php esc_html_e( 'Cost', 'costgator' ); ?></th>
							<th class="num"><?php esc_html_e( 'Margin', 'costgator' ); ?></th>
							<th><?php esc_html_e( 'Details', 'costgator' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( array_slice( $costgator_section['rows'], 0, Admin::ROW_LIMIT ) as $costgator_row ) : ?>
							<tr>
								<td><?php echo Admin::product_link( $costgator_row ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in product_link(). ?></td>
								<td class="num"><?php echo wp_kses_post( wc_price( $costgator_row['price'] ) ); ?></td>
								<td class="num"><?php echo wp_kses_post( wc_price( $costgator_row['cost'] ) ); ?></td>
								<td class="num"><?php echo esc_html( Admin::format_margin( $costgator_row['margin'] ) ); ?></td>
								<td>
									<?php
									$costgator_details = array();
									if ( 'below_cost' === $costgator_row['type'] ) {
										/* translators: %s: loss amount per unit */
										$costgator_details[] = sprintf( __( 'Loss: %s per unit', 'costgator' ), wp_strip_all_tags( wc_price( $costgator_row['cost'] - $costgator_row['price'] ) ) );
									}
									if ( ! empty( $costgator_row['on_sale'] ) ) {
										$costgator_details[] = __( 'On sale', 'costgator' );
									}
									if ( ! empty( $costgator_row['from'] ) ) {
										/* translators: %s: sale start date */
										$costgator_details[] = sprintf( __( 'Sale starts %s', 'costgator' ), wp_date( get_option( 'date_format' ), $costgator_row['from'] ) );
										$costgator_details[] = 'future_below_cost' === $costgator_row['type'] ? __( 'Below cost', 'costgator' ) : __( 'Low margin', 'costgator' );
									}
									/* translators: %s: name of the cost data source */
									$costgator_details[] = sprintf( __( 'Cost from %s', 'costgator' ), Admin::source_label( $costgator_row['source'] ) );
									echo esc_html( implode( ' · ', $costgator_details ) );
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( count( $costgator_section['rows'] ) > Admin::ROW_LIMIT ) : ?>
					<p class="description">
						<?php
						/* translators: %s: number of rows not shown */
						printf( esc_html__( 'Not shown: %s more.', 'costgator' ), esc_html( number_format_i18n( count( $costgator_section['rows'] ) - Admin::ROW_LIMIT ) ) );
						?>
					</p>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>

		<?php if ( $costgator_coupons ) : ?>
			<div class="costgator-section is-warning">
				<h2><?php esc_html_e( 'Coupons that could cause losses', 'costgator' ); ?> <span class="costgator-count"><?php echo esc_html( number_format_i18n( count( $costgator_coupons ) ) ); ?></span></h2>
				<p class="description"><?php esc_html_e( 'Potential risk: applied to the current price of eligible products, this coupon would leave them below cost or below your threshold. Spend limits, customer restrictions and coupon combinations are not evaluated.', 'costgator' ); ?></p>
				<table class="widefat striped costgator-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Coupon', 'costgator' ); ?></th>
							<th class="num"><?php esc_html_e( 'Discount', 'costgator' ); ?></th>
							<th class="num"><?php esc_html_e( 'Below cost', 'costgator' ); ?></th>
							<th class="num"><?php esc_html_e( 'Low margin', 'costgator' ); ?></th>
							<th><?php esc_html_e( 'Examples', 'costgator' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $costgator_coupons as $costgator_coupon_id => $costgator_coupon ) : ?>
							<?php $costgator_coupon_url = get_edit_post_link( $costgator_coupon_id ); ?>
							<tr>
								<td><code><?php echo $costgator_coupon_url ? '<a href="' . esc_url( $costgator_coupon_url ) . '">' . esc_html( $costgator_coupon['code'] ) . '</a>' : esc_html( $costgator_coupon['code'] ); ?></code></td>
								<td class="num"><?php echo esc_html( number_format_i18n( $costgator_coupon['percent'], 0 ) . '%' ); ?></td>
								<td class="num"><?php echo esc_html( number_format_i18n( $costgator_coupon['below_cost'] ) ); ?></td>
								<td class="num"><?php echo esc_html( number_format_i18n( $costgator_coupon['low_margin'] ) ); ?></td>
								<td><?php echo esc_html( implode( ', ', array_slice( wp_list_pluck( $costgator_coupon['examples'], 'name' ), 0, 5 ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>

		<?php if ( $costgator_missing ) : ?>
			<div class="costgator-section">
				<h2><?php esc_html_e( 'Missing cost information', 'costgator' ); ?> <span class="costgator-count"><?php echo esc_html( number_format_i18n( count( $costgator_missing ) ) ); ?></span></h2>
				<p class="description"><?php esc_html_e( 'These products have no usable cost, so their profitability cannot be checked. WooCommerce stores a zero cost as empty, so products with a cost of 0 also appear here.', 'costgator' ); ?></p>
				<ul class="costgator-missing">
					<?php foreach ( array_slice( $costgator_missing, 0, Admin::ROW_LIMIT ) as $costgator_row ) : ?>
						<li><?php echo Admin::product_link( $costgator_row ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in product_link(). ?></li>
					<?php endforeach; ?>
				</ul>
				<?php if ( count( $costgator_missing ) > Admin::ROW_LIMIT ) : ?>
					<p class="description">
						<?php
						/* translators: %s: number of rows not shown */
						printf( esc_html__( 'Not shown: %s more.', 'costgator' ), esc_html( number_format_i18n( count( $costgator_missing ) - Admin::ROW_LIMIT ) ) );
						?>
					</p>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ( ! $costgator_below && ! $costgator_low && ! $costgator_future && ! $costgator_coupons && ! $costgator_missing ) : ?>
			<?php if ( $scan['items'] ) : ?>
				<div class="costgator-section is-ok">
					<h2><?php esc_html_e( 'No issues found', 'costgator' ); ?></h2>
					<p><?php esc_html_e( 'Every checked product has a cost and sells above your margin threshold.', 'costgator' ); ?></p>
				</div>
			<?php else : ?>
				<div class="costgator-section">
					<h2><?php esc_html_e( 'No products could be checked', 'costgator' ); ?></h2>
					<p><?php esc_html_e( 'The scan found no published products with a price.', 'costgator' ); ?></p>
				</div>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( ! empty( $scan['skipped_no_price'] ) ) : ?>
			<p class="description">
				<?php
				/* translators: %s: number of products and variations without a price */
				printf( esc_html__( '%s products or variations without a price were skipped.', 'costgator' ), esc_html( number_format_i18n( $scan['skipped_no_price'] ) ) );
				?>
			</p>
		<?php endif; ?>

		<?php if ( $scan['unsupported_coupons'] ) : ?>
			<p class="description">
				<?php
				/* translators: %s: number of coupons */
				printf( esc_html__( 'Fixed amount coupons not analyzed: %s. This version checks percentage coupons only.', 'costgator' ), esc_html( number_format_i18n( $scan['unsupported_coupons'] ) ) );
				?>
			</p>
		<?php endif; ?>

		<div class="costgator-section costgator-preflight" id="costgator-preflight">
			<h2><?php esc_html_e( 'Promotion Preflight', 'costgator' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Planning a store-wide sale? See which products would drop below cost or below your threshold before you launch it. Uses the data of the last scan.', 'costgator' ); ?></p>
			<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>#costgator-preflight" class="costgator-inline-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( Admin::PAGE_SLUG ); ?>">
				<label>
					<?php esc_html_e( 'Discount', 'costgator' ); ?>
					<input type="number" name="preflight" min="1" max="100" step="0.1" value="<?php echo esc_attr( null !== $costgator_preflight_percent ? $costgator_preflight_percent : 20 ); ?>" class="small-text"> %
				</label>
				<label>
					<?php esc_html_e( 'applied to', 'costgator' ); ?>
					<select name="basis">
						<option value="current" <?php selected( $costgator_preflight_basis, 'current' ); ?>><?php esc_html_e( 'current price (on top of running sales)', 'costgator' ); ?></option>
						<option value="regular" <?php selected( $costgator_preflight_basis, 'regular' ); ?>><?php esc_html_e( 'regular price (replacing running sales)', 'costgator' ); ?></option>
					</select>
				</label>
				<button type="submit" class="button"><?php esc_html_e( 'Check promotion', 'costgator' ); ?></button>
			</form>

			<?php
			if ( null !== $costgator_preflight_percent ) :
				$costgator_pf = Scanner::preflight( $scan, $costgator_preflight_percent, $costgator_preflight_basis, $threshold );
				?>
				<p class="costgator-preflight-summary">
					<?php
					printf(
						/* translators: 1: discount percent, 2: products below cost, 3: products with low margin, 4: products checked */
						esc_html__( 'With %1$s off: %2$s below cost, %3$s below threshold, out of %4$s products with a known cost.', 'costgator' ),
						esc_html( number_format_i18n( $costgator_preflight_percent, 1 ) . '%' ),
						'<strong class="costgator-critical-text">' . esc_html( number_format_i18n( count( $costgator_pf['below_cost'] ) ) ) . '</strong>',
						'<strong>' . esc_html( number_format_i18n( count( $costgator_pf['low_margin'] ) ) ) . '</strong>',
						esc_html( number_format_i18n( $costgator_pf['checked'] ) )
					);
					if ( $costgator_pf['no_cost'] ) {
						echo ' ';
						/* translators: %s: number of products */
						printf( esc_html__( 'Skipped without cost: %s.', 'costgator' ), esc_html( number_format_i18n( $costgator_pf['no_cost'] ) ) );
					}
					?>
				</p>
				<?php $costgator_pf_rows = array_merge( $costgator_pf['below_cost'], $costgator_pf['low_margin'] ); ?>
				<?php if ( $costgator_pf_rows ) : ?>
					<table class="widefat striped costgator-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Product', 'costgator' ); ?></th>
								<th class="num"><?php esc_html_e( 'Promo net price', 'costgator' ); ?></th>
								<th class="num"><?php esc_html_e( 'Cost', 'costgator' ); ?></th>
								<th class="num"><?php esc_html_e( 'Margin', 'costgator' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( array_slice( $costgator_pf_rows, 0, Admin::ROW_LIMIT ) as $costgator_row ) : ?>
								<?php $costgator_row['item_id'] = $costgator_row['id']; ?>
								<tr class="<?php echo $costgator_row['new_price'] < $costgator_row['cost'] ? 'costgator-row-critical' : ''; ?>">
									<td><?php echo Admin::product_link( $costgator_row ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in product_link(). ?></td>
									<td class="num"><?php echo wp_kses_post( wc_price( $costgator_row['new_price'] ) ); ?></td>
									<td class="num"><?php echo wp_kses_post( wc_price( $costgator_row['cost'] ) ); ?></td>
									<td class="num"><?php echo esc_html( Admin::format_margin( $costgator_row['new_margin'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<?php if ( count( $costgator_pf_rows ) > Admin::ROW_LIMIT ) : ?>
						<p class="description">
							<?php
							/* translators: %s: number of rows not shown */
							printf( esc_html__( 'Not shown: %s more.', 'costgator' ), esc_html( number_format_i18n( count( $costgator_pf_rows ) - Admin::ROW_LIMIT ) ) );
							?>
						</p>
					<?php endif; ?>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<?php if ( Admin::PRO_WAITLIST_URL ) : ?>
			<div class="costgator-pro">
				<span class="costgator-pro-badge"><?php esc_html_e( 'Coming soon', 'costgator' ); ?></span>
				<h2><?php esc_html_e( 'CostGator Pro', 'costgator' ); ?></h2>
				<ul>
					<li><?php esc_html_e( 'Automated scheduled audits with email alerts', 'costgator' ); ?></li>
					<li><?php esc_html_e( 'Coupon combination analysis', 'costgator' ); ?></li>
					<li><?php esc_html_e( 'Per-category margin rules and CSV export', 'costgator' ); ?></li>
				</ul>
				<a class="button" href="<?php echo esc_url( Admin::PRO_WAITLIST_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Get notified when Pro launches', 'costgator' ); ?></a>
			</div>
		<?php endif; ?>
	<?php endif; ?>

	<div class="costgator-section costgator-settings">
		<h2><?php esc_html_e( 'Settings', 'costgator' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="costgator-inline-form">
			<input type="hidden" name="action" value="costgator_settings">
			<?php wp_nonce_field( Admin::NONCE_SETTINGS ); ?>
			<label>
				<?php esc_html_e( 'Low margin threshold', 'costgator' ); ?>
				<input type="number" name="threshold" min="0" max="99" step="0.1" value="<?php echo esc_attr( $threshold ); ?>" class="small-text"> %
			</label>
			<button type="submit" class="button"><?php esc_html_e( 'Save', 'costgator' ); ?></button>
		</form>
	</div>
</div>
