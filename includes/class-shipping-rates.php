<?php
/**
 * Shipping method with the rates that Ecomiq quotes.
 *
 * @package Ecomiq\WooCommerce
 */

namespace Ecomiq\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Shipping method with the rates that Ecomiq quotes for each seller. Store owners add it to their zones.
 */
final class Shipping_Rates extends \WC_Shipping_Method {

	/**
	 * Sets up the shipping method.
	 *
	 * @param int $instance_id Instance ID.
	 */
	public function __construct( $instance_id = 0 ) {
		parent::__construct( $instance_id );
		$this->id                   = Shipping::METHOD_ID;
		$this->method_title         = __( 'Ecomiq', 'ecomiq-for-woocommerce' );
		$this->method_description   = __( 'Shows the shipping rates that Ecomiq calculates for each seller, with your zones, rates and carriers in Ecomiq.', 'ecomiq-for-woocommerce' );
		$this->title                = $this->method_title;
		$this->supports             = array( 'shipping-zones', 'instance-settings', 'instance-settings-modal' );
		$this->instance_form_fields = array(
			'fallback_cost'  => array(
				'title'       => __( 'Fallback rate', 'ecomiq-for-woocommerce' ),
				'type'        => 'price',
				'default'     => '',
				'placeholder' => __( 'None', 'ecomiq-for-woocommerce' ),
				'description' => __( 'Charged when Ecomiq does not answer. Leave it empty to offer no shipping in that case.', 'ecomiq-for-woocommerce' ),
				'desc_tip'    => true,
			),
			'fallback_title' => array(
				'title'       => __( 'Fallback rate name', 'ecomiq-for-woocommerce' ),
				'type'        => 'text',
				'default'     => __( 'Shipping', 'ecomiq-for-woocommerce' ),
				'description' => __( 'What the buyer sees for the fallback rate.', 'ecomiq-for-woocommerce' ),
				'desc_tip'    => true,
			),
		);
	}

	/**
	 * Adds the rates Ecomiq quoted for the package, or the fallback rate when Ecomiq did not answer.
	 *
	 * @param array $package Package.
	 */
	public function calculate_shipping( $package = array() ) {
		if ( empty( $package['ecomiq'] ) || ! is_array( $package['ecomiq'] ) ) {
			return;
		}
		$ecomiq = $package['ecomiq'];
		if ( isset( $ecomiq['rates'] ) ) {
			foreach ( $ecomiq['rates'] as $rate ) {
				$this->add_rate(
					array(
						'id'        => $this->get_rate_id( substr( md5( $rate['reference'] ), 0, 12 ) ),
						'label'     => $rate['name'],
						'cost'      => $rate['price'],
						'taxes'     => false,
						'package'   => $package,
						'meta_data' => array( Shipping::RATE_META => $rate['reference'] ),
					)
				);
			}
			return;
		}
		$cost = trim( (string) $this->get_option( 'fallback_cost' ) );
		if ( empty( $ecomiq['fallback'] ) || '' === $cost ) {
			return;
		}
		$this->add_rate(
			array(
				'id'      => $this->get_rate_id( 'fallback' ),
				'label'   => $this->get_option( 'fallback_title' ),
				'cost'    => wc_format_decimal( $cost ),
				'taxes'   => false,
				'package' => $package,
			)
		);
	}
}
