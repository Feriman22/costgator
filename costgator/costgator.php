<?php
/**
 * Plugin Name:          CostGator Margin Audit for WooCommerce
 * Description:          Find WooCommerce products, sales and coupons that sell below cost or below your margin. Test a discount before you launch it.
 * Version:              1.0.0
 * Requires at least:    6.5
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               Feriman
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          costgator
 * WC requires at least: 8.0
 * WC tested up to:      11.2
 *
 * @package CostGator
 */

defined( 'ABSPATH' ) || exit;

define( 'COSTGATOR_VERSION', '1.0.0' );
define( 'COSTGATOR_FILE', __FILE__ );
define( 'COSTGATOR_DIR', plugin_dir_path( __FILE__ ) );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		require_once COSTGATOR_DIR . 'includes/class-margin.php';
		require_once COSTGATOR_DIR . 'includes/class-cost-provider.php';
		require_once COSTGATOR_DIR . 'includes/class-auditor.php';
		require_once COSTGATOR_DIR . 'includes/class-scanner.php';

		if ( is_admin() ) {
			require_once COSTGATOR_DIR . 'includes/class-admin.php';
			\CostGator\Admin::init();
		}
	}
);
