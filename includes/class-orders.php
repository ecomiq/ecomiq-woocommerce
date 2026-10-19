<?php
/**
 * Orders that Ecomiq creates for sales on other channels.
 *
 * @package Ecomiq\WooCommerce
 */

namespace Ecomiq\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Orders that Ecomiq creates for sales on other channels.
 */
final class Orders {

	const METHOD_ID        = 'ecomiq';
	const CHANNEL_META     = '_ecomiq_channel';
	const NUMBER_META      = '_ecomiq_order_number';
	const ATTRIBUTION_META = '_wc_order_attribution_';

	const CUSTOMER_EMAILS = array(
		'customer_on_hold_order',
		'customer_processing_order',
		'customer_completed_order',
		'customer_refunded_order',
		'customer_partially_refunded_order',
		'customer_invoice',
		'customer_note',
		'customer_cancelled_order',
		'customer_failed_order',
	);

	/**
	 * Registers the Ecomiq payment and shipping methods and the order hooks.
	 */
	public static function register() {
		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'payment_gateways' ) );
		add_filter( 'woocommerce_shipping_methods', array( __CLASS__, 'shipping_methods' ) );
		foreach ( self::CUSTOMER_EMAILS as $email ) {
			add_filter( 'woocommerce_email_enabled_' . $email, array( __CLASS__, 'customer_email' ), 10, 2 );
		}
		add_action( 'woocommerce_rest_insert_shop_order_object', array( __CLASS__, 'created' ), 10, 3 );
	}

	/**
	 * Adds the Ecomiq payment method.
	 *
	 * @param array $gateways Payment gateway classes.
	 *
	 * @return array
	 */
	public static function payment_gateways( $gateways ) {
		require_once __DIR__ . '/class-payment-method.php';
		$gateways[] = Payment_Method::class;
		return $gateways;
	}

	/**
	 * Adds the Ecomiq shipping method.
	 *
	 * @param array $methods Shipping method classes by ID.
	 *
	 * @return array
	 */
	public static function shipping_methods( $methods ) {
		require_once __DIR__ . '/class-shipping-method.php';
		$methods[ self::METHOD_ID ] = Shipping_Method::class;
		return $methods;
	}

	/**
	 * Whether Ecomiq created the order for a sale on another channel.
	 *
	 * @param mixed $order Order.
	 *
	 * @return bool
	 */
	public static function is_ecomiq( $order ) {
		return $order instanceof \WC_Order && self::METHOD_ID === $order->get_payment_method();
	}

	/**
	 * Turns off customer emails for Ecomiq orders. The channel where the sale happened already wrote to the buyer.
	 *
	 * @param bool  $enabled Whether the email is enabled.
	 * @param mixed $order   Order the email is about.
	 *
	 * @return bool
	 */
	public static function customer_email( $enabled, $order ) {
		if ( self::is_ecomiq( $order ) ) {
			return false;
		}
		return $enabled;
	}

	/**
	 * Marks an order that Ecomiq just created and notes where it was sold.
	 *
	 * @param \WC_Order        $order    Order.
	 * @param \WP_REST_Request $request  Request.
	 * @param bool             $creating Whether the order was created.
	 */
	public static function created( $order, $request, $creating ) {
		if ( ! $creating || ! self::is_ecomiq( $order ) ) {
			return;
		}
		$channel = (string) $order->get_meta( self::CHANNEL_META );
		$order->set_created_via( self::METHOD_ID );
		if ( '' !== $channel ) {
			$order->update_meta_data( self::ATTRIBUTION_META . 'source_type', 'utm' );
			$order->update_meta_data( self::ATTRIBUTION_META . 'utm_source', $channel );
		}
		$order->save();
		$order->add_order_note( self::note( $channel, (string) $order->get_meta( self::NUMBER_META ) ) );
	}

	/**
	 * Returns the note that says where the order was sold.
	 *
	 * @param string $channel Channel where the sale happened.
	 * @param string $number  Order number in that channel.
	 *
	 * @return string
	 */
	private static function note( $channel, $number ) {
		if ( '' === $channel ) {
			return __( 'Ecomiq created this order for a sale on another channel.', 'ecomiq-for-woocommerce' );
		}
		if ( '' === $number ) {
			/* translators: %s: channel name */
			return sprintf( __( 'Sold on %s. Ecomiq created this order.', 'ecomiq-for-woocommerce' ), $channel );
		}
		/* translators: 1: channel name, 2: order number in that channel */
		return sprintf( __( 'Sold on %1$s, order %2$s. Ecomiq created this order.', 'ecomiq-for-woocommerce' ), $channel, $number );
	}
}
