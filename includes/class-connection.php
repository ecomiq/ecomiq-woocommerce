<?php
/**
 * Connection with Ecomiq.
 *
 * @package Ecomiq\WooCommerce
 */

namespace Ecomiq\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Connection with Ecomiq: API key, settings and webhooks.
 */
final class Connection {

	const OPTION          = 'ecomiq_connection';
	const SETTINGS_OPTION = 'ecomiq_settings';
	const DEFAULT_SERVER  = 'https://admin.ecomiq.pe';
	const WEBHOOK_PREFIX  = 'ecomiq-';
	const WEBHOOK_PATH    = '/webhooks/woocommerce/';

	/**
	 * Returns the Ecomiq address.
	 *
	 * @return string
	 */
	public static function server() {
		$settings = get_option( self::SETTINGS_OPTION, array() );
		if ( is_array( $settings ) && ! empty( $settings['server'] ) ) {
			return untrailingslashit( (string) $settings['server'] );
		}
		return self::DEFAULT_SERVER;
	}

	/**
	 * Saves the Ecomiq address.
	 *
	 * @param string $value Address.
	 *
	 * @return true|\WP_Error
	 */
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

	/**
	 * Returns the current connection with its key details.
	 *
	 * @return array|null
	 */
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

	/**
	 * Creates the API key for Ecomiq and replaces the previous one.
	 *
	 * @param int    $user_id    User who owns the key.
	 * @param string $store_name Store name in Ecomiq.
	 * @param string $store_url  Store URL in Ecomiq.
	 *
	 * @return array|\WP_Error Keys or the error.
	 */
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
		Activity::record( 'connected', self::user_name( $user_id ) );
		return $key;
	}

	/**
	 * Deletes the API key and the Ecomiq webhooks.
	 */
	public static function disconnect() {
		$connection = get_option( self::OPTION );
		if ( is_array( $connection ) && ! empty( $connection['key_id'] ) ) {
			self::delete_key( (int) $connection['key_id'] );
		}
		self::delete_webhooks();
		delete_option( self::OPTION );
		Activity::record( 'disconnected', self::user_name( get_current_user_id() ) );
	}

	/**
	 * Removes everything the plugin stored.
	 */
	public static function uninstall() {
		self::disconnect();
		delete_option( self::SETTINGS_OPTION );
		delete_option( Activity::OPTION );
		delete_metadata( 'user', 0, Admin::DISMISSED_META, '', true );
	}

	/**
	 * Returns the hashed consumer key of the connection.
	 *
	 * @return string
	 */
	public static function key_hash() {
		global $wpdb;
		$connection = get_option( self::OPTION );
		if ( ! is_array( $connection ) || empty( $connection['key_id'] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WooCommerce has no API for REST API keys.
		$hash = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT consumer_key FROM {$wpdb->prefix}woocommerce_api_keys WHERE key_id = %d",
				(int) $connection['key_id']
			)
		);
		if ( ! is_string( $hash ) ) {
			return '';
		}
		return $hash;
	}

	/**
	 * Returns the origin of an address, or null if it is not a valid Ecomiq address.
	 *
	 * @param string $value Address.
	 *
	 * @return string|null
	 */
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

	/**
	 * Returns a user's display name.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return string
	 */
	private static function user_name( $user_id ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return '';
		}
		return $user->display_name;
	}

	/**
	 * Whether a host is a local development host.
	 *
	 * @param string $host Host.
	 *
	 * @return bool
	 */
	private static function is_local( $host ) {
		$host = trim( $host, '[]' );
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}
		return '.localhost' === substr( $host, -10 ) || '.test' === substr( $host, -5 );
	}

	/**
	 * Returns an API key row.
	 *
	 * @param int $key_id Key ID.
	 *
	 * @return object|null
	 */
	private static function key( $key_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WooCommerce has no API for REST API keys.
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

	/**
	 * Creates a read and write API key.
	 *
	 * @param int $user_id User ID.
	 *
	 * @return array|\WP_Error
	 */
	private static function create_key( $user_id ) {
		global $wpdb;
		$consumer_key    = 'ck_' . wc_rand_hash();
		$consumer_secret = 'cs_' . wc_rand_hash();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- WooCommerce has no API for REST API keys.
		$inserted = $wpdb->insert(
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

	/**
	 * Deletes an API key.
	 *
	 * @param int $key_id Key ID.
	 */
	private static function delete_key( $key_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- WooCommerce has no API for REST API keys.
		$wpdb->delete( $wpdb->prefix . 'woocommerce_api_keys', array( 'key_id' => $key_id ), array( '%d' ) );
	}

	/**
	 * Deletes the webhooks Ecomiq created.
	 */
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
