<?php
/**
 * Ecomiq seal on the order lines.
 *
 * @package Ecomiq\WooCommerce
 */

namespace Ecomiq\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Copies the seal that Ecomiq writes on the products it publishes to the order lines that sell them.
 */
final class Seal {

	const META = '_ecomiq';

	/**
	 * Hooks the new order lines and the order screen.
	 */
	public static function register() {
		add_action( 'woocommerce_before_order_item_object_save', array( __CLASS__, 'stamp' ) );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hidden_meta' ) );
	}

	/**
	 * Copies the seal of the product, or of its parent for a variation, to a new order line.
	 *
	 * @param \WC_Order_Item $item Order item about to be saved.
	 */
	public static function stamp( $item ) {
		if ( ! $item instanceof \WC_Order_Item_Product || $item->get_id() || '' !== $item->get_meta( self::META ) ) {
			return;
		}
		$product = wc_get_product( $item->get_product_id() );
		if ( ! $product ) {
			return;
		}
		$seal = $product->get_meta( self::META );
		if ( '' === $seal ) {
			return;
		}
		$item->add_meta_data( self::META, $seal, true );
	}

	/**
	 * Hides the seal on the order screen.
	 *
	 * @param array $keys Hidden meta keys.
	 *
	 * @return array
	 */
	public static function hidden_meta( $keys ) {
		$keys[] = self::META;
		return $keys;
	}
}
