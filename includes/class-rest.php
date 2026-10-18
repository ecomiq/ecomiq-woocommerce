<?php

namespace Ecomiq\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Rest {

	const NAMESPACE_V1 = 'ecomiq/v1';

	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

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
				),
			)
		);
	}

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
			esc_url_raw( (string) $request->get_param( 'store_url' ) )
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

	public static function secure() {
		return is_ssl() || 'local' === wp_get_environment_type();
	}

	private static function site_name() {
		return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}
}
