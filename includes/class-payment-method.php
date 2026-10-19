<?php
/**
 * Payment method of the orders that Ecomiq creates.
 *
 * @package Ecomiq\WooCommerce
 */

namespace Ecomiq\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Payment method of the orders that Ecomiq creates. It never shows at checkout and charges nothing.
 */
final class Payment_Method extends \WC_Payment_Gateway {

	/**
	 * Sets up the payment method.
	 */
	public function __construct() {
		$this->id                 = Orders::METHOD_ID;
		$this->method_title       = __( 'Ecomiq', 'ecomiq-for-woocommerce' );
		$this->method_description = __( 'Marks the orders that Ecomiq creates for sales on other channels. It never shows at checkout and charges nothing.', 'ecomiq-for-woocommerce' );
		$this->title              = __( 'Sold on another channel', 'ecomiq-for-woocommerce' );
		$this->enabled            = 'yes';
		$this->has_fields         = false;
	}

	/**
	 * Keeps the method out of checkout.
	 *
	 * @return bool
	 */
	public function is_available() {
		return false;
	}
}
