<?php
/**
 * Plugin Name:          Ecomiq for WooCommerce
 * Plugin URI:           https://ecomiq.pe
 * Description:          Connect your store to Ecomiq in one click to sync products, stock, prices and orders.
 * Version:              1.0.0
 * Requires at least:    6.5
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * Author:               Ecomiq
 * Author URI:           https://ecomiq.pe
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          ecomiq-for-woocommerce
 * Domain Path:          /languages
 * WC requires at least: 9.0
 * WC tested up to:      11.2
 *
 * @package Ecomiq\WooCommerce
 */

namespace Ecomiq\WooCommerce;

defined( 'ABSPATH' ) || exit;

const VERSION = '1.0.0';
const FILE    = __FILE__;

require_once __DIR__ . '/includes/class-connection.php';
require_once __DIR__ . '/includes/class-activity.php';
require_once __DIR__ . '/includes/class-diagnostics.php';
require_once __DIR__ . '/includes/class-authorization.php';
require_once __DIR__ . '/includes/class-rest.php';
require_once __DIR__ . '/includes/class-admin.php';
require_once __DIR__ . '/includes/class-orders.php';
require_once __DIR__ . '/includes/class-shipping.php';

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', FILE, true );
		}
	}
);

add_action(
	'init',
	function () {
		load_plugin_textdomain( 'ecomiq-for-woocommerce', false, dirname( plugin_basename( FILE ) ) . '/languages' );
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		Rest::register();
		Connection::register();
		Activity::register();
		Admin::register();
		Orders::register();
		Shipping::register();
	}
);
