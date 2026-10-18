<?php
use Ecomiq\WooCommerce\Admin;
use Ecomiq\WooCommerce\Activity;
use Ecomiq\WooCommerce\Authorization;
use Ecomiq\WooCommerce\Connection;

$GLOBALS['ecomiq_failed'] = 0;

function ecomiq_check( $label, $ok ) {
	if ( ! $ok ) {
		++$GLOBALS['ecomiq_failed'];
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
}

wp_set_current_user( 1 );
set_current_screen( 'dashboard' );

$status = rest_do_request( new WP_REST_Request( 'GET', '/ecomiq/v1/status' ) );
ecomiq_check( 'status 200', 200 === $status->get_status() && '1.0.0' === $status->get_data()['plugin'] );

ob_start();
Admin::render();
$html = ob_get_clean();
ecomiq_check( 'connect page renders', false !== strpos( $html, 'ecomiq-card' ) );

$verifier  = str_repeat( 'a', 43 );
$fields    = Authorization::fields(
	array(
		'redirect_uri'          => Authorization::redirect_uri(),
		'state'                 => 'state-1',
		'code_challenge'        => Authorization::challenge( $verifier ),
		'code_challenge_method' => 'S256',
		'store'                 => 'Fresh',
	)
);
$request   = Authorization::request( $fields );
ecomiq_check( 'authorize request valid', is_array( $request ) );
$code      = Authorization::issue( $request, 1 );
$token_req = new WP_REST_Request( 'POST', '/ecomiq/v1/token' );
$token_req->set_body_params(
	array(
		'code'          => $code,
		'code_verifier' => $verifier,
		'redirect_uri'  => Authorization::redirect_uri(),
		'store_name'    => 'Fresh',
		'store_url'     => Connection::server() . '/store/1',
	)
);
$token = rest_do_request( $token_req );
ecomiq_check( 'token 200', 200 === $token->get_status() && 0 === strpos( $token->get_data()['consumer_key'], 'ck_' ) );
$replay = rest_do_request( $token_req );
ecomiq_check( 'code single use', 400 === $replay->get_status() );
ecomiq_check( 'connected', null !== Connection::current() );

$webhook = new WC_Webhook();
$webhook->set_name( 'ecomiq-product.updated' );
$webhook->set_topic( 'product.updated' );
$webhook->set_delivery_url( 'https://example.test/webhooks/woocommerce/1' );
$webhook->set_status( 'active' );
$webhook->set_user_id( 1 );
$webhook->save();

$events = Activity::events();
ecomiq_check( 'activity has connected', ! empty( $events ) && 'connected' === $events[0]['type'] );

ob_start();
Admin::render();
$html = ob_get_clean();
ecomiq_check( 'connected page renders', false !== strpos( $html, 'ecomiq-activity' ) );

set_current_screen( 'plugins' );
ob_start();
Admin::activation_notice();
ecomiq_check( 'no notice when connected', '' === ob_get_clean() );

Connection::disconnect();
ecomiq_check( 'disconnected', null === Connection::current() );
ecomiq_check( 'webhook removed', ! wc_get_webhook( $webhook->get_id() ) || 0 === $webhook->get_id() || ! get_post( $webhook->get_id() ) );

ob_start();
Admin::activation_notice();
ecomiq_check( 'notice when not connected', false !== strpos( ob_get_clean(), 'notice-info' ) );

update_user_meta( 1, Admin::DISMISSED_META, 1 );
$webhook = new WC_Webhook();
$webhook->set_name( 'ecomiq-order.created' );
$webhook->set_topic( 'order.created' );
$webhook->set_delivery_url( 'https://example.test/webhooks/woocommerce/1' );
$webhook->set_user_id( 1 );
$webhook->save();
$code = Authorization::issue( $request, 1 );
$token_req->set_param( 'code', $code );
ecomiq_check( 'reconnect for uninstall', 200 === rest_do_request( $token_req )->get_status() );

if ( $GLOBALS['ecomiq_failed'] > 0 ) {
	exit( 1 );
}
