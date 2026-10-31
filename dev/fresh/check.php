<?php
use Automattic\WooCommerce\Utilities\OrderUtil;
use Ecomiq\WooCommerce\Admin;
use Ecomiq\WooCommerce\Activity;
use Ecomiq\WooCommerce\Authorization;
use Ecomiq\WooCommerce\Connection;
use Ecomiq\WooCommerce\Diagnostics;
use Ecomiq\WooCommerce\Fulfillments;
use Ecomiq\WooCommerce\Order_Parts;
use Ecomiq\WooCommerce\Seal;
use Ecomiq\WooCommerce\Shipping;

$GLOBALS['ecomiq_failed'] = 0;
$ecomiq_section           = isset( $args[0] ) ? $args[0] : '';

function ecomiq_check( $label, $ok ) {
	if ( ! $ok ) {
		++$GLOBALS['ecomiq_failed'];
	}
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
}

function ecomiq_line_seals( $order_id ) {
	$order = rest_do_request( new WP_REST_Request( 'GET', '/wc/v3/orders/' . (int) $order_id ) )->get_data();
	if ( ! isset( $order['line_items'] ) ) {
		return array();
	}
	$seals = array();
	foreach ( $order['line_items'] as $line ) {
		$product           = $line['variation_id'] ? $line['variation_id'] : $line['product_id'];
		$seals[ $product ] = null;
		foreach ( $line['meta_data'] as $meta ) {
			if ( Seal::META === $meta['key'] ) {
				$seals[ $product ] = $meta['value'];
			}
		}
	}
	ksort( $seals );
	return $seals;
}

function ecomiq_order_box( $order_id ) {
	global $wp_meta_boxes;
	$wp_meta_boxes = array();
	$screen        = 'shop_order';
	$subject       = get_post( $order_id );
	if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
		$screen  = 'woocommerce_page_wc-orders';
		$subject = wc_get_order( $order_id );
	}
	do_action( 'add_meta_boxes', $screen, $subject );
	if ( empty( $wp_meta_boxes[ $screen ]['side']['high'][ Order_Parts::BOX ] ) ) {
		return null;
	}
	$box = $wp_meta_boxes[ $screen ]['side']['high'][ Order_Parts::BOX ];
	ob_start();
	call_user_func( $box['callback'], $subject, $box );
	return ob_get_clean();
}

function ecomiq_order_lines( $order_id ) {
	global $post, $theorder, $thepostid;
	$theorder  = wc_get_order( $order_id );
	$thepostid = $order_id;
	$post      = null;
	if ( ! OrderUtil::custom_orders_table_usage_is_enabled() ) {
		$post = get_post( $order_id );
	}
	ob_start();
	WC_Meta_Box_Order_Items::output( $post ? $post : $theorder );
	$html      = ob_get_clean();
	$theorder  = null;
	$thepostid = null;
	$post      = null;
	$lines     = array();
	foreach ( array_slice( explode( 'data-order_item_id="', $html ), 1 ) as $row ) {
		$lines[ (int) $row ] = $row;
	}
	return $lines;
}

function ecomiq_fulfillments_row() {
	ob_start();
	Admin::render();
	$html  = ob_get_clean();
	$label = strpos( $html, 'Envíos parciales de WooCommerce' );
	if ( false === $label ) {
		return '';
	}
	$start = strrpos( substr( $html, 0, $label ), '<tr' );
	return substr( $html, $start, strpos( $html, '</tr>', $label ) - $start );
}

function ecomiq_press_fulfillments() {
	$stop                 = function ( $location ) {
		throw new RuntimeException( $location );
	};
	$location             = '';
	$_REQUEST['_wpnonce'] = wp_create_nonce( 'ecomiq_fulfillments' );
	add_filter( 'wp_redirect', $stop, 1 );
	try {
		Admin::fulfillments();
	} catch ( RuntimeException $redirect ) {
		$location = $redirect->getMessage();
	}
	remove_filter( 'wp_redirect', $stop, 1 );
	unset( $_REQUEST['_wpnonce'] );
	return $location;
}

function ecomiq_check_orders() {
	$mode = 'posts';
	if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
		$mode = 'HPOS';
	}
	$no_http = function () {
		return new WP_Error( 'http_request_failed', 'No network in the check.' );
	};
	$cod     = function ( $gateways ) {
		$gateways['cod'] = WC()->payment_gateways()->payment_gateways()['cod'];
		return $gateways;
	};
	add_filter( 'pre_http_request', $no_http );
	add_filter( 'pre_wp_mail', '__return_false' );
	add_filter( 'woocommerce_set_cookie_enabled', '__return_false' );
	add_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
	add_filter( 'woocommerce_available_payment_gateways', $cod );

	$seal        ='{"v":1,"store":"7","listing":"70","product":"700"}';
	$parent_seal = '{"v":1,"store":"7","listing":"71","product":"701"}';
	$sealed      = new WC_Product_Simple();
	$sealed->set_name( 'Polo azul' );
	$sealed->set_regular_price( '20' );
	$sealed->set_virtual( true );
	$sealed->update_meta_data( Seal::META, $seal );
	$sealed->save();
	$size = new WC_Product_Attribute();
	$size->set_name( 'Talla' );
	$size->set_options( array( 'S', 'M' ) );
	$size->set_visible( true );
	$size->set_variation( true );
	$parent = new WC_Product_Variable();
	$parent->set_name( 'Gorra' );
	$parent->set_attributes( array( $size ) );
	$parent->update_meta_data( Seal::META, $parent_seal );
	$parent->save();
	$variation = new WC_Product_Variation();
	$variation->set_parent_id( $parent->get_id() );
	$variation->set_attributes( array( 'talla' => 'S' ) );
	$variation->set_regular_price( '30' );
	$variation->set_virtual( true );
	$variation->save();
	$own = new WC_Product_Simple();
	$own->set_name( 'Mochila' );
	$own->set_regular_price( '10' );
	$own->set_virtual( true );
	$own->save();
	$expected = array(
		$sealed->get_id()    => $seal,
		$variation->get_id() => $parent_seal,
		$own->get_id()       => null,
	);
	ksort( $expected );
	$fill = function () use ( $sealed, $parent, $variation, $own ) {
		WC()->cart->empty_cart();
		WC()->cart->add_to_cart( $sealed->get_id(), 2 );
		WC()->cart->add_to_cart( $parent->get_id(), 1, $variation->get_id(), array( 'attribute_talla' => 'S' ) );
		WC()->cart->add_to_cart( $own->get_id(), 1 );
		WC()->cart->calculate_totals();
	};
	$orders = array();

	wc_load_cart();
	WC()->customer->set_billing_country( 'PE' );
	$fill();
	$classic  = WC()->checkout()->create_order(
		array(
			'payment_method' => 'cod',
			'billing_email'  => 'buyer@example.test',
		)
	);
	$orders[] = $classic;
	ecomiq_check( "$mode: classic checkout copies the product seal to its lines, the parent's for a variation", is_int( $classic ) && $expected === ecomiq_line_seals( $classic ) );

	$fill();
	$checkout = new WP_REST_Request( 'POST', '/wc/store/v1/checkout' );
	$checkout->set_header( 'Content-Type', 'application/json' );
	$checkout->set_body(
		wp_json_encode(
			array(
				'billing_address' => array(
					'first_name' => 'Ana',
					'last_name'  => 'Pérez',
					'address_1'  => 'Av. Ejército 101',
					'city'       => 'Arequipa',
					'state'      => 'ARE',
					'postcode'   => '04001',
					'country'    => 'PE',
					'email'      => 'ana@example.test',
				),
				'payment_method'  => 'cod',
			)
		)
	);
	$blocks   = rest_do_request( $checkout );
	$block    = isset( $blocks->get_data()['order_id'] ) ? (int) $blocks->get_data()['order_id'] : 0;
	$orders[] = $block;
	ecomiq_check( "$mode: block checkout copies the seal to the lines", 200 === $blocks->get_status() && $expected === ecomiq_line_seals( $block ) );
	if ( 200 !== $blocks->get_status() ) {
		echo wp_json_encode( $blocks->get_data() ) . "\n";
	}

	$create = new WP_REST_Request( 'POST', '/wc/v3/orders' );
	$create->set_body_params(
		array(
			'line_items' => array(
				array(
					'product_id' => $sealed->get_id(),
					'quantity'   => 1,
				),
				array(
					'product_id'   => $parent->get_id(),
					'variation_id' => $variation->get_id(),
					'quantity'     => 1,
				),
				array(
					'product_id' => $own->get_id(),
					'quantity'   => 1,
				),
			),
		)
	);
	$created  = rest_do_request( $create );
	$rest     = 201 === $created->get_status() ? (int) $created->get_data()['id'] : 0;
	$orders[] = $rest;
	ecomiq_check( "$mode: orders created through the REST API get the seal", $expected === ecomiq_line_seals( $rest ) );

	$admin = wc_create_order();
	$admin->add_product( $sealed, 1 );
	$admin->add_product( $variation, 1 );
	$admin->add_product( $own, 1 );
	$admin->save();
	$orders[] = $admin->get_id();
	ecomiq_check( "$mode: lines added on the order screen get the seal", $expected === ecomiq_line_seals( $admin->get_id() ) );

	ecomiq_check( "$mode: switches to es_ES", switch_to_locale( 'es_ES' ) );
	$encodings = array(
		'JSON text' => 'wp_json_encode',
		'object'    => null,
	);
	foreach ( $encodings as $encoding => $encode ) {
		$split  = wc_create_order();
		$line_a = $split->add_product( $sealed, 2 );
		$line_b = $split->add_product( $variation, 1 );
		$line_o = $split->add_product( $own, 1 );
		$split->save();
		$orders[] = $split->get_id();
		$value    = array(
			'v'     => 1,
			'parts' => array(
				array(
					'kind'      => 'seller',
					'name'      => 'Vendedor A',
					'lines'     => array(
						array(
							'id'  => $line_a,
							'qty' => 2,
						),
					),
					'status'    => 'shipped',
					'shipments' => array(
						array(
							'number'  => 'OLV123',
							'url'     => 'https://example.test/olv123',
							'carrier' => 'Olva',
						),
					),
				),
				array(
					'kind'      => 'seller',
					'name'      => 'Vendedor B',
					'lines'     => array(
						array(
							'id'  => $line_b,
							'qty' => 1,
						),
					),
					'status'    => 'pending',
					'shipments' => array(),
				),
				array(
					'kind'      => 'outside',
					'lines'     => array(
						array(
							'id'  => $line_o,
							'qty' => 1,
						),
					),
					'status'    => 'pending',
					'shipments' => array(),
				),
			),
		);
		if ( null !== $encode ) {
			$value = $encode( $value );
		}
		$write = new WP_REST_Request( 'PUT', '/wc/v3/orders/' . $split->get_id() );
		$write->set_body_params(
			array(
				'meta_data' => array(
					array(
						'key'   => Order_Parts::META,
						'value' => $value,
					),
				),
			)
		);
		$written = rest_do_request( $write );
		$box     = ecomiq_order_box( $split->get_id() );
		ecomiq_check(
			"$mode: the Ecomiq box shows each part from the order meta ($encoding)",
			200 === $written->get_status() && null !== $box
				&& false !== strpos( $box, '<span class="ecomiq-part-name">Vendedor A</span><span class="ecomiq-part-detail">2 productos · Enviado · <a href="https://example.test/olv123" target="_blank" rel="noopener noreferrer">Olva OLV123</a></span>' )
				&& false !== strpos( $box, '<span class="ecomiq-part-name">Vendedor B</span><span class="ecomiq-part-detail">1 producto · Por preparar</span>' )
				&& false !== strpos( $box, '<span class="ecomiq-part-name">Tus productos</span><span class="ecomiq-part-detail">1 producto · Pendiente</span>' )
		);
		$lines = ecomiq_order_lines( $split->get_id() );
		ecomiq_check(
			"$mode: each line shows who ships it ($encoding)",
			isset( $lines[ $line_a ], $lines[ $line_b ], $lines[ $line_o ] )
				&& false !== strpos( $lines[ $line_a ], '<div class="ecomiq-line-part">Vendedor A</div>' )
				&& false !== strpos( $lines[ $line_b ], '<div class="ecomiq-line-part">Vendedor B</div>' )
				&& false !== strpos( $lines[ $line_o ], '<div class="ecomiq-line-part">Tus productos</div>' )
		);
		ecomiq_check( "$mode: the seal is hidden on the order screen ($encoding)", ! empty( $lines ) && false === strpos( implode( '', $lines ), Seal::META ) );
	}

	$plain      = wc_create_order();
	$plain_line = $plain->add_product( $own, 1 );
	$plain->save();
	$orders[]    = $plain->get_id();
	$plain_lines = ecomiq_order_lines( $plain->get_id() );
	ecomiq_check( "$mode: no box and no names without the order meta", null === ecomiq_order_box( $plain->get_id() ) && isset( $plain_lines[ $plain_line ] ) && false === strpos( $plain_lines[ $plain_line ], 'ecomiq-line-part' ) );

	delete_option( Fulfillments::OPTION );
	Diagnostics::reset();
	$off    = ecomiq_fulfillments_row();
	$status = rest_do_request( new WP_REST_Request( 'GET', '/ecomiq/v1/status' ) )->get_data();
	ecomiq_check( "$mode: diagnostics says Apagados, with the beta notice and the button", false !== strpos( $off, 'Apagados' ) && false !== strpos( $off, 'Función beta de WooCommerce.' ) && false !== strpos( $off, 'Activar envíos parciales' ) && false === strpos( $off, '69430' ) );
	ecomiq_check( "$mode: status has the WooCommerce version and fulfillments off", WC()->version === $status['wooVersion'] && false === $status['fulfillments'] );
	if ( ! defined( 'WCSHIPPING_VERSION' ) ) {
		define( 'WCSHIPPING_VERSION', '2.5.0' );
	}
	ecomiq_check( "$mode: with WooCommerce Shipping it warns about issue 69430", false !== strpos( ecomiq_fulfillments_row(), '<a href="' . Fulfillments::ISSUE . '" target="_blank" rel="noopener noreferrer">#69430</a>' ) );
	ecomiq_check( "$mode: fulfillments stay off until the button", 'yes' !== get_option( Fulfillments::OPTION ) && ! Fulfillments::enabled() );
	$redirect = ecomiq_press_fulfillments();
	$on       = ecomiq_fulfillments_row();
	$status   = rest_do_request( new WP_REST_Request( 'GET', '/ecomiq/v1/status' ) )->get_data();
	ecomiq_check( "$mode: the button turns fulfillments on", 'yes' === get_option( Fulfillments::OPTION ) && false !== strpos( $redirect, 'ecomiq_notice=fulfillments_on' ) );
	ecomiq_check( "$mode: diagnostics says Activos, without the button", false !== strpos( $on, 'Activos' ) && false === strpos( $on, 'Activar envíos parciales' ) );
	ecomiq_check( "$mode: status has fulfillments on", true === $status['fulfillments'] );
	delete_option( Fulfillments::OPTION );
	Diagnostics::reset();

	foreach ( $orders as $order_id ) {
		$order = wc_get_order( $order_id );
		if ( $order ) {
			$order->delete( true );
		}
	}
	foreach ( array( $variation, $parent, $sealed, $own ) as $product ) {
		$product->delete( true );
	}
	WC()->cart->empty_cart();
	restore_previous_locale();
	remove_filter( 'pre_http_request', $no_http );
	remove_filter( 'pre_wp_mail', '__return_false' );
	remove_filter( 'woocommerce_store_api_disable_nonce_check', '__return_true' );
	remove_filter( 'woocommerce_available_payment_gateways', $cod );
}

function ecomiq_fulfill( $order, $meta ) {
	$items = array();
	foreach ( $order->get_items() as $item_id => $item ) {
		$items[] = array(
			'item_id' => $item_id,
			'qty'     => $item->get_quantity(),
		);
	}
	$meta[]  = array(
		'id'    => 0,
		'key'   => '_items',
		'value' => $items,
	);
	$request = new WP_REST_Request( 'POST', '/wc/v3/orders/' . $order->get_id() . '/fulfillments' );
	$request->set_header( 'Content-Type', 'application/json' );
	$request->set_body(
		wp_json_encode(
			array(
				'status'          => 'fulfilled',
				'is_fulfilled'    => true,
				'notify_customer' => false,
				'meta_data'       => $meta,
			)
		)
	);
	return rest_do_request( $request )->get_status();
}

function ecomiq_completed_email( $order_id ) {
	$sent   = array();
	$mailer = function ( $callback, $email ) use ( &$sent ) {
		return function () use ( &$sent, $email ) {
			$sent[] = $email->id;
			return true;
		};
	};
	add_filter( 'woocommerce_mail_callback', $mailer, 10, 2 );
	wc_get_order( $order_id )->update_status( 'completed' );
	remove_filter( 'woocommerce_mail_callback', $mailer, 10 );
	return in_array( 'customer_completed_order', $sent, true );
}

function ecomiq_check_emails() {
	$mode = 'posts';
	if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
		$mode = 'HPOS';
	}
	add_filter( 'pre_wp_mail', '__return_false' );
	$product = new WC_Product_Simple();
	$product->set_name( 'Polo azul' );
	$product->set_regular_price( '20' );
	$product->save();
	$orders = array();
	$place  = function () use ( $product, &$orders ) {
		$order = wc_create_order();
		$order->set_billing_email( 'buyer@example.test' );
		$order->add_product( $product, 1 );
		$order->calculate_totals();
		$order->update_status( 'processing' );
		$orders[] = $order->get_id();
		return $order;
	};
	$ecomiq   = array(
		array(
			'id'    => 0,
			'key'   => Fulfillments::SHIPMENT_META,
			'value' => 'shipment-1',
		),
		array(
			'id'    => 0,
			'key'   => '_ecomiq_seller',
			'value' => 'Vendedor A',
		),
	);
	ecomiq_check( "$mode: WooCommerce fulfillments are on for the email checks", Fulfillments::enabled() );

	$shipped = $place();
	$posted  = ecomiq_fulfill( $shipped, $ecomiq );
	ecomiq_check( "$mode: no completed order email after an Ecomiq fulfillment", 201 === $posted && ! ecomiq_completed_email( $shipped->get_id() ) );

	$store  = $place();
	$posted = ecomiq_fulfill( $store, array() );
	ecomiq_check( "$mode: completed order email when only the store fulfilled the order", 201 === $posted && ecomiq_completed_email( $store->get_id() ) );

	$off    = $place();
	$posted = ecomiq_fulfill( $off, $ecomiq );
	update_option( Fulfillments::OPTION, 'no' );
	ecomiq_check( "$mode: completed order email when fulfillments are off", 201 === $posted && ecomiq_completed_email( $off->get_id() ) );
	update_option( Fulfillments::OPTION, 'yes' );

	foreach ( $orders as $order_id ) {
		wc_get_order( $order_id )->delete( true );
	}
	$product->delete( true );
	remove_filter( 'pre_wp_mail', '__return_false' );
}

wp_set_current_user( 1 );
set_current_screen( 'dashboard' );

$ecomiq_sections = array(
	'orders' => 'ecomiq_check_orders',
	'emails' => 'ecomiq_check_emails',
);
if ( isset( $ecomiq_sections[ $ecomiq_section ] ) ) {
	call_user_func( $ecomiq_sections[ $ecomiq_section ] );
	if ( $GLOBALS['ecomiq_failed'] > 0 ) {
		exit( 1 );
	}
	return;
}

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

ecomiq_check_orders();

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
