<?php
use Ecomiq\WooCommerce\Admin;
use Ecomiq\WooCommerce\Activity;
use Ecomiq\WooCommerce\Authorization;
use Ecomiq\WooCommerce\Connection;
use Ecomiq\WooCommerce\Shipping;

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
ecomiq_check( 'status 200', 200 === $status->get_status() && \Ecomiq\WooCommerce\VERSION === $status->get_data()['plugin'] );

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

add_filter( 'woocommerce_set_cookie_enabled', '__return_false' );
$zone = new WC_Shipping_Zone();
$zone->set_zone_name( 'Peru' );
$zone->add_location( 'PE', 'country' );
$zone->save();
$instance = $zone->add_shipping_method( Shipping::METHOD_ID );
update_option(
	'woocommerce_' . Shipping::METHOD_ID . '_' . $instance . '_settings',
	array(
		'fallback_cost'  => '15',
		'fallback_title' => 'Standard',
	)
);
$products = array();
foreach ( array( 'FRESH-A', 'FRESH-B', 'FRESH-C' ) as $sku ) {
	$product = new WC_Product_Simple();
	$product->set_name( $sku );
	$product->set_sku( $sku );
	$product->set_regular_price( '50' );
	$product->save();
	$products[ $sku ] = $product;
}
$quotes      = array();
$quote_fails = false;
$quote_http  = function ( $pre, $args, $url ) use ( &$quotes, &$quote_fails ) {
	if ( 'https://example.test/webhooks/woocommerce/1/shipping-rates' !== $url ) {
		return $pre;
	}
	$body     = json_decode( $args['body'], true );
	$quotes[] = array(
		'body'   => $body,
		'signed' => base64_encode( hash_hmac( 'sha256', $args['body'], 'fresh-secret', true ) ) === $args['headers']['X-WC-Webhook-Signature'],
	);
	if ( $quote_fails ) {
		return new WP_Error( 'http_request_failed', 'Fixture timeout.' );
	}
	$sellers = array(
		'FRESH-A' => array( 'Andes', array( array( '{"sellerId":1,"shippingMethodId":7}', 'Urbano', 12.5 ) ) ),
		'FRESH-B' => array( 'Lima', array( array( '{"sellerId":2,"shippingMethodId":8}', 'Olva', 9.9 ), array( '{"sellerId":2,"shippingMethodId":9}', 'Express', 20 ) ) ),
	);
	$groups  = array();
	foreach ( $body['items'] as $item ) {
		if ( ! isset( $sellers[ $item['sku'] ] ) ) {
			continue;
		}
		$rates = array();
		foreach ( $sellers[ $item['sku'] ][1] as $rate ) {
			$rates[] = array(
				'reference' => $rate[0],
				'name'      => $rate[1],
				'price'     => $rate[2],
			);
		}
		$groups[] = array(
			'sellerId'   => '1',
			'sellerName' => $sellers[ $item['sku'] ][0],
			'items'      => array( $item['key'] ),
			'rates'      => $rates,
		);
	}
	return array(
		'headers'  => array(),
		'body'     => wp_json_encode( array( 'groups' => $groups ) ),
		'response' => array(
			'code'    => 200,
			'message' => 'OK',
		),
		'cookies'  => array(),
	);
};
add_filter( 'pre_http_request', $quote_http, 10, 3 );
wc_load_cart();
WC()->customer->set_billing_country( 'PE' );
WC()->customer->set_shipping_country( 'PE' );
WC()->customer->set_shipping_state( 'ARE' );
WC()->customer->set_shipping_city( 'Arequipa' );
$keys = array();
foreach ( $products as $sku => $product ) {
	$keys[ $sku ] = WC()->cart->add_to_cart( $product->get_id(), 2 );
}
WC()->cart->calculate_totals();
$packages = WC()->shipping()->get_packages();
$asked    = count( $quotes );
$last     = end( $quotes );
$sent     = $last['body'];
ecomiq_check( 'quote is signed with the webhook secret', $last['signed'] );
ecomiq_check( 'quote sends the cart items and destination', 3 === count( $sent['items'] ) && 'FRESH-A' === $sent['items'][0]['sku'] && 2 === $sent['items'][0]['quantity'] && 50.0 === (float) $sent['items'][0]['price'] && 'ARE' === $sent['destination']['state'] && 'Arequipa' === $sent['destination']['stateName'] );
ecomiq_check( 'one package per seller plus the rest', 3 === count( $packages ) && array( $keys['FRESH-A'] ) === array_keys( $packages[0]['contents'] ) && array( $keys['FRESH-C'] ) === array_keys( $packages[2]['contents'] ) );
ecomiq_check( 'packages named after the seller', 'Shipping from Lima' === apply_filters( 'woocommerce_shipping_package_name', 'Shipping', 1, $packages[1] ) );
$rate_a = current( $packages[0]['rates'] );
ecomiq_check( 'seller rates with their reference', 1 === count( $packages[0]['rates'] ) && 'Urbano' === $rate_a->get_label() && 12.5 === (float) $rate_a->get_cost() && '{"sellerId":1,"shippingMethodId":7}' === $rate_a->get_meta_data()[ Shipping::RATE_META ] && 2 === count( $packages[1]['rates'] ) );
ecomiq_check( 'items Ecomiq does not sell get no Ecomiq rate', array() === $packages[2]['rates'] );
WC()->cart->calculate_totals();
ecomiq_check( 'quote reused while the cart does not change', $asked === count( $quotes ) );

WC()->cart->remove_cart_item( $keys['FRESH-C'] );
WC()->cart->calculate_totals();
$packages = WC()->shipping()->get_packages();
WC()->session->set( 'chosen_shipping_methods', array( $rate_a->get_id(), key( $packages[1]['rates'] ) ) );
WC()->cart->calculate_totals();
$order_id = WC()->checkout()->create_order(
	array(
		'payment_method' => 'cod',
		'billing_email'  => 'buyer@example.test',
	)
);
$rest     = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/orders/' . $order_id ) )->get_data();
$lines    = array();
foreach ( $rest['shipping_lines'] as $line ) {
	foreach ( $line['meta_data'] as $meta ) {
		if ( Shipping::RATE_META === $meta['key'] ) {
			$lines[] = array( $line['method_title'], $line['total'], $meta['value'] );
		}
	}
}
ecomiq_check( 'order REST has one shipping line per seller with its reference', array( array( 'Urbano', '12.50', '{"sellerId":1,"shippingMethodId":7}' ), array( 'Olva', '9.90', '{"sellerId":2,"shippingMethodId":8}' ) ) === $lines && '22.40' === $rest['shipping_total'] );

$quote_fails = true;
WC()->cart->set_quantity( $keys['FRESH-A'], 3 );
WC()->cart->calculate_totals();
$packages = WC()->shipping()->get_packages();
$fallback = current( $packages[0]['rates'] );
ecomiq_check( 'fallback rate when Ecomiq does not answer', 1 === count( $packages ) && 1 === count( $packages[0]['rates'] ) && 'Standard' === $fallback->get_label() && 15.0 === (float) $fallback->get_cost() );
ecomiq_check( 'failed quote in activity', 'quote_failed' === Activity::events()[0]['type'] );

$asked = count( $quotes );
WC()->customer->set_shipping_country( 'CL' );
WC()->cart->calculate_totals();
ecomiq_check( 'no quote outside Ecomiq zones', $asked === count( $quotes ) && 1 === count( WC()->shipping()->get_packages() ) );

remove_filter( 'pre_http_request', $quote_http, 10 );
WC()->cart->empty_cart();
wc_get_order( $order_id )->delete( true );
foreach ( $products as $product ) {
	$product->delete( true );
}
$zone->delete();

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
