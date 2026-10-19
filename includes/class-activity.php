<?php
/**
 * Activity log of what Ecomiq does in the store.
 *
 * @package Ecomiq\WooCommerce
 */

namespace Ecomiq\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Activity log of what Ecomiq does in the store.
 */
final class Activity {

	const OPTION = 'ecomiq_activity';
	const LIMIT  = 50;
	const SOURCE = 'ecomiq';

	const REACTIVATE_HOOK  = 'ecomiq_reactivate_webhook';
	const REACTIVATE_DELAY = 900;
	const REPEAT_WINDOW    = 60;

	const STOCK_FIELDS = array( 'stock_quantity', 'manage_stock', 'stock_status', 'backorders' );
	const PRICE_FIELDS = array( 'regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to', 'date_on_sale_from_gmt', 'date_on_sale_to_gmt' );

	/**
	 * Hashed consumer key of the connection, cached per request.
	 *
	 * @var string|null
	 */
	private static $key_hash = null;

	/**
	 * Hooks into REST responses and webhook deliveries.
	 */
	public static function register() {
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'request' ), 10, 3 );
		add_action( 'woocommerce_webhook_delivery', array( __CLASS__, 'delivery' ), 10, 5 );
		add_action( 'woocommerce_webhook_disabled_due_delivery_failures', array( __CLASS__, 'disabled' ) );
		add_action( self::REACTIVATE_HOOK, array( __CLASS__, 'reactivate' ) );
	}

	/**
	 * Returns the recorded activity, newest first.
	 *
	 * @return array[] Events with time, type, subject and failed.
	 */
	public static function events() {
		$events = get_option( self::OPTION, array() );
		if ( ! is_array( $events ) ) {
			return array();
		}
		return $events;
	}

	/**
	 * Returns the WooCommerce logs screen filtered by the Ecomiq source.
	 *
	 * @return string
	 */
	public static function logs_url() {
		return add_query_arg(
			array(
				'page'   => 'wc-status',
				'tab'    => 'logs',
				'source' => self::SOURCE,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Records an event in the activity list and the WooCommerce log.
	 *
	 * @param string $type    Event type.
	 * @param string $subject Optional. What the event refers to.
	 * @param bool   $failed  Optional. Whether the event is a failure.
	 */
	public static function record( $type, $subject = '', $failed = false ) {
		$events = self::events();
		if ( ! empty( $events ) && $type === $events[0]['type'] && (string) $subject === $events[0]['subject'] && time() - (int) $events[0]['time'] < self::REPEAT_WINDOW ) {
			return;
		}
		array_unshift(
			$events,
			array(
				'time'    => time(),
				'type'    => $type,
				'subject' => (string) $subject,
				'failed'  => (bool) $failed,
			)
		);
		update_option( self::OPTION, array_slice( $events, 0, self::LIMIT ), false );
		if ( function_exists( 'wc_get_logger' ) ) {
			$message = self::label( $type );
			if ( '' !== (string) $subject ) {
				$message .= ' · ' . $subject;
			}
			$level = 'info';
			if ( $failed ) {
				$level = 'error';
			}
			wc_get_logger()->log( $level, $message, array( 'source' => self::SOURCE ) );
		}
	}

	/**
	 * Returns the translated label of an event type.
	 *
	 * @param string $type Event type.
	 *
	 * @return string
	 */
	public static function label( $type ) {
		$labels = array(
			'connected'        => __( 'Connected to Ecomiq', 'ecomiq-for-woocommerce' ),
			'disconnected'     => __( 'Disconnected from Ecomiq', 'ecomiq-for-woocommerce' ),
			'product_created'  => __( 'Created a product', 'ecomiq-for-woocommerce' ),
			'product_updated'  => __( 'Updated a product', 'ecomiq-for-woocommerce' ),
			'stock_updated'    => __( 'Updated stock', 'ecomiq-for-woocommerce' ),
			'price_updated'    => __( 'Updated a price', 'ecomiq-for-woocommerce' ),
			'status_updated'   => __( 'Changed a product status', 'ecomiq-for-woocommerce' ),
			'product_deleted'  => __( 'Deleted a product', 'ecomiq-for-woocommerce' ),
			'products_batch'   => __( 'Updated products in bulk', 'ecomiq-for-woocommerce' ),
			'order_created'    => __( 'Created an order', 'ecomiq-for-woocommerce' ),
			'order_updated'    => __( 'Updated an order', 'ecomiq-for-woocommerce' ),
			'webhook_created'  => __( 'Registered a webhook', 'ecomiq-for-woocommerce' ),
			'webhook_deleted'  => __( 'Removed a webhook', 'ecomiq-for-woocommerce' ),
			'data_changed'     => __( 'Changed store data', 'ecomiq-for-woocommerce' ),
			'request_failed'   => __( 'A request failed', 'ecomiq-for-woocommerce' ),
			'webhook_failed'   => __( 'A webhook did not reach Ecomiq', 'ecomiq-for-woocommerce' ),
			'webhook_disabled' => __( 'WooCommerce disabled an Ecomiq webhook after repeated failures', 'ecomiq-for-woocommerce' ),
			'webhook_enabled'  => __( 'Turned an Ecomiq webhook back on', 'ecomiq-for-woocommerce' ),
		);
		if ( isset( $labels[ $type ] ) ) {
			return $labels[ $type ];
		}
		return $type;
	}

	/**
	 * Records the writes and failed requests Ecomiq makes through the WooCommerce REST API.
	 *
	 * @param mixed            $result  Response.
	 * @param \WP_REST_Server  $server  Server instance.
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return mixed The unchanged response.
	 */
	public static function request( $result, $server, $request ) {
		$route = $request->get_route();
		if ( 0 !== strpos( $route, '/wc/' ) || ! self::from_ecomiq( $request ) ) {
			return $result;
		}
		$method = $request->get_method();
		$status = 200;
		if ( $result instanceof \WP_HTTP_Response ) {
			$status = $result->get_status();
		}
		if ( $status >= 400 ) {
			self::record( 'request_failed', self::failure( $method, $route, $status, $result ), true );
			return $result;
		}
		if ( in_array( $method, array( 'GET', 'HEAD', 'OPTIONS' ), true ) ) {
			return $result;
		}
		$data = array();
		if ( $result instanceof \WP_HTTP_Response && is_array( $result->get_data() ) ) {
			$data = $result->get_data();
		}
		self::write( $method, $route, $request, $data );
		return $result;
	}

	/**
	 * Records an Ecomiq webhook that WooCommerce disabled and schedules turning it back on.
	 *
	 * @param int $webhook_id Webhook ID.
	 */
	public static function disabled( $webhook_id ) {
		$webhook = self::ecomiq_webhook( $webhook_id );
		if ( null === $webhook ) {
			return;
		}
		if ( function_exists( 'as_next_scheduled_action' ) && as_next_scheduled_action( self::REACTIVATE_HOOK, array( 'webhook_id' => (int) $webhook_id ), self::SOURCE ) ) {
			return;
		}
		self::record( 'webhook_disabled', $webhook->get_topic(), true );
		Diagnostics::reset();
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + self::REACTIVATE_DELAY, self::REACTIVATE_HOOK, array( 'webhook_id' => (int) $webhook_id ), self::SOURCE );
		}
	}

	/**
	 * Turns an Ecomiq webhook back on if WooCommerce disabled it after failures.
	 *
	 * @param int $webhook_id Webhook ID.
	 */
	public static function reactivate( $webhook_id ) {
		$webhook = self::ecomiq_webhook( $webhook_id );
		if ( null === $webhook || 'disabled' !== $webhook->get_status() || null === Connection::current() ) {
			return;
		}
		$webhook->set_failure_count( 0 );
		$webhook->set_status( 'active' );
		$webhook->save();
		self::record( 'webhook_enabled', $webhook->get_topic() );
		Diagnostics::reset();
	}

	/**
	 * Records Ecomiq webhooks that did not reach Ecomiq.
	 *
	 * @param array           $http_args  Request arguments.
	 * @param array|\WP_Error $response   Response.
	 * @param float           $duration   Delivery time in seconds.
	 * @param mixed           $arg        Resource ID.
	 * @param int             $webhook_id Webhook ID.
	 */
	public static function delivery( $http_args, $response, $duration, $arg, $webhook_id ) {
		if ( ! function_exists( 'wc_get_webhook' ) ) {
			return;
		}
		$webhook = wc_get_webhook( $webhook_id );
		if ( ! $webhook || 0 !== strpos( $webhook->get_name(), Connection::WEBHOOK_PREFIX ) ) {
			return;
		}
		if ( is_wp_error( $response ) ) {
			self::record( 'webhook_failed', $webhook->get_topic() . ' · ' . $response->get_error_message(), true );
			return;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			self::record( 'webhook_failed', $webhook->get_topic() . ' · HTTP ' . $code, true );
		}
	}

	/**
	 * Returns an Ecomiq webhook by ID, or null for other webhooks.
	 *
	 * @param int $webhook_id Webhook ID.
	 *
	 * @return \WC_Webhook|null
	 */
	private static function ecomiq_webhook( $webhook_id ) {
		if ( ! function_exists( 'wc_get_webhook' ) ) {
			return null;
		}
		$webhook = wc_get_webhook( (int) $webhook_id );
		if ( ! $webhook || 0 !== strpos( $webhook->get_name(), Connection::WEBHOOK_PREFIX ) ) {
			return null;
		}
		return $webhook;
	}

	/**
	 * Records a successful write by its route.
	 *
	 * @param string           $method  HTTP method.
	 * @param string           $route   REST route.
	 * @param \WP_REST_Request $request Request.
	 * @param array            $data    Response data.
	 */
	private static function write( $method, $route, \WP_REST_Request $request, array $data ) {
		$subject = self::subject( $data );
		if ( preg_match( '#^/wc/v\d+/products/batch$#', $route ) ) {
			$count = 0;
			foreach ( array( 'create', 'update', 'delete' ) as $kind ) {
				$items = $request->get_param( $kind );
				if ( is_array( $items ) ) {
					$count += count( $items );
				}
			}
			if ( $count > 0 ) {
				/* translators: %d: number of products */
				self::record( 'products_batch', sprintf( __( 'Products: %d', 'ecomiq-for-woocommerce' ), $count ) );
			}
			return;
		}
		if ( preg_match( '#^/wc/v\d+/products/\d+(/variations/\d+)?$#', $route ) ) {
			if ( 'DELETE' === $method ) {
				self::record( 'product_deleted', $subject );
				return;
			}
			self::record( self::product_change( $request ), $subject );
			return;
		}
		if ( preg_match( '#^/wc/v\d+/products(/\d+/variations)?$#', $route ) ) {
			self::record( 'product_created', $subject );
			return;
		}
		if ( preg_match( '#^/wc/v\d+/orders$#', $route ) ) {
			self::record( 'order_created', self::order_subject( $data ) );
			return;
		}
		if ( preg_match( '#^/wc/v\d+/orders/\d+$#', $route ) ) {
			self::record( 'order_updated', self::order_subject( $data ) );
			return;
		}
		if ( preg_match( '#^/wc/v\d+/webhooks(/\d+)?$#', $route ) ) {
			$type = 'webhook_created';
			if ( 'DELETE' === $method ) {
				$type = 'webhook_deleted';
			}
			$topic = '';
			if ( isset( $data['topic'] ) ) {
				$topic = (string) $data['topic'];
			}
			self::record( $type, $topic );
			return;
		}
		self::record( 'data_changed', $method . ' ' . $route );
	}

	/**
	 * Classifies a product update by the fields it sends.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return string Event type.
	 */
	private static function product_change( \WP_REST_Request $request ) {
		$fields = array_keys( (array) $request->get_json_params() );
		if ( empty( $fields ) ) {
			$fields = array_keys( (array) $request->get_body_params() );
		}
		if ( empty( $fields ) ) {
			return 'product_updated';
		}
		if ( ! array_diff( $fields, self::STOCK_FIELDS ) ) {
			return 'stock_updated';
		}
		if ( ! array_diff( $fields, self::PRICE_FIELDS ) ) {
			return 'price_updated';
		}
		if ( array( 'status' ) === $fields ) {
			return 'status_updated';
		}
		return 'product_updated';
	}

	/**
	 * Describes a product by name or SKU and ID.
	 *
	 * @param array $data Response data.
	 *
	 * @return string
	 */
	private static function subject( array $data ) {
		$id = '';
		if ( isset( $data['id'] ) ) {
			$id = '#' . (int) $data['id'];
		}
		if ( ! empty( $data['name'] ) ) {
			return trim( wp_strip_all_tags( (string) $data['name'] ) . ' (' . $id . ')' );
		}
		if ( ! empty( $data['sku'] ) ) {
			return trim( (string) $data['sku'] . ' (' . $id . ')' );
		}
		return $id;
	}

	/**
	 * Describes an order by number and status.
	 *
	 * @param array $data Response data.
	 *
	 * @return string
	 */
	private static function order_subject( array $data ) {
		$subject = '';
		if ( isset( $data['number'] ) ) {
			$subject = '#' . $data['number'];
		} elseif ( isset( $data['id'] ) ) {
			$subject = '#' . (int) $data['id'];
		}
		if ( ! empty( $data['status'] ) && function_exists( 'wc_get_order_status_name' ) ) {
			$subject .= ' · ' . wc_get_order_status_name( (string) $data['status'] );
		}
		return $subject;
	}

	/**
	 * Describes a failed request.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  REST route.
	 * @param int    $status HTTP status.
	 * @param mixed  $result Response.
	 *
	 * @return string
	 */
	private static function failure( $method, $route, $status, $result ) {
		$detail = $method . ' ' . $route . ' · ' . $status;
		if ( $result instanceof \WP_HTTP_Response ) {
			$data = $result->get_data();
			if ( is_array( $data ) && ! empty( $data['message'] ) ) {
				$detail .= ' · ' . wp_strip_all_tags( (string) $data['message'] );
			}
		}
		return $detail;
	}

	/**
	 * Whether the request uses the Ecomiq API key.
	 *
	 * @param \WP_REST_Request $request Request.
	 *
	 * @return bool
	 */
	private static function from_ecomiq( \WP_REST_Request $request ) {
		$key = '';
		if ( ! empty( $_SERVER['PHP_AUTH_USER'] ) ) {
			$key = sanitize_text_field( wp_unslash( $_SERVER['PHP_AUTH_USER'] ) );
		} elseif ( is_string( $request->get_param( 'consumer_key' ) ) ) {
			$key = $request->get_param( 'consumer_key' );
		} elseif ( is_string( $request->get_param( 'oauth_consumer_key' ) ) ) {
			$key = $request->get_param( 'oauth_consumer_key' );
		}
		if ( '' === $key || 0 !== strpos( $key, 'ck_' ) ) {
			return false;
		}
		$hash = self::key_hash();
		if ( '' === $hash ) {
			return false;
		}
		return hash_equals( $hash, wc_api_hash( $key ) );
	}

	/**
	 * Returns the hashed consumer key of the connection, cached per request.
	 *
	 * @return string
	 */
	private static function key_hash() {
		if ( null !== self::$key_hash ) {
			return self::$key_hash;
		}
		self::$key_hash = Connection::key_hash();
		return self::$key_hash;
	}
}
