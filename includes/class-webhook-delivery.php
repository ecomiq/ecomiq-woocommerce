<?php
/**
 * Durable retries of the original signed webhook request.
 *
 * @package Ecomiq\WooCommerce
 */

namespace Ecomiq\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Durable retries of the original signed webhook request.
 */
final class Webhook_Delivery {

	const HOOK          = 'ecomiq_retry_webhook_delivery';
	const GROUP         = 'ecomiq-webhook-deliveries';
	const OPTION_PREFIX = 'ecomiq_webhook_delivery_';
	const DELAYS        = array( 30, 60, 120, 300, 900 );

	/**
	 * Registers the queue callback and explicit recovery command.
	 */
	public static function register() {
		add_action( self::HOOK, array( __CLASS__, 'retry' ), 10, 3 );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'ecomiq webhook retry', array( __CLASS__, 'command' ) );
		}
	}

	/**
	 * Persists a failed delivery without rebuilding its payload or signature.
	 *
	 * @param \WC_Webhook     $webhook Webhook.
	 * @param array           $request Original HTTP arguments.
	 * @param array|\WP_Error $response Original response.
	 * @throws \RuntimeException When retaining or scheduling the delivery fails.
	 */
	public static function failed( $webhook, array $request, $response ) {
		$key     = hash( 'sha256', $webhook->get_id() . '|' . $request['headers']['X-WC-Webhook-Delivery-ID'] . '|' . hash( 'sha256', $request['body'] ) );
		$actions = as_get_scheduled_actions(
			array(
				'hook'     => self::HOOK,
				'group'    => self::GROUP,
				'search'   => '"delivery_key":"' . $key . '"',
				'per_page' => 1,
			),
			'ids'
		);
		if ( ! empty( $actions ) ) {
			return;
		}
		add_option(
			self::OPTION_PREFIX . $key,
			array(
				'webhook_id' => (int) $webhook->get_id(),
				'url'        => $webhook->get_delivery_url(),
				'request'    => $request,
			),
			'',
			false
		);
		if ( ! is_array( get_option( self::OPTION_PREFIX . $key ) ) ) {
			throw new \RuntimeException( 'Could not preserve the failed Ecomiq webhook delivery.' );
		}
		$attempt = 1;
		$delay   = self::DELAYS[0];
		if ( ! self::transient_failure( $response ) ) {
			$attempt = count( self::DELAYS );
			$delay   = 0;
		}
		$action_id = self::schedule( $key, $attempt, $delay );
		if ( 0 === $delay && \ActionScheduler_Store::STATUS_PENDING === \ActionScheduler::store()->get_status( $action_id ) ) {
			\ActionScheduler::store()->mark_failure( $action_id );
			\ActionScheduler::logger()->log( $action_id, 'Ecomiq webhook rejected: HTTP ' . (int) wp_remote_retrieve_response_code( $response ) );
		}
	}

	/**
	 * Repeats the exact signed request with a bounded automatic retry budget.
	 *
	 * @param string $delivery_key Delivery identity.
	 * @param int    $attempt Retry number, from one to five.
	 * @param int    $recovery_of Optional. Failed action explicitly recovered.
	 * @throws \InvalidArgumentException When the retry number is invalid.
	 * @throws \RuntimeException When the destination changed or delivery needs recovery.
	 */
	public static function retry( $delivery_key, $attempt, $recovery_of = 0 ) {
		$delivery = get_option( self::OPTION_PREFIX . $delivery_key );
		if ( ! is_array( $delivery ) ) {
			return;
		}
		if ( $attempt < 1 || $attempt > count( self::DELAYS ) ) {
			throw new \InvalidArgumentException( 'Invalid Ecomiq webhook retry number.' );
		}
		$webhook  = self::webhook( $delivery );
		$start    = microtime( true );
		$response = wp_safe_remote_request( $delivery['url'], $delivery['request'] );
		$webhook->log_delivery( $delivery['request']['headers']['X-WC-Webhook-Delivery-ID'], $delivery['request'], $response, round( microtime( true ) - $start, 5 ) );
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( ! is_wp_error( $response ) && $code >= 200 && $code < 300 ) {
			delete_option( self::OPTION_PREFIX . $delivery_key );
			return;
		}
		Activity::record( 'webhook_failed', $webhook->get_topic() . ' · ' . $delivery_key, true );
		if ( 0 === (int) $recovery_of && $attempt < count( self::DELAYS ) && self::transient_failure( $response ) ) {
			self::schedule( $delivery_key, $attempt + 1, self::DELAYS[ $attempt ] );
			return;
		}
		throw new \RuntimeException( 'Ecomiq webhook delivery requires recovery: ' . esc_html( $delivery_key ) );
	}

	/**
	 * Schedules one explicit attempt from a failed delivery action.
	 *
	 * @param int $action_id Failed action ID.
	 *
	 * @return int Recovery action ID.
	 * @throws \InvalidArgumentException When the action cannot be recovered.
	 */
	public static function recover( $action_id ) {
		$store  = \ActionScheduler::store();
		$action = $store->fetch_action( $action_id );
		if ( self::HOOK !== $action->get_hook() || self::GROUP !== $action->get_group() || \ActionScheduler_Store::STATUS_FAILED !== $store->get_status( $action_id ) ) {
			throw new \InvalidArgumentException( 'Select a failed Ecomiq webhook delivery action.' );
		}
		$args     = $action->get_args();
		$delivery = get_option( self::OPTION_PREFIX . $args['delivery_key'] );
		if ( ! is_array( $delivery ) ) {
			throw new \InvalidArgumentException( 'The Ecomiq webhook delivery was already completed or removed.' );
		}
		self::webhook( $delivery );
		return self::schedule( $args['delivery_key'], count( self::DELAYS ), 0, (int) $action_id );
	}

	/**
	 * Recovers a retained delivery when its first action could not be saved.
	 *
	 * @param string $delivery_key Retained delivery identity.
	 *
	 * @return int Recovery action ID.
	 * @throws \InvalidArgumentException When the retained delivery is unavailable.
	 */
	public static function recover_delivery( $delivery_key ) {
		if ( ! is_string( $delivery_key ) || ! preg_match( '/^[a-f0-9]{64}$/', $delivery_key ) ) {
			throw new \InvalidArgumentException( 'Select a retained Ecomiq webhook delivery key.' );
		}
		$delivery = get_option( self::OPTION_PREFIX . $delivery_key );
		if ( ! is_array( $delivery ) ) {
			throw new \InvalidArgumentException( 'The Ecomiq webhook delivery was already completed or removed.' );
		}
		self::webhook( $delivery );
		return self::schedule( $delivery_key, count( self::DELAYS ), 0, -1 );
	}

	/**
	 * Recovers one failed action through WP-CLI.
	 *
	 * ## OPTIONS
	 *
	 * [<action-id>]
	 * : Failed action ID shown in WooCommerce Scheduled Actions.
	 *
	 * [--delivery-key=<key>]
	 * : Retained delivery key when its first action could not be saved.
	 *
	 * @param string[] $args Command arguments.
	 * @param array    $assoc_args Command options.
	 */
	public static function command( array $args, array $assoc_args ) {
		try {
			if ( isset( $assoc_args['delivery-key'] ) && empty( $args ) ) {
				$action_id = self::recover_delivery( $assoc_args['delivery-key'] );
			} elseif ( ! isset( $assoc_args['delivery-key'] ) && 1 === count( $args ) ) {
				$action_id = self::recover( (int) $args[0] );
			} else {
				\WP_CLI::error( 'Provide one failed action ID or --delivery-key.' );
				return;
			}
			\WP_CLI::success( 'Recovery action: ' . $action_id );
		} catch ( \Exception $error ) {
			\WP_CLI::error( $error->getMessage() );
		}
	}

	/**
	 * Removes retained payloads when the plugin is uninstalled.
	 */
	public static function uninstall() {
		global $wpdb;
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}
		do {
			$names = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 100", $wpdb->esc_like( self::OPTION_PREFIX ) . '%' ) );
			foreach ( $names as $name ) {
				delete_option( $name );
			}
			$count = count( $names );
		} while ( 100 === $count );
	}

	/**
	 * Loads the original destination and verifies the retained signature.
	 *
	 * @param array $delivery Retained delivery.
	 *
	 * @return \WC_Webhook
	 * @throws \RuntimeException When the original connection is no longer valid.
	 */
	private static function webhook( array $delivery ) {
		$webhook = wc_get_webhook( $delivery['webhook_id'] );
		if ( ! $webhook || 0 !== strpos( $webhook->get_name(), Connection::WEBHOOK_PREFIX ) || $delivery['url'] !== $webhook->get_delivery_url() || null === Connection::current() ) {
			throw new \RuntimeException( 'The original Ecomiq webhook connection is no longer available.' );
		}
		$signature = $webhook->generate_signature( $delivery['request']['body'] );
		if ( ! hash_equals( $signature, $delivery['request']['headers']['X-WC-Webhook-Signature'] ) ) {
			throw new \RuntimeException( 'The original Ecomiq webhook secret changed.' );
		}
		return $webhook;
	}

	/**
	 * Schedules each automatic retry once, including after duplicate notifications.
	 *
	 * @param string $delivery_key Delivery identity.
	 * @param int    $attempt Retry number.
	 * @param int    $delay Delay in seconds.
	 * @param int    $recovery_of Optional. Failed action explicitly recovered.
	 *
	 * @return int Action ID.
	 * @throws \RuntimeException When scheduling the delivery fails.
	 */
	private static function schedule( $delivery_key, $attempt, $delay, $recovery_of = 0 ) {
		$args     = array(
			'delivery_key' => $delivery_key,
			'attempt'      => (int) $attempt,
			'recovery_of'  => (int) $recovery_of,
		);
		$existing = as_get_scheduled_actions(
			array(
				'hook'     => self::HOOK,
				'group'    => self::GROUP,
				'args'     => $args,
				'status'   => array( 'pending', 'in-progress', 'complete', 'failed' ),
				'per_page' => 1,
			),
			'ids'
		);
		if ( ! empty( $existing ) ) {
			return (int) $existing[0];
		}
		$action_id = as_schedule_single_action( time() + $delay, self::HOOK, $args, self::GROUP, true );
		if ( ! $action_id ) {
			throw new \RuntimeException( 'Could not schedule Ecomiq webhook delivery: ' . esc_html( $delivery_key ) );
		}
		return (int) $action_id;
	}

	/**
	 * Whether another delivery can succeed without changing the request.
	 *
	 * @param array|\WP_Error $response HTTP response.
	 *
	 * @return bool
	 */
	private static function transient_failure( $response ) {
		if ( is_wp_error( $response ) ) {
			return true;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		return in_array( $code, array( 408, 425, 429 ), true ) || $code >= 500;
	}
}
