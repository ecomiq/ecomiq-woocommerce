<?php
/**
 * WooCommerce order fulfillments.
 *
 * @package Ecomiq\WooCommerce
 */

namespace Ecomiq\WooCommerce;

use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce order fulfillments, where Ecomiq records what each seller shipped.
 */
final class Fulfillments {

	const FEATURE              = 'fulfillments';
	const OPTION               = 'woocommerce_feature_fulfillments_enabled';
	const ISSUE                = 'https://github.com/woocommerce/woocommerce/issues/69430';
	const SHIPMENT_META        = '_ecomiq_shipment';
	const DATA_STORE           = 'Automattic\WooCommerce\Admin\Features\Fulfillments\DataStore\FulfillmentsDataStore';
	const CONTAINER_DATA_STORE = 'Automattic\WooCommerce\Internal\DataStores\Fulfillments\FulfillmentsDataStore';

	/**
	 * Hooks the completed order email.
	 */
	public static function register() {
		add_filter( 'woocommerce_email_enabled_customer_completed_order', array( __CLASS__, 'completed_email' ), 10, 2 );
	}

	/**
	 * Turns off the completed order email when Ecomiq already told the buyer about its shipments.
	 *
	 * @param bool  $enabled Whether the email is enabled.
	 * @param mixed $order   Order the email is about.
	 *
	 * @return bool
	 */
	public static function completed_email( $enabled, $order ) {
		if ( ! $enabled || ! $order instanceof \WC_Order ) {
			return $enabled;
		}
		return ! self::shipped_by_ecomiq( $order );
	}

	/**
	 * Whether the order has a fulfillment that Ecomiq created. The store's own fulfillments do not count.
	 *
	 * @param \WC_Order $order Order.
	 *
	 * @return bool
	 */
	public static function shipped_by_ecomiq( \WC_Order $order ) {
		if ( ! self::enabled() ) {
			return false;
		}
		try {
			$fulfillments = self::data_store()->read_fulfillments( \WC_Order::class, (string) $order->get_id() );
		} catch ( \Throwable $error ) {
			return false;
		}
		foreach ( (array) $fulfillments as $fulfillment ) {
			if ( '' !== $fulfillment->get_meta( self::SHIPMENT_META ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns the WooCommerce fulfillments data store. Up to WooCommerce 10.6 it lives in the container.
	 *
	 * @return object
	 */
	private static function data_store() {
		if ( class_exists( self::DATA_STORE ) ) {
			return \WC_Data_Store::load( 'order-fulfillment' );
		}
		return wc_get_container()->get( self::CONTAINER_DATA_STORE );
	}

	/**
	 * Whether the store has fulfillments turned on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		return class_exists( FeaturesUtil::class ) && FeaturesUtil::feature_is_enabled( self::FEATURE );
	}

	/**
	 * Whether this WooCommerce version has fulfillments.
	 *
	 * @return bool
	 */
	public static function available() {
		return class_exists( FeaturesUtil::class ) && array_key_exists( self::FEATURE, FeaturesUtil::get_features( true ) );
	}

	/**
	 * Turns fulfillments on, as WooCommerce documents it while they are in beta.
	 */
	public static function enable() {
		update_option( self::OPTION, 'yes' );
	}

	/**
	 * Whether the store uses WooCommerce Shipping, whose old labels stop showing with fulfillments on.
	 *
	 * @return bool
	 */
	public static function uses_woocommerce_shipping() {
		return defined( 'WCSHIPPING_VERSION' );
	}
}
