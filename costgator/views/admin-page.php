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

// Prints "Largest loss: <amount> per unit, <product>" for a row that sells below cost.
$costgator_largest_loss = static function ( array $row, string $price_key ) use ( $scan ) {
	$row['item_id'] = isset( $row['item_id'] ) ? $row['item_id'] : $row['id'];
	printf(
		/* translators: 1: loss amount per unit, 2: product name */
		esc_html__( 'Largest loss: %1$s per unit, %2$s', 'costgator' ),
		'<strong>' . wp_kses_post( Admin::money( $row['cost'] - $row[ $price_key ], $scan ) ) . '</strong>',
		Admin::product_link( $row ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in product_link().
	);
};
?>
<div class="wrap costgator-wrap">
	<h1><?php esc_html_e( 'CostGator', 'costgator' ); ?></h1>
	<p class="costgator-subtitle"><?php esc_html_e( 'Finds prices, sales and coupons that can make you sell below cost.', 'costgator' ); ?></p>

	<?php if ( isset( $_GET['settings-updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved. Run a new scan to apply them.', 'costgator' ); ?></p></div>
	<?php endif; ?>

	<?php if ( ! Cost_Provider::native_available() ) : ?>
		<div class="notice notice-info">
			<p><?php esc_html_e( 'This WooCommerce version has no built-in Cost of Goods Sold. Enter your product costs with a supported cost plugin, or update WooCommerce to use the built-in feature.', 'costgator' ); ?></p>
		</div>
	<?php elseif ( ! Cost_Provider::native_enabled() ) : ?>
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
		<button type="button" class="button <?php echo null === $scan ? 'button-primary' : ''; ?> button-hero" id="costgator-scan"><?php esc_html_e( 'Scan My Store', 'costgator' ); ?></button>
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
		$costgator_below   = Admin::worst_first( Admin::findings( $scan, array( 'below_cost' ) ) );
		$costgator_low     = Admin::worst_first( Admin::findings( $scan, array( 'low_margin' ) ) );
		$costgator_future  = Admin::worst_first( Admin::findings( $scan, array( 'future_below_cost', 'future_low_margin' ) ) );
		$costgator_missing = Admin::findings( $scan, array( 'missing_cost' ) );
		$costgator_coupons = $scan['coupons'];
		$costgator_known   = count( $scan['items'] ) - count( $costgator_missing );

		// Preflight uses the threshold of the scan, the same one the findings below use.
		$costgator_pf      = null;
		$costgator_pf_rows = array();
		if ( null !== $costgator_preflight_percent ) {
			$costgator_pf      = Scanner::preflight( $scan, $costgator_preflight_percent, $costgator_preflight_basis, (float) $scan['threshold'] );
			$costgator_pf_rows = array_merge(
				Admin::worst_first( $costgator_pf['below_cost'], 'new_price', 'new_margin' ),
				Admin::worst_first( $costgator_pf['low_margin'], 'new_price', 'new_margin' )
			);
		}
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
				// A time format like "g:i a" ends with a period in some languages, the sentence adds its own.
				esc_html( rtrim( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $scan['finished'] ), '.' ) ),
				esc_html( Admin::format_margin( $scan['threshold'] ) )
			);
			?>
		</p>

		<?php require __DIR__ . '/overview.php'; ?>
		<?php require __DIR__ . '/findings.php'; ?>
		<?php require __DIR__ . '/preflight-panel.php'; ?>
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

	<?php if ( null !== $scan && Admin::PRO_WAITLIST_URL ) : ?>
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
</div>
