<?php
/**
 * Admin page, AJAX endpoints and settings.
 *
 * @package CostGator
 */

namespace CostGator;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce > CostGator screen.
 */
final class Admin {

	const PAGE_SLUG       = 'costgator';
	const CAPABILITY      = 'manage_woocommerce';
	const OPTION_SETTINGS = 'costgator_settings';
	const NONCE_SCAN      = 'costgator_scan';
	const NONCE_SETTINGS  = 'costgator_settings';
	const ROW_LIMIT       = 100;

	/**
	 * Landing page of the upcoming Pro version. The waitlist box stays hidden while this is empty.
	 */
	const PRO_WAITLIST_URL = '';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_costgator_start', array( __CLASS__, 'ajax_start' ) );
		add_action( 'wp_ajax_costgator_step', array( __CLASS__, 'ajax_step' ) );
		add_action( 'admin_post_costgator_settings', array( __CLASS__, 'save_settings' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( COSTGATOR_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Minimum margin threshold in percent.
	 *
	 * @return float
	 */
	public static function threshold(): float {
		$settings = get_option( self::OPTION_SETTINGS, array() );
		return isset( $settings['threshold'] ) ? (float) $settings['threshold'] : 10.0;
	}

	/**
	 * Add the submenu under WooCommerce.
	 */
	public static function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'CostGator', 'costgator' ),
			__( 'CostGator', 'costgator' ),
			self::CAPABILITY,
			self::PAGE_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param string[] $links Existing links.
	 * @return string[]
	 */
	public static function action_links( array $links ): array {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( self::page_url() ), esc_html__( 'Scan store', 'costgator' ) )
		);
		return $links;
	}

	/**
	 * Assets, only on our own screen.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public static function enqueue( $hook_suffix ) {
		if ( 'woocommerce_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}
		wp_enqueue_style( 'costgator-admin', plugins_url( 'assets/admin.css', COSTGATOR_FILE ), array(), COSTGATOR_VERSION );
		wp_enqueue_script( 'costgator-admin', plugins_url( 'assets/admin.js', COSTGATOR_FILE ), array(), COSTGATOR_VERSION, true );
		wp_localize_script(
			'costgator-admin',
			'costGator',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_SCAN ),
				'i18n'    => array(
					/* translators: 1: processed products, 2: total products */
					'progress' => __( 'Scanning %1$s of %2$s products...', 'costgator' ),
					'starting' => __( 'Starting scan...', 'costgator' ),
					'done'     => __( 'Scan complete. Loading results...', 'costgator' ),
					'failed'   => __( 'The scan failed. Please try again.', 'costgator' ),
				),
			)
		);
	}

	/**
	 * AJAX: start a scan.
	 */
	public static function ajax_start() {
		self::verify_ajax();
		wp_send_json_success( Scanner::start( self::threshold() ) );
	}

	/**
	 * AJAX: process the next batch.
	 */
	public static function ajax_step() {
		self::verify_ajax();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in verify_ajax().
		$scan_id = isset( $_POST['scan_id'] ) ? sanitize_text_field( wp_unslash( $_POST['scan_id'] ) ) : '';
		$status  = Scanner::step( $scan_id );
		if ( is_wp_error( $status ) ) {
			wp_send_json_error( array( 'message' => $status->get_error_message() ), 409 );
		}
		wp_send_json_success( $status );
	}

	/**
	 * Capability and nonce check for AJAX endpoints.
	 */
	private static function verify_ajax() {
		check_ajax_referer( self::NONCE_SCAN, 'nonce' );
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'costgator' ) ), 403 );
		}
	}

	/**
	 * Save the threshold setting.
	 */
	public static function save_settings() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'costgator' ), 403 );
		}
		check_admin_referer( self::NONCE_SETTINGS );

		$threshold = isset( $_POST['threshold'] ) ? (float) wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['threshold'] ) ) ) : 10.0;
		$threshold = max( 0.0, min( 99.0, $threshold ) );
		update_option( self::OPTION_SETTINGS, array( 'threshold' => $threshold ), false );

		wp_safe_redirect( add_query_arg( 'settings-updated', '1', self::page_url() ) );
		exit;
	}

	/**
	 * URL of the plugin screen.
	 *
	 * @param array $args Extra query args.
	 * @return string
	 */
	private static function page_url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::PAGE_SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * Render the screen.
	 */
	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$scan      = Scanner::last_result();
		$threshold = self::threshold();
		include COSTGATOR_DIR . 'views/admin-page.php';
	}

	/**
	 * Edit link of a product or the parent of a variation.
	 *
	 * @param array $row Finding or item with item_id and parent_id.
	 * @return string Escaped HTML.
	 */
	public static function product_link( array $row ): string {
		$target = ! empty( $row['parent_id'] ) ? $row['parent_id'] : $row['item_id'];
		$name   = esc_html( $row['name'] );
		$url    = get_edit_post_link( $target );
		$html   = $url ? sprintf( '<a href="%s">%s</a>', esc_url( $url ), $name ) : $name;
		if ( ! empty( $row['sku'] ) ) {
			$html .= ' <span class="costgator-sku">' . esc_html( $row['sku'] ) . '</span>';
		}
		return $html;
	}

	/**
	 * Human readable cost source.
	 *
	 * @param string|null $source Source id or label.
	 * @return string
	 */
	public static function source_label( $source ): string {
		if ( Cost_Provider::SOURCE_NATIVE === $source ) {
			return __( 'WooCommerce COGS', 'costgator' );
		}
		return (string) $source;
	}

	/**
	 * Format a margin percentage.
	 *
	 * @param float|null $margin Margin in percent.
	 * @return string
	 */
	public static function format_margin( $margin ): string {
		return null === $margin ? '-' : number_format_i18n( $margin, 1 ) . '%';
	}

	/**
	 * Findings of the given types.
	 *
	 * @param array    $scan  Completed scan.
	 * @param string[] $types Finding types.
	 * @return array[]
	 */
	public static function findings( array $scan, array $types ): array {
		return array_values(
			array_filter(
				$scan['findings'],
				static function ( $finding ) use ( $types ) {
					return in_array( $finding['type'], $types, true );
				}
			)
		);
	}
}
