<?php
/**
 * Shipping method of the orders that Ecomiq creates.
 *
 * @package Ecomiq\WooCommerce
 */

namespace Ecomiq\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Shipping method of the orders that Ecomiq creates. It never shows at checkout and is not part of any zone.
 */
final class Shipping_Method extends \WC_Shipping_Method {

	/**
	 * Sets up the shipping method.
	 *
	 * @param int $instance_id Instance ID.
	 */
	public function __construct( $instance_id = 0 ) {
		parent::__construct( $instance_id );
		$this->id                 = Orders::METHOD_ID;
		$this->method_title       = __( 'Ecomiq', 'ecomiq-for-woocommerce' );
		$this->method_description = __( 'Shipping of the orders that Ecomiq creates for sales on other channels. It never shows at checkout.', 'ecomiq-for-woocommerce' );
		$this->title              = __( 'Shipped by another channel', 'ecomiq-for-woocommerce' );
		$this->enabled            = 'yes';
		$this->supports           = array();
	}

	/**
	 * Keeps the method out of checkout.
	 *
	 * @param array $package Package.
	 *
	 * @return bool
	 */
	public function is_available( $package ) {
		return false;
	}

	/**
	 * Adds no rates: the channel where the sale happened handles shipping.
	 *
	 * @param array $package Package.
	 */
	public function calculate_shipping( $package = array() ) {
	}
}
