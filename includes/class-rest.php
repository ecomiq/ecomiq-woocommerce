<?php
/**
 * REST routes for the connection.
 *
 * @package Ecomiq\WooCommerce
 */

namespace Ecomiq\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * REST routes for the Ecomiq connection.
 */
final class Rest {

	const NAMESPACE_V1 = 'ecomiq/v1';

	/**
	 * Hooks the REST routes.
	 */
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_filter( 'woocommerce_rest_is_request_to_rest_api', array( __CLASS__, 'accepts_api_key' ) );
	}

	/**
	 * Lets WooCommerce authenticate the Ecomiq API key on the plugin's private routes.
	 *
	 * @param bool $is_rest Whether WooCommerce already treats the request as its REST API.
	 *
	 * @return bool
	 */
	public static function accepts_api_key( $is_rest ) {
		if ( $is_rest ) {
			return true;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		return false !== strpos( $uri, '/' . rest_get_url_prefix() . '/' . self::NAMESPACE_V1 . '/order-statuses' );
	}

	/**
	 * Registers the status, order statuses and token routes.
	 */
	public static function routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'status' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/order-statuses',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'order_statuses' ),
				'permission_callback' => array( __CLASS__, 'can_manage' ),
			)
		);
		register_rest_route(
			self::NAMESPACE_V1,
			'/token',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'token' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'code'          => array(
						'type'     => 'string',
						'required' => true,
					),
					'code_verifier' => array(
						'type'     => 'string',
						'required' => true,
					),
					'redirect_uri'  => array(
						'type'     => 'string',
						'required' => true,
					),
					'store_name'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'store_url'     => array(
						'type'    => 'string',
						'default' => '',
					),
					'outbound_ips'  => array(
						'type'    => 'array',
						'items'   => array( 'type' => 'string' ),
						'default' => array(),
					),
				),
			)
		);
	}

	/**
	 * Whether the current user can manage the store.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Returns the order statuses of the store, including the ones other plugins add.
	 *
	 * @return \WP_REST_Response
	 */
	public static function order_statuses() {
		$statuses = array();
		foreach ( wc_get_order_statuses() as $key => $label ) {
			if ( 0 === strpos( $key, 'wc-' ) ) {
				$key = substr( $key, 3 );
			}
			if ( 'checkout-draft' === $key ) {
				continue;
			}
			$statuses[] = array(
				'key'   => $key,
				'label' => $label,
			);
		}
		return new \WP_REST_Response( $statuses );
	}

	/**
	 * Returns what Ecomiq needs to start a connection.
	 *
	 * @return \WP_REST_Response
	 */
	public static function status() {
		return new \WP_REST_Response(
			array(
				'plugin'        => VERSION,
				'name'          => self::site_name(),
				'home_url'      => home_url(),
				'rest_url'      => rest_url(),
				'authorize_url' => admin_url( 'admin.php?page=' . Admin::PAGE ),
				'server'        => Connection::server(),
				'https'         => is_ssl(),
			)
		);
	}

	/**
	 * Exchanges an authorization code for the API key.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function token( \WP_REST_Request $request ) {
		if ( ! self::secure() ) {
			return new \WP_Error( 'https_required', __( 'WordPress does not detect HTTPS, so WooCommerce will reject the API key.', 'ecomiq-for-woocommerce' ), array( 'status' => 400 ) );
		}
		$user_id = Authorization::redeem(
			(string) $request->get_param( 'code' ),
			(string) $request->get_param( 'code_verifier' ),
			(string) $request->get_param( 'redirect_uri' )
		);
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}
		$key = Connection::connect(
			$user_id,
			sanitize_text_field( (string) $request->get_param( 'store_name' ) ),
			esc_url_raw( (string) $request->get_param( 'store_url' ) ),
			(array) $request->get_param( 'outbound_ips' )
		);
		if ( is_wp_error( $key ) ) {
			return $key;
		}
		$response = new \WP_REST_Response(
			array(
				'consumer_key'    => $key['consumer_key'],
				'consumer_secret' => $key['consumer_secret'],
				'key_id'          => $key['key_id'],
				'permissions'     => 'read_write',
				'name'            => self::site_name(),
				'home_url'        => home_url(),
				'rest_url'        => rest_url(),
			)
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Whether the token can be exchanged on this site.
	 *
	 * @return bool
	 */
	public static function secure() {
		return is_ssl() || 'local' === wp_get_environment_type();
	}

	/**
	 * Returns the decoded site name.
	 *
	 * @return string
	 */
	private static function site_name() {
		return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}
}
