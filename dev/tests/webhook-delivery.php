<?php

namespace Ecomiq\WooCommerce {

	final class Connection {
		const WEBHOOK_PREFIX = 'ecomiq-';
		public static $connected = true;
		public static function current() {
			if ( self::$connected ) {
				return array( 'key_id' => 1 );
			}
			return null;
		}
	}
}

namespace {

	define( 'ABSPATH', __DIR__ );
	define( 'WP_CLI', false );

	final class WP_Error {
		public function get_error_message() {
			return 'Connection timed out.';
		}
	}

	final class DeliveryAction {
		public $args;
		public $status = 'pending';
		public $when;
		public function __construct( $args, $when ) {
			$this->args = $args;
			$this->when = $when;
		}
		public function get_args() {
			return $this->args;
		}
		public function get_hook() {
			return \Ecomiq\WooCommerce\Webhook_Delivery::HOOK;
		}
		public function get_group() {
			return \Ecomiq\WooCommerce\Webhook_Delivery::GROUP;
		}
	}

	final class DeliveryStore {
		public $actions = array();
		public function fetch_action( $id ) {
			return $this->actions[ $id ];
		}
		public function get_status( $id ) {
			return $this->actions[ $id ]->status;
		}
		public function mark_failure( $id ) {
			$this->actions[ $id ]->status = 'failed';
		}
		public function log( $id, $message ) {}
	}

	final class ActionScheduler_Store {
		const STATUS_FAILED = 'failed';
		const STATUS_PENDING = 'pending';
	}

	final class ActionScheduler {
		public static $store;
		public static function store() {
			return self::$store;
		}
		public static function logger() {
			return self::$store;
		}
	}

	final class WC_Webhook {
		public $secret = 'test-webhook-secret';
		public $url = 'https://example.test/webhooks/woocommerce/1';
		public $name = 'ecomiq-product.updated';
		public $failures = 0;
		public function get_id() {
			return 42;
		}
		public function get_name() {
			return $this->name;
		}
		public function get_topic() {
			return 'product.updated';
		}
		public function get_delivery_url() {
			return $this->url;
		}
		public function generate_signature( $body ) {
			return base64_encode( hash_hmac( 'sha256', $body, $this->secret, true ) );
		}
		public function log_delivery( $id, $request, $response, $duration ) {
			if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) >= 300 ) {
				++$this->failures;
			} else {
				$this->failures = 0;
			}
		}
	}

	function add_action( $hook, $callback, $priority = 10, $args = 1 ) {}
	function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {}
	function __( $value, $domain ) {
		return $value;
	}
	function esc_html( $value ) {
		return htmlspecialchars( $value );
	}
	function get_option( $key, $default = false ) {
		if ( isset( $GLOBALS['options'][ $key ] ) ) {
			return $GLOBALS['options'][ $key ];
		}
		return $default;
	}
	function add_option( $key, $value, $deprecated = '', $autoload = true ) {
		if ( isset( $GLOBALS['options'][ $key ] ) ) {
			return false;
		}
		$GLOBALS['options'][ $key ] = $value;
		$GLOBALS['autoload'][ $key ] = $autoload;
		return true;
	}
	function update_option( $key, $value, $autoload = true ) {
		$GLOBALS['options'][ $key ] = $value;
		return true;
	}
	function delete_option( $key ) {
		unset( $GLOBALS['options'][ $key ] );
		return true;
	}
	function is_wp_error( $response ) {
		return $response instanceof WP_Error;
	}
	function wp_remote_retrieve_response_code( $response ) {
		if ( is_wp_error( $response ) ) {
			return '';
		}
		return $response['response']['code'];
	}
	function wc_get_webhook( $id ) {
		if ( 42 === (int) $id ) {
			return $GLOBALS['webhook'];
		}
		return false;
	}
	function as_get_scheduled_actions( $query, $format ) {
		$ids = array();
		foreach ( ActionScheduler::store()->actions as $id => $action ) {
			$matches = false;
			if ( isset( $query['search'] ) ) {
				$matches = false !== strpos( json_encode( $action->args ), $query['search'] );
			} elseif ( isset( $query['args'] ) ) {
				$matches = $action->args === $query['args'];
			}
			if ( $matches && ( ! isset( $query['status'] ) || in_array( $action->status, $query['status'], true ) ) ) {
				$ids[] = $id;
			}
		}
		return array_slice( $ids, 0, $query['per_page'] );
	}
	function as_schedule_single_action( $when, $hook, $args, $group, $unique ) {
		if ( $GLOBALS['schedule_failure'] ) {
			return 0;
		}
		if ( strlen( json_encode( $args ) ) > 8000 ) {
			throw new RuntimeException( 'Action arguments exceed the real store limit.' );
		}
		$id = count( ActionScheduler::store()->actions ) + 1;
		ActionScheduler::store()->actions[ $id ] = new DeliveryAction( $args, $when );
		return $id;
	}
	function wp_safe_remote_request( $url, $request ) {
		$GLOBALS['requests'][] = array( $url, $request );
		$identity = $request['headers']['X-WC-Webhook-Delivery-ID'] . '|' . hash( 'sha256', $request['body'] );
		if ( $GLOBALS['accept'] ) {
			$GLOBALS['accepted'][ $identity ] = true;
		}
		return $GLOBALS['response'];
	}

	require dirname( __DIR__, 2 ) . '/includes/class-activity.php';

	function check( $label, $condition ) {
		if ( ! $condition ) {
			throw new RuntimeException( 'FAIL ' . $label );
		}
		echo 'PASS ' . $label . "\n";
	}
	function reset_delivery() {
		ActionScheduler::$store = new DeliveryStore();
		$GLOBALS['webhook'] = new WC_Webhook();
		$GLOBALS['options'] = array();
		$GLOBALS['autoload'] = array();
		$GLOBALS['requests'] = array();
		$GLOBALS['accepted'] = array();
		$GLOBALS['accept'] = false;
		$GLOBALS['response'] = new WP_Error();
		$GLOBALS['schedule_failure'] = false;
		\Ecomiq\WooCommerce\Connection::$connected = true;
	}
	function request( $body = '{"id":17}', $delivery_id = 'delivery-42' ) {
		return array(
			'method' => 'POST',
			'timeout' => 60,
			'headers' => array(
				'X-WC-Webhook-Delivery-ID' => $delivery_id,
				'X-WC-Webhook-Signature' => $GLOBALS['webhook']->generate_signature( $body ),
				'X-WC-Webhook-Topic' => 'product.updated',
			),
			'body' => $body,
		);
	}
	function execute_action( $id ) {
		$action = ActionScheduler::store()->fetch_action( $id );
		if ( 'pending' !== $action->status ) {
			return;
		}
		$action->status = 'in-progress';
		try {
			call_user_func_array( array( '\Ecomiq\WooCommerce\Webhook_Delivery', 'retry' ), array_values( $action->args ) );
			$action->status = 'complete';
		} catch ( Exception $error ) {
			$action->status = 'failed';
		}
	}

	if ( isset( $argv[1] ) && 'resume' === $argv[1] ) {
		$data = unserialize( file_get_contents( $argv[2] ), array( 'allowed_classes' => true ) );
		ActionScheduler::$store = $data['store'];
		$GLOBALS['options'] = $data['options'];
		$GLOBALS['webhook'] = $data['webhook'];
		$GLOBALS['requests'] = array();
		$GLOBALS['accepted'] = array();
		$GLOBALS['accept'] = true;
		$GLOBALS['response'] = array( 'response' => array( 'code' => 200 ) );
		execute_action( 1 );
		check( 'pending delivery survives another PHP process', 'complete' === ActionScheduler::store()->get_status( 1 ) && 1 === count( $GLOBALS['requests'] ) );
		check( 'original large body and signature survive restart', $data['request'] === $GLOBALS['requests'][0][1] );
		check( 'successful retry removes the retained envelope', array() === array_filter( array_keys( $GLOBALS['options'] ), function ( $key ) { return 0 === strpos( $key, \Ecomiq\WooCommerce\Webhook_Delivery::OPTION_PREFIX ); } ) );
		exit( 0 );
	}

	reset_delivery();
	$initial = request();
	\Ecomiq\WooCommerce\Activity::delivery( $initial, new WP_Error(), 10, 17, 42 );
	\Ecomiq\WooCommerce\Activity::delivery( $initial, new WP_Error(), 10, 17, 42 );
	check( 'duplicate failure notification schedules one retry', 1 === count( ActionScheduler::store()->actions ) );
	$delays = array( 30, 60, 120, 300, 900 );
	foreach ( $delays as $index => $delay ) {
		$id = $index + 1;
		check( 'retry delay ' . $id, abs( ActionScheduler::store()->actions[ $id ]->when - time() - $delay ) <= 1 );
		execute_action( $id );
	}
	check( 'five additional attempts then terminal failure', 5 === count( $GLOBALS['requests'] ) && 5 === count( ActionScheduler::store()->actions ) && 'failed' === ActionScheduler::store()->get_status( 5 ) );
	check( 'original request and signature are preserved at every attempt', array( $initial ) === array_values( array_unique( array_column( $GLOBALS['requests'], 1 ), SORT_REGULAR ) ) );
	\Ecomiq\WooCommerce\Activity::delivery( $initial, new WP_Error(), 10, 17, 42 );
	check( 'duplicate initial notification cannot reset exhausted retries', 5 === count( ActionScheduler::store()->actions ) );
	$recovery = \Ecomiq\WooCommerce\Webhook_Delivery::recover( 5 );
	check( 'recovery request is idempotent', $recovery === \Ecomiq\WooCommerce\Webhook_Delivery::recover( 5 ) );
	$GLOBALS['response'] = array( 'response' => array( 'code' => 200 ) );
	$GLOBALS['accept'] = true;
	execute_action( $recovery );
	check( 'explicit recovery delivers once and clears payload', 'complete' === ActionScheduler::store()->get_status( $recovery ) && 6 === count( $GLOBALS['requests'] ) && 1 === count( $GLOBALS['accepted'] ) );
	execute_action( $recovery );
	check( 'completed action cannot deliver twice', 6 === count( $GLOBALS['requests'] ) );
	$completed_key = ActionScheduler::store()->actions[1]->args['delivery_key'];
	\Ecomiq\WooCommerce\Activity::delivery( $initial, new WP_Error(), 10, 17, 42 );
	check( 'late duplicate failure cannot recreate a completed payload', false === get_option( \Ecomiq\WooCommerce\Webhook_Delivery::OPTION_PREFIX . $completed_key ) && 6 === count( ActionScheduler::store()->actions ) );

	reset_delivery();
	\Ecomiq\WooCommerce\Activity::delivery( request(), array( 'response' => array( 'code' => 400 ) ), 0.1, 17, 42 );
	check( 'permanent rejection is retained without an automatic HTTP retry', 'failed' === ActionScheduler::store()->get_status( 1 ) && array() === $GLOBALS['requests'] );
	$recovery = \Ecomiq\WooCommerce\Webhook_Delivery::recover( 1 );
	execute_action( $recovery );
	check( 'failed explicit recovery does not start another retry budget', 'failed' === ActionScheduler::store()->get_status( $recovery ) && 2 === count( ActionScheduler::store()->actions ) );

	foreach ( array( 408, 425, 429, 500, 503 ) as $status ) {
		reset_delivery();
		\Ecomiq\WooCommerce\Activity::delivery( request(), array( 'response' => array( 'code' => $status ) ), 0.1, 17, 42 );
		check( 'transient HTTP ' . $status . ' is scheduled', 'pending' === ActionScheduler::store()->get_status( 1 ) );
	}

	reset_delivery();
	\Ecomiq\WooCommerce\Activity::delivery( request( '{"id":17}' ), new WP_Error(), 10, 17, 42 );
	\Ecomiq\WooCommerce\Activity::delivery( request( '{"id":18}' ), new WP_Error(), 10, 18, 42 );
	check( 'same Woo delivery ID with a different body remains distinct', 2 === count( ActionScheduler::store()->actions ) );
	$GLOBALS['webhook']->secret = 'rotated-secret';
	execute_action( 1 );
	check( 'secret rotation stops replay of an old delivery', 'failed' === ActionScheduler::store()->get_status( 1 ) && array() === $GLOBALS['requests'] );

	reset_delivery();
	$initial = request();
	$GLOBALS['accept'] = true;
	wp_safe_remote_request( $GLOBALS['webhook']->get_delivery_url(), $initial );
	\Ecomiq\WooCommerce\Activity::delivery( $initial, new WP_Error(), 10, 17, 42 );
	$GLOBALS['response'] = array( 'response' => array( 'code' => 200 ) );
	execute_action( 1 );
	check( 'ambiguous timeout reuses identity for receiver deduplication', 2 === count( $GLOBALS['requests'] ) && 1 === count( $GLOBALS['accepted'] ) );

	reset_delivery();
	$initial = request();
	\Ecomiq\WooCommerce\Activity::delivery( $initial, new WP_Error(), 10, 17, 42 );
	$action = ActionScheduler::store()->actions[1];
	$action->status = 'in-progress';
	$GLOBALS['response'] = array( 'response' => array( 'code' => 200 ) );
	call_user_func_array( array( '\Ecomiq\WooCommerce\Webhook_Delivery', 'retry' ), array_values( $action->args ) );
	\Ecomiq\WooCommerce\Activity::delivery( $initial, new WP_Error(), 10, 17, 42 );
	check( 'duplicate cannot recreate payload before successful action is marked complete', 'in-progress' === $action->status && false === get_option( \Ecomiq\WooCommerce\Webhook_Delivery::OPTION_PREFIX . $action->args['delivery_key'] ) && 1 === count( ActionScheduler::store()->actions ) );

	reset_delivery();
	$GLOBALS['schedule_failure'] = true;
	$failed = false;
	$orphan_original = request();
	try {
		\Ecomiq\WooCommerce\Activity::delivery( $orphan_original, new WP_Error(), 10, 17, 42 );
	} catch ( RuntimeException $error ) {
		$failed = true;
	}
	$names = array_values( array_filter( array_keys( $GLOBALS['options'] ), function ( $name ) { return 0 === strpos( $name, \Ecomiq\WooCommerce\Webhook_Delivery::OPTION_PREFIX ); } ) );
	check( 'scheduling failure retains the original delivery', $failed && 1 === count( $names ) && array() === ActionScheduler::store()->actions );
	$GLOBALS['schedule_failure'] = false;
	$delivery_key = substr( $names[0], strlen( \Ecomiq\WooCommerce\Webhook_Delivery::OPTION_PREFIX ) );
	$recovery = \Ecomiq\WooCommerce\Webhook_Delivery::recover_delivery( $delivery_key );
	$GLOBALS['response'] = array( 'response' => array( 'code' => 200 ) );
	execute_action( $recovery );
	check( 'retained delivery without an action is explicitly recoverable', 'complete' === ActionScheduler::store()->get_status( $recovery ) && false === get_option( $names[0] ) );
	\Ecomiq\WooCommerce\Activity::delivery( $orphan_original, new WP_Error(), 10, 17, 42 );
	check( 'late original failure cannot recreate an explicitly recovered orphan', false === get_option( $names[0] ) && 1 === count( ActionScheduler::store()->actions ) );

	reset_delivery();
	$initial = request( json_encode( array( 'id' => 17, 'description' => str_repeat( 'large fixture ', 10000 ) ) ) );
	\Ecomiq\WooCommerce\Activity::delivery( $initial, new WP_Error(), 10, 17, 42 );
	check( 'large payload is stored once without autoload', strlen( $initial['body'] ) > 8000 && 1 === count( $GLOBALS['autoload'] ) && false === reset( $GLOBALS['autoload'] ) && strlen( json_encode( ActionScheduler::store()->actions[1]->args ) ) < 191 );
	$file = tempnam( sys_get_temp_dir(), 'ecomiq-webhook-restart-' );
	file_put_contents( $file, serialize( array( 'store' => ActionScheduler::store(), 'options' => $GLOBALS['options'], 'webhook' => $GLOBALS['webhook'], 'request' => $initial ) ) );
	$process = proc_open( array( PHP_BINARY, __FILE__, 'resume', $file ), array( 0 => STDIN, 1 => STDOUT, 2 => STDERR ), $pipes );
	$result = proc_close( $process );
	unlink( $file );
	check( 'restart test process passed', 0 === $result );
}
