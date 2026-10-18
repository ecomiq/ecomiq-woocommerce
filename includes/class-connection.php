<?php

namespace Ecomiq\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Connection {

	const OPTION          = 'ecomiq_connection';
	const SETTINGS_OPTION = 'ecomiq_settings';
	const DEFAULT_SERVER  = 'https://admin.ecomiq.pe';
	const WEBHOOK_PREFIX  = 'ecomiq-';
	const WEBHOOK_PATH    = '/webhooks/woocommerce/';

	public static function server() {
		$settings = get_option( self::SETTINGS_OPTION, array() );
		if ( is_array( $settings ) && ! empty( $settings['server'] ) ) {
			return untrailingslashit( (string) $settings['server'] );
		}
		return self::DEFAULT_SERVER;
	}

	public static function save_server( $value ) {
		$server = self::origin( trim( (string) $value ) );
		if ( null === $server ) {
			return new \WP_Error( 'ecomiq_server', __( 'Use an address like https://admin.ecomiq.pe, without a path.', 'ecomiq-for-woocommerce' ) );
		}
		if ( self::DEFAULT_SERVER === $server ) {
			delete_option( self::SETTINGS_OPTION );
			return true;
		}
		update_option( self::SETTINGS_OPTION, array( 'server' => $server ), false );
		return true;
	}

	public static function current() {
		$connection = get_option( self::OPTION );
		if ( ! is_array( $connection ) || empty( $connection['key_id'] ) ) {
			return null;
		}
		$key = self::key( (int) $connection['key_id'] );
		if ( null === $key ) {
			delete_option( self::OPTION );
			return null;
		}
		$connection['truncated_key'] = $key->truncated_key;
		$connection['last_access']   = null;
		if ( ! empty( $key->last_access ) ) {
			$connection['last_access'] = (int) get_gmt_from_date( $key->last_access, 'U' );
		}
		return $connection;
	}

	public static function connect( $user_id, $store_name, $store_url ) {
		$previous = get_option( self::OPTION );
		$key      = self::create_key( $user_id );
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		if ( is_array( $previous ) && ! empty( $previous['key_id'] ) ) {
			self::delete_key( (int) $previous['key_id'] );
		}
		if ( 0 !== strpos( $store_url, self::server() . '/' ) ) {
			$store_url = '';
		}
		update_option(
			self::OPTION,
			array(
				'key_id'       => $key['key_id'],
				'user_id'      => (int) $user_id,
				'connected_at' => time(),
				'store_name'   => $store_name,
				'store_url'    => $store_url,
			),
			false
		);
		return $key;
	}

	public static function disconnect() {
		$connection = get_option( self::OPTION );
		if ( is_array( $connection ) && ! empty( $connection['key_id'] ) ) {
			self::delete_key( (int) $connection['key_id'] );
		}
		self::delete_webhooks();
		delete_option( self::OPTION );
	}

	public static function uninstall() {
		global $wpdb;
		self::disconnect();
		delete_option( self::SETTINGS_OPTION );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . Authorization::TRANSIENT_PREFIX ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . Authorization::TRANSIENT_PREFIX ) . '%'
			)
		);
	}

	public static function origin( $value ) {
		$parts = wp_parse_url( $value );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}
		if ( isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['query'] ) || isset( $parts['fragment'] ) ) {
			return null;
		}
		if ( isset( $parts['path'] ) && '' !== trim( $parts['path'], '/' ) ) {
			return null;
		}
		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( $parts['host'] );
		if ( 'https' !== $scheme && ! ( 'http' === $scheme && self::is_local( $host ) ) ) {
			return null;
		}
		$origin = $scheme . '://' . $host;
		if ( isset( $parts['port'] ) ) {
			$origin .= ':' . (int) $parts['port'];
		}
		return $origin;
	}

	private static function is_local( $host ) {
		$host = trim( $host, '[]' );
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}
		return '.localhost' === substr( $host, -10 ) || '.test' === substr( $host, -5 );
	}

	private static function key( $key_id ) {
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT key_id, truncated_key, last_access FROM {$wpdb->prefix}woocommerce_api_keys WHERE key_id = %d",
				$key_id
			)
		);
		if ( ! $row ) {
			return null;
		}
		return $row;
	}

	private static function create_key( $user_id ) {
		global $wpdb;
		$consumer_key    = 'ck_' . wc_rand_hash();
		$consumer_secret = 'cs_' . wc_rand_hash();
		$inserted        = $wpdb->insert(
			$wpdb->prefix . 'woocommerce_api_keys',
			array(
				'user_id'         => (int) $user_id,
				'description'     => 'Ecomiq',
				'permissions'     => 'read_write',
				'consumer_key'    => wc_api_hash( $consumer_key ),
				'consumer_secret' => $consumer_secret,
				'truncated_key'   => substr( $consumer_key, -7 ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
		if ( false === $inserted ) {
			return new \WP_Error( 'ecomiq_key', __( 'WooCommerce could not create the API key.', 'ecomiq-for-woocommerce' ), array( 'status' => 500 ) );
		}
		return array(
			'key_id'          => (int) $wpdb->insert_id,
			'consumer_key'    => $consumer_key,
			'consumer_secret' => $consumer_secret,
		);
	}

	private static function delete_key( $key_id ) {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'woocommerce_api_keys', array( 'key_id' => $key_id ), array( '%d' ) );
	}

	private static function delete_webhooks() {
		if ( ! function_exists( 'wc_get_webhook' ) || ! class_exists( 'WC_Data_Store' ) ) {
			return;
		}
		$ids = \WC_Data_Store::load( 'webhook' )->search_webhooks(
			array(
				'search' => self::WEBHOOK_PREFIX,
				'limit'  => -1,
			)
		);
		foreach ( (array) $ids as $id ) {
			$webhook = wc_get_webhook( $id );
			if ( ! $webhook ) {
				continue;
			}
			if ( 0 !== strpos( $webhook->get_name(), self::WEBHOOK_PREFIX ) ) {
				continue;
			}
			if ( false === strpos( $webhook->get_delivery_url(), self::WEBHOOK_PATH ) ) {
				continue;
			}
			$webhook->delete( true );
		}
	}
}
