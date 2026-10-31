<?php
/**
 * Parts of an order that Ecomiq split.
 *
 * @package Ecomiq\WooCommerce
 */

namespace Ecomiq\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Shows on the order screen the parts Ecomiq wrote: one per seller, plus the store's own products.
 */
final class Order_Parts {

	const META    = '_ecomiq_parts';
	const BOX     = 'ecomiq-parts';
	const SELLER  = 'seller';
	const OUTSIDE = 'outside';

	/**
	 * Hooks the order screen.
	 */
	public static function register() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ), 40, 2 );
		add_action( 'woocommerce_after_order_itemmeta', array( __CLASS__, 'line' ), 10, 2 );
	}

	/**
	 * Adds the Ecomiq box to an order that has parts.
	 *
	 * @param string $screen  Screen ID.
	 * @param mixed  $subject Post or order being edited.
	 */
	public static function meta_box( $screen, $subject ) {
		$order = self::order( $subject );
		if ( null === $order || empty( self::parts( $order ) ) ) {
			return;
		}
		wp_enqueue_style( 'ecomiq-admin', plugins_url( 'assets/admin.css', FILE ), array(), VERSION );
		add_meta_box( self::BOX, 'Ecomiq', array( __CLASS__, 'render' ), $screen, 'side', 'high' );
	}

	/**
	 * Renders one row per part with its products, status and shipments.
	 *
	 * @param mixed $subject Post or order being edited.
	 */
	public static function render( $subject ) {
		$order = self::order( $subject );
		if ( null === $order ) {
			return;
		}
		echo '<ul class="ecomiq-parts">';
		foreach ( self::parts( $order ) as $part ) {
			$count = array_sum( $part['lines'] );
			$texts = array(
				sprintf(
					/* translators: %d: number of products */
					_n( '%d product', '%d products', $count, 'ecomiq-for-woocommerce' ),
					$count
				),
			);
			if ( '' !== $part['status'] ) {
				$texts[] = $part['status'];
			}
			echo '<li><span class="ecomiq-part-name">' . esc_html( $part['name'] ) . '</span>';
			echo '<span class="ecomiq-part-detail">' . esc_html( implode( ' · ', $texts ) );
			foreach ( $part['shipments'] as $shipment ) {
				echo ' · ';
				if ( '' === $shipment['url'] ) {
					echo esc_html( $shipment['text'] );
					continue;
				}
				echo '<a href="' . esc_url( $shipment['url'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $shipment['text'] ) . '</a>';
			}
			echo '</span></li>';
		}
		echo '</ul>';
	}

	/**
	 * Shows under an order line who ships it.
	 *
	 * @param int            $item_id Order item ID.
	 * @param \WC_Order_Item $item    Order item.
	 */
	public static function line( $item_id, $item ) {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return;
		}
		$order = $item->get_order();
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		foreach ( self::parts( $order ) as $part ) {
			if ( isset( $part['lines'][ (int) $item_id ] ) ) {
				echo '<div class="ecomiq-line-part">' . esc_html( $part['name'] ) . '</div>';
				return;
			}
		}
	}

	/**
	 * Returns the order being edited, or null for other screens.
	 *
	 * @param mixed $subject Post or order being edited.
	 *
	 * @return \WC_Order|null
	 */
	private static function order( $subject ) {
		if ( $subject instanceof \WP_Post ) {
			if ( 'shop_order' !== $subject->post_type ) {
				return null;
			}
			$subject = wc_get_order( $subject->ID );
		}
		if ( ! $subject instanceof \WC_Order ) {
			return null;
		}
		return $subject;
	}

	/**
	 * Returns the parts Ecomiq wrote on the order, with their names and labels.
	 *
	 * @param \WC_Order $order Order.
	 *
	 * @return array[] Parts with name, lines (quantity by order item ID), status and shipments.
	 */
	private static function parts( \WC_Order $order ) {
		$value = $order->get_meta( self::META );
		if ( is_string( $value ) ) {
			$value = json_decode( $value, true );
		}
		if ( ! is_array( $value ) || ! isset( $value['v'], $value['parts'] ) || 1 !== (int) $value['v'] || ! is_array( $value['parts'] ) ) {
			return array();
		}
		$parts = array();
		foreach ( $value['parts'] as $part ) {
			if ( ! is_array( $part ) || ! isset( $part['kind'] ) ) {
				continue;
			}
			$name = '';
			if ( self::OUTSIDE === $part['kind'] ) {
				$name = __( 'Your products', 'ecomiq-for-woocommerce' );
			} elseif ( self::SELLER === $part['kind'] && isset( $part['name'] ) && is_string( $part['name'] ) ) {
				$name = trim( $part['name'] );
			}
			if ( '' === $name ) {
				continue;
			}
			$status = '';
			if ( isset( $part['status'] ) && is_string( $part['status'] ) ) {
				$status = self::status( (string) $part['kind'], $part['status'] );
			}
			$parts[] = array(
				'name'      => $name,
				'lines'     => self::lines( isset( $part['lines'] ) ? $part['lines'] : array() ),
				'status'    => $status,
				'shipments' => self::shipments( isset( $part['shipments'] ) ? $part['shipments'] : array() ),
			);
		}
		return $parts;
	}

	/**
	 * Returns the quantity of each order line of a part, by order item ID.
	 *
	 * @param mixed $lines Lines with id and qty.
	 *
	 * @return int[]
	 */
	private static function lines( $lines ) {
		$quantities = array();
		foreach ( (array) $lines as $line ) {
			if ( ! is_array( $line ) || ! isset( $line['id'], $line['qty'] ) || (int) $line['id'] <= 0 || (int) $line['qty'] <= 0 ) {
				continue;
			}
			$quantities[ (int) $line['id'] ] = (int) $line['qty'];
		}
		return $quantities;
	}

	/**
	 * Returns the shipments of a part as text and tracking address.
	 *
	 * @param mixed $shipments Shipments with number, url and carrier.
	 *
	 * @return array[]
	 */
	private static function shipments( $shipments ) {
		$list = array();
		foreach ( (array) $shipments as $shipment ) {
			if ( ! is_array( $shipment ) ) {
				continue;
			}
			$parts = array();
			foreach ( array( 'carrier', 'number' ) as $key ) {
				if ( isset( $shipment[ $key ] ) && is_scalar( $shipment[ $key ] ) && '' !== trim( (string) $shipment[ $key ] ) ) {
					$parts[] = trim( (string) $shipment[ $key ] );
				}
			}
			if ( empty( $parts ) ) {
				continue;
			}
			$url = '';
			if ( isset( $shipment['url'] ) && is_string( $shipment['url'] ) ) {
				$url = esc_url_raw( $shipment['url'], array( 'http', 'https' ) );
			}
			$list[] = array(
				'text' => implode( ' ', $parts ),
				'url'  => $url,
			);
		}
		return $list;
	}

	/**
	 * Returns the label of the status of a part, or an empty string for an unknown status.
	 *
	 * @param string $kind   seller or outside.
	 * @param string $status Status Ecomiq wrote.
	 *
	 * @return string
	 */
	private static function status( $kind, $status ) {
		$labels = array(
			'pending'   => __( 'To prepare', 'ecomiq-for-woocommerce' ),
			'preparing' => __( 'Preparing', 'ecomiq-for-woocommerce' ),
			'shipped'   => __( 'Shipped', 'ecomiq-for-woocommerce' ),
			'delivered' => __( 'Delivered', 'ecomiq-for-woocommerce' ),
			'cancelled' => __( 'Cancelled', 'ecomiq-for-woocommerce' ),
		);
		if ( self::OUTSIDE === $kind ) {
			$labels = array(
				'pending'   => __( 'Pending', 'ecomiq-for-woocommerce' ),
				'shipped'   => __( 'Shipped', 'ecomiq-for-woocommerce' ),
				'cancelled' => __( 'Cancelled', 'ecomiq-for-woocommerce' ),
			);
		}
		if ( ! isset( $labels[ $status ] ) ) {
			return '';
		}
		return $labels[ $status ];
	}
}
