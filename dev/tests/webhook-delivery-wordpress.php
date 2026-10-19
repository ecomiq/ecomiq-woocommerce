<?php

use Ecomiq\WooCommerce\Activity;
use Ecomiq\WooCommerce\Connection;
use Ecomiq\WooCommerce\Webhook_Delivery;

global $wpdb;

if ( 'local' !== wp_get_environment_type() || null === Connection::current() ) {
	throw new RuntimeException( 'Run only in a connected local WordPress fixture.' );
}

$store = ActionScheduler::store();
$fixture = wp_generate_uuid4();
$response = new WP_Error( 'http_request_failed', 'Fixture connection timeout.' );
$requests = array();
$options = array();
$actions = array();
$events_before = get_option( Activity::OPTION, false );
$hook = new WC_Webhook();
$hook->set_name( 'ecomiq-delivery-smoke-' . $fixture );
$hook->set_topic( 'product.updated' );
$hook->set_delivery_url( 'https://ecomiq-webhook.invalid/callback' );
$hook->set_secret( 'fixture-secret-' . $fixture );
$hook->set_status( 'active' );

$capture = function ( $pre, $args, $url ) use ( &$requests, &$response, $hook ) {
	if ( $url === $hook->get_delivery_url() ) {
		$requests[] = array( $url, $args );
	}
	return $response;
};
$check = function ( $name, $condition ) {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL ' . $name );
	}
	echo 'PASS ' . $name . "\n";
};
$request = function ( $body, $id ) use ( $hook ) {
	return array(
		'method' => 'POST',
		'timeout' => 60,
		'user-agent' => 'Ecomiq delivery fixture',
		'headers' => array(
			'Content-Type' => 'application/json',
			'X-WC-Webhook-Delivery-ID' => $id,
			'X-WC-Webhook-ID' => $hook->get_id(),
			'X-WC-Webhook-Topic' => $hook->get_topic(),
			'X-WC-Webhook-Signature' => $hook->generate_signature( $body ),
		),
		'body' => $body,
	);
};
$key = function ( $request ) use ( $hook, &$options ) {
	$value = hash( 'sha256', $hook->get_id() . '|' . $request['headers']['X-WC-Webhook-Delivery-ID'] . '|' . hash( 'sha256', $request['body'] ) );
	$options[] = Webhook_Delivery::OPTION_PREFIX . $value;
	return $value;
};
$action = function ( $key, $attempt, $recovery = 0 ) use ( &$actions, $store ) {
	$ids = $store->query_actions( array( 'hook' => Webhook_Delivery::HOOK, 'group' => Webhook_Delivery::GROUP, 'args' => array( 'delivery_key' => $key, 'attempt' => $attempt, 'recovery_of' => $recovery ), 'per_page' => 1 ) );
	if ( empty( $ids ) ) {
		throw new RuntimeException( 'Fixture action was not persisted.' );
	}
	$actions[] = (int) $ids[0];
	return (int) $ids[0];
};

add_filter( 'pre_http_request', $capture, PHP_INT_MAX, 3 );
try {
	$hook->save();
	$fixture_webhook_id = $hook->get_id();
	$hook->set_pending_delivery( false );
	$hook->save();
	$requests = array();
	$body = wp_json_encode( array( 'id' => 17, 'description' => str_repeat( 'large fixture ', 10000 ) ) );
	$original = $request( $body, $fixture . '-transient' );
	$delivery_key = $key( $original );
	Activity::delivery( $original, $response, 10, 17, $hook->get_id() );
	$id = $action( $delivery_key, 1 );
	$check( 'real Action Scheduler stores compact arguments for a large payload', strlen( $body ) > 8000 && strlen( wp_json_encode( $store->fetch_action( $id )->get_args() ) ) < 191 && 'pending' === $store->get_status( $id ) );
	Activity::delivery( $original, $response, 10, 17, $hook->get_id() );
	$check( 'real queue deduplicates repeated failure notifications', $id === $action( $delivery_key, 1 ) );
	for ( $attempt = 1; $attempt <= 5; ++$attempt ) {
		$id = $action( $delivery_key, $attempt );
		ActionScheduler::runner()->process_action( $id, 'Ecomiq delivery fixture' );
	}
	$check( 'five real queue attempts end in Failed with payload retained', 5 === count( $requests ) && 'failed' === $store->get_status( $id ) && is_array( get_option( Webhook_Delivery::OPTION_PREFIX . $delivery_key ) ) );
	$check( 'all real attempts preserve original signature, body and delivery ID', count( array_filter( $requests, function ( $sent ) use ( $original ) { return $sent[1]['body'] === $original['body'] && $sent[1]['headers'] === $original['headers']; } ) ) === 5 );
	$recovery = Webhook_Delivery::recover( $id );
	$actions[] = $recovery;
	$check( 'real explicit recovery is idempotent and pending', $recovery === Webhook_Delivery::recover( $id ) && 'pending' === $store->get_status( $recovery ) );
	$response = array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array() );
	ActionScheduler::runner()->process_action( $recovery, 'Ecomiq delivery fixture' );
	$check( 'real recovery succeeds and deletes the retained body', 'complete' === $store->get_status( $recovery ) && false === get_option( Webhook_Delivery::OPTION_PREFIX . $delivery_key ) && 6 === count( $requests ) );
	ActionScheduler::runner()->process_action( $recovery, 'Ecomiq delivery fixture' );
	$check( 'real completed action is not delivered again', 6 === count( $requests ) );
	Activity::delivery( $original, new WP_Error( 'http_request_failed', 'Fixture late timeout.' ), 10, 17, $hook->get_id() );
	$check( 'real history prevents a late failure from retaining a completed payload', false === get_option( Webhook_Delivery::OPTION_PREFIX . $delivery_key ) && 6 === count( $requests ) );

	$response = array( 'headers' => array(), 'body' => '', 'response' => array( 'code' => 400, 'message' => 'Bad Request' ), 'cookies' => array() );
	$original = $request( '{"id":18}', $fixture . '-permanent' );
	$delivery_key = $key( $original );
	Activity::delivery( $original, $response, 0.1, 18, $hook->get_id() );
	$id = $action( $delivery_key, 5 );
	$check( 'real permanent error is retained without automatic resend', 'failed' === $store->get_status( $id ) && 6 === count( $requests ) );
	$recovery = Webhook_Delivery::recover( $id );
	$actions[] = $recovery;
	ActionScheduler::runner()->process_action( $recovery, 'Ecomiq delivery fixture' );
	$check( 'real rejected manual recovery stays Failed', 'failed' === $store->get_status( $recovery ) && 7 === count( $requests ) );
} finally {
	foreach ( array_unique( $actions ) as $id ) {
		$store->delete_action( $id );
	}
	foreach ( array_unique( $options ) as $name ) {
		delete_option( $name );
	}
	as_unschedule_all_actions( Activity::REACTIVATE_HOOK, array( 'webhook_id' => $hook->get_id() ), Activity::SOURCE );
	$hook->delete( true );
	if ( false === $events_before ) {
		delete_option( Activity::OPTION );
	} else {
		update_option( Activity::OPTION, $events_before, false );
	}
	remove_filter( 'pre_http_request', $capture, PHP_INT_MAX );
}

$remaining_options = array_filter( array_unique( $options ), function ( $name ) { return false !== get_option( $name ); } );
$ids = implode( ',', array_unique( array_map( 'intval', $actions ) ) );
$remaining_actions = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE action_id IN ($ids)" );
$remaining_webhook = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wc_webhooks WHERE webhook_id = %d", $fixture_webhook_id ) );
$check( 'fixture webhook, retained options and queue actions are removed', 0 === $remaining_webhook && array() === $remaining_options && 0 === $remaining_actions );
