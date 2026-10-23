<?php
/**
 * Shipping rates that Ecomiq quotes at checkout.
 *
 * @package Ecomiq\WooCommerce
 */

namespace Ecomiq\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Splits the cart into one package per seller and asks Ecomiq for the rates of each one.
 */
final class Shipping {

	const METHOD_ID   = 'ecomiq_rates';
	const RATE_META   = 'ecomiq_rate';
	const SESSION_KEY = 'ecomiq_shipping_quote';
	const RATES_PATH  = '/shipping-rates';
	const TIMEOUT     = 10;
	const QUOTE_TTL   = 600;
	const FAILURE_TTL = 60;

	/**
	 * Quotes already answered in this request, by request hash.
	 *
	 * @var array
	 */
	private static $quotes = array();

	/**
	 * Ecomiq webhook found in this request, or false before looking it up.
	 *
	 * @var \WC_Webhook|null|false
	 */
	private static $webhook = false;

	/**
	 * Hooks the shipping method and the cart packages.
	 */
	public static function register() {
		add_filter( 'woocommerce_shipping_methods', array( __CLASS__, 'shipping_methods' ) );
		add_filter( 'woocommerce_cart_shipping_packages', array( __CLASS__, 'packages' ), 20 );
		add_filter( 'woocommerce_shipping_package_name', array( __CLASS__, 'package_name' ), 10, 3 );
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hidden_meta' ) );
	}

	/**
	 * Adds the Ecomiq rates method.
	 *
	 * @param array $methods Shipping method classes by ID.
	 *
	 * @return array
	 */
	public static function shipping_methods( $methods ) {
		require_once __DIR__ . '/class-shipping-rates.php';
		$methods[ self::METHOD_ID ] = Shipping_Rates::class;
		return $methods;
	}

	/**
	 * Splits the cart into the packages Ecomiq quotes when the destination zone uses the Ecomiq method.
	 *
	 * @param array $packages Cart packages.
	 *
	 * @return array
	 */
	public static function packages( $packages ) {
		if ( ! is_array( $packages ) || empty( $packages ) || ! self::quotes_zone( $packages[0] ) ) {
			return $packages;
		}
		$base     = $packages[0];
		$contents = array();
		foreach ( $packages as $package ) {
			foreach ( (array) $package['contents'] as $key => $item ) {
				$contents[ $key ] = $item;
			}
		}
		$quote = self::quote( $contents, (array) $base['destination'] );
		if ( null === $quote ) {
			foreach ( array_keys( $packages ) as $index ) {
				$packages[ $index ]['ecomiq'] = array( 'fallback' => true );
			}
			return $packages;
		}
		$split = array();
		foreach ( $quote['groups'] as $group ) {
			$group_contents = array_intersect_key( $contents, array_flip( $group['items'] ) );
			if ( empty( $group_contents ) ) {
				continue;
			}
			$split[]  = self::package(
				$base,
				$group_contents,
				array(
					'seller' => $group['seller'],
					'rates'  => $group['rates'],
				)
			);
			$contents = array_diff_key( $contents, $group_contents );
		}
		if ( ! empty( $contents ) ) {
			$split[] = self::package( $base, $contents, null );
		}
		return $split;
	}

	/**
	 * Names each package after its seller when the cart has several.
	 *
	 * @param string $name    Package name.
	 * @param int    $index   Package index.
	 * @param array  $package Package.
	 *
	 * @return string
	 */
	public static function package_name( $name, $index, $package ) {
		if ( empty( $package['ecomiq']['seller'] ) ) {
			return $name;
		}
		/* translators: %s: seller name */
		return sprintf( __( 'Shipping from %s', 'ecomiq-for-woocommerce' ), $package['ecomiq']['seller'] );
	}

	/**
	 * Hides the Ecomiq rate reference on the order screen.
	 *
	 * @param array $keys Hidden meta keys.
	 *
	 * @return array
	 */
	public static function hidden_meta( $keys ) {
		$keys[] = self::RATE_META;
		return $keys;
	}

	/**
	 * Whether the zone of a package offers the Ecomiq method.
	 *
	 * @param array $package Package.
	 *
	 * @return bool
	 */
	private static function quotes_zone( array $package ) {
		if ( empty( $package['destination']['country'] ) ) {
			return false;
		}
		$zone = \WC_Shipping_Zones::get_zone_matching_package( $package );
		foreach ( $zone->get_shipping_methods( true ) as $method ) {
			if ( self::METHOD_ID === $method->id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Builds a package from the cart package with part of its contents.
	 *
	 * @param array      $base     Cart package.
	 * @param array      $contents Cart items of the package.
	 * @param array|null $ecomiq   Seller and rates that Ecomiq quoted.
	 *
	 * @return array
	 */
	private static function package( array $base, array $contents, $ecomiq ) {
		$package                  = $base;
		$package['contents']      = $contents;
		$package['contents_cost'] = array_sum( wp_list_pluck( $contents, 'line_total' ) );
		unset( $package['ecomiq'] );
		if ( null !== $ecomiq ) {
			$package['ecomiq'] = $ecomiq;
		}
		return $package;
	}

	/**
	 * Asks Ecomiq for the rates of the cart items, or returns null when Ecomiq does not answer.
	 *
	 * @param array $contents    Cart items by key.
	 * @param array $destination Shipping destination.
	 *
	 * @return array|null
	 */
	private static function quote( array $contents, array $destination ) {
		$webhook = self::webhook();
		if ( null === $webhook ) {
			return null;
		}
		$items = array();
		foreach ( $contents as $key => $item ) {
			$quantity = (int) $item['quantity'];
			if ( $quantity <= 0 || ! $item['data'] instanceof \WC_Product ) {
				continue;
			}
			$variant_id = (int) $item['variation_id'];
			if ( $variant_id <= 0 ) {
				$variant_id = (int) $item['product_id'];
			}
			$items[] = array(
				'key'       => (string) $key,
				'productId' => (string) $item['product_id'],
				'variantId' => (string) $variant_id,
				'sku'       => (string) $item['data']->get_sku(),
				'quantity'  => $quantity,
				'price'     => round( ( (float) $item['line_total'] + (float) $item['line_tax'] ) / $quantity, wc_get_price_decimals() ),
			);
		}
		if ( empty( $items ) ) {
			return array( 'groups' => array() );
		}
		$country = (string) $destination['country'];
		$state   = isset( $destination['state'] ) ? (string) $destination['state'] : '';
		$body    = (string) wp_json_encode(
			array(
				'currency'    => get_woocommerce_currency(),
				'language'    => substr( determine_locale(), 0, 2 ),
				'destination' => array(
					'country'   => $country,
					'state'     => $state,
					'stateName' => self::state_name( $country, $state ),
					'city'      => isset( $destination['city'] ) ? (string) $destination['city'] : '',
					'postcode'  => isset( $destination['postcode'] ) ? (string) $destination['postcode'] : '',
					'address1'  => isset( $destination['address_1'] ) ? (string) $destination['address_1'] : '',
					'address2'  => isset( $destination['address_2'] ) ? (string) $destination['address_2'] : '',
				),
				'items'       => $items,
			)
		);
		$url     = untrailingslashit( $webhook->get_delivery_url() ) . self::RATES_PATH;
		$hash    = md5( $url . $body );
		if ( isset( self::$quotes[ $hash ] ) ) {
			return self::$quotes[ $hash ];
		}
		$stored = null;
		if ( WC()->session ) {
			$stored = WC()->session->get( self::SESSION_KEY );
		}
		if ( is_array( $stored ) && isset( $stored['hash'], $stored['expires'] ) && $hash === $stored['hash'] && $stored['expires'] > time() ) {
			self::$quotes[ $hash ] = $stored['quote'];
			return $stored['quote'];
		}
		$response = wp_safe_remote_post(
			$url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 0,
				'headers'     => array(
					'Content-Type'           => 'application/json',
					'X-WC-Webhook-Source'    => home_url( '/' ),
					'X-WC-Webhook-Signature' => $webhook->generate_signature( $body ),
				),
				'body'        => $body,
			)
		);
		$quote    = self::read( $response );
		$ttl      = self::QUOTE_TTL;
		if ( null === $quote ) {
			$ttl = self::FAILURE_TTL;
			Activity::record( 'quote_failed', self::failure( $response ), true );
		}
		self::$quotes[ $hash ] = $quote;
		if ( WC()->session ) {
			WC()->session->set(
				self::SESSION_KEY,
				array(
					'hash'    => $hash,
					'quote'   => $quote,
					'expires' => time() + $ttl,
				)
			);
		}
		return $quote;
	}

	/**
	 * Reads the rates Ecomiq answered, or returns null if the answer is not usable.
	 *
	 * @param array|\WP_Error $response Response.
	 *
	 * @return array|null
	 */
	private static function read( $response ) {
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['groups'] ) || ! is_array( $data['groups'] ) ) {
			return null;
		}
		$groups = array();
		foreach ( $data['groups'] as $group ) {
			if ( ! is_array( $group ) || ! isset( $group['items'], $group['rates'] ) || ! is_array( $group['items'] ) || ! is_array( $group['rates'] ) ) {
				return null;
			}
			$rates = array();
			foreach ( $group['rates'] as $rate ) {
				if ( ! is_array( $rate ) || ! isset( $rate['reference'], $rate['name'], $rate['price'] ) || ! is_numeric( $rate['price'] ) ) {
					return null;
				}
				$rates[] = array(
					'reference' => (string) $rate['reference'],
					'name'      => (string) $rate['name'],
					'price'     => (float) $rate['price'],
				);
			}
			$seller = '';
			if ( isset( $group['sellerName'] ) && is_string( $group['sellerName'] ) ) {
				$seller = $group['sellerName'];
			}
			$groups[] = array(
				'seller' => $seller,
				'items'  => array_map( 'strval', $group['items'] ),
				'rates'  => $rates,
			);
		}
		return array( 'groups' => $groups );
	}

	/**
	 * Describes why a quote failed.
	 *
	 * @param array|\WP_Error $response Response.
	 *
	 * @return string
	 */
	private static function failure( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}
		return 'HTTP ' . (int) wp_remote_retrieve_response_code( $response );
	}

	/**
	 * Returns the Ecomiq webhook whose address and secret the quote uses.
	 *
	 * @return \WC_Webhook|null
	 */
	private static function webhook() {
		if ( false !== self::$webhook ) {
			return self::$webhook;
		}
		self::$webhook = null;
		if ( ! function_exists( 'wc_get_webhook' ) || ! class_exists( 'WC_Data_Store' ) || null === Connection::current() ) {
			return null;
		}
		$ids = \WC_Data_Store::load( 'webhook' )->search_webhooks(
			array(
				'search' => Connection::WEBHOOK_PREFIX,
				'limit'  => -1,
			)
		);
		foreach ( (array) $ids as $id ) {
			$webhook = wc_get_webhook( $id );
			if ( ! $webhook || 0 !== strpos( $webhook->get_name(), Connection::WEBHOOK_PREFIX ) || '' === (string) $webhook->get_secret() ) {
				continue;
			}
			if ( false !== strpos( $webhook->get_delivery_url(), Connection::WEBHOOK_PATH ) ) {
				self::$webhook = $webhook;
				return $webhook;
			}
		}
		return null;
	}

	/**
	 * Returns the name of a state, without HTML entities.
	 *
	 * @param string $country Country code.
	 * @param string $state   State code.
	 *
	 * @return string
	 */
	private static function state_name( $country, $state ) {
		$states = WC()->countries->get_states( $country );
		if ( ! is_array( $states ) || ! isset( $states[ $state ] ) ) {
			return '';
		}
		return html_entity_decode( (string) $states[ $state ], ENT_QUOTES, 'UTF-8' );
	}
}
