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
$webhook->set_secret( 'fresh-secret' );
$webhook->set_status( 'active' );
$webhook->set_user_id( 1 );
$webhook->save();
$sibling = new WC_Webhook();
$sibling->set_name( 'ecomiq-order.created' );
$sibling->set_topic( 'order.created' );
$sibling->set_delivery_url( 'https://example.test/webhooks/woocommerce/1' );
$sibling->set_secret( 'fresh-secret' );
$sibling->set_status( 'active' );
$sibling->set_user_id( 1 );
$sibling->save();

function ecomiq_release_notices() {
	$ids     = as_get_scheduled_actions(
		array(
			'hook'     => Connection::RELEASE_HOOK,
			'status'   => ActionScheduler_Store::STATUS_PENDING,
			'per_page' => 10,
		),
		'ids'
	);
	$notices = array();
	foreach ( $ids as $id ) {
		$notices[] = ActionScheduler::store()->fetch_action( $id )->get_args();
	}
	return $notices;
}

function ecomiq_signed( $notice, $reason ) {
	$body = json_decode( $notice[1], true );
	return 'https://example.test/webhooks/woocommerce/1' === $notice[0]
		&& $reason === $body['reason']
		&& base64_encode( hash_hmac( 'sha256', $notice[1], 'fresh-secret', true ) ) === $notice[2]
		&& false === strpos( wp_json_encode( $notice ), 'fresh-secret' );
}

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

$sent    = array();
$capture = function ( $pre, $args, $url ) use ( &$sent ) {
	$sent[] = array( $url, $args['body'], $args['headers']['X-WC-Webhook-Signature'], $args['headers']['X-WC-Webhook-Topic'] );
	return array(
		'headers'  => array(),
		'body'     => '',
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'cookies'  => array(),
	);
};
add_filter( 'pre_http_request', $capture, 10, 3 );
Connection::disconnect();
remove_filter( 'pre_http_request', $capture, 10 );
ecomiq_check( 'disconnected', null === Connection::current() );
ecomiq_check( 'webhooks removed', ! wc_get_webhook( $webhook->get_id() ) && ! wc_get_webhook( $sibling->get_id() ) );
ecomiq_check( 'one signed notice per address, sent at once', 1 === count( $sent ) && ecomiq_signed( $sent[0], 'disconnected' ) && Connection::RELEASED_TOPIC === $sent[0][3] && array() === ecomiq_release_notices() );

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
ecomiq_check( 'reconnect', 200 === rest_do_request( $token_req )->get_status() );

$replaced = new WC_Webhook();
$replaced->set_name( 'ecomiq-product.created' );
$replaced->set_topic( 'product.created' );
$replaced->set_delivery_url( 'https://example.test/webhooks/woocommerce/1' );
$replaced->set_secret( 'fresh-secret' );
$replaced->set_user_id( 1 );
$replaced->save();
$code = Authorization::issue( $request, 1 );
$token_req->set_param( 'code', $code );
ecomiq_check( 'reconnect for uninstall', 200 === rest_do_request( $token_req )->get_status() );
$notices = ecomiq_release_notices();
ecomiq_check( 'replacing releases the old webhooks', ! wc_get_webhook( $replaced->get_id() ) && 1 === count( $notices ) && ecomiq_signed( $notices[0], 'replaced' ) );

if ( $GLOBALS['ecomiq_failed'] > 0 ) {
	exit( 1 );
}
