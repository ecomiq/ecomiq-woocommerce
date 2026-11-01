<?php

use Ecomiq\WooCommerce\Updater;
use const Ecomiq\WooCommerce\FILE;
use const Ecomiq\WooCommerce\VERSION;

if ( 'local' !== wp_get_environment_type() ) {
	throw new RuntimeException( 'Run only in a local WordPress fixture.' );
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
require_once ABSPATH . 'wp-includes/update.php';

$file    = plugin_basename( FILE );
$release = null;
$calls   = 0;
$fake    = function ( $pre, $args, $url ) use ( &$release, &$calls ) {
	if ( Updater::RELEASE_URL === $url ) {
		++$calls;
		if ( $release instanceof WP_Error ) {
			return $release;
		}
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $release ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
	if ( false !== strpos( $url, 'api.wordpress.org/plugins/update-check' ) ) {
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode(
				array(
					'plugins'      => array(),
					'translations' => array(),
					'no_update'    => array(),
				)
			),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
	return new WP_Error( 'http_request_failed', 'Blocked in the updater fixture.' );
};
add_filter( 'pre_http_request', $fake, 10, 3 );

$check   = function ( $name, $condition ) {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL ' . $name );
	}
	echo 'PASS ' . $name . "\n";
};
$release_for = function ( $tag, $package = null ) {
	if ( null === $package ) {
		$package = 'https://github.com/ecomiq/ecomiq-woocommerce/releases/download/' . $tag . '/ecomiq-for-woocommerce.zip';
	}
	return array(
		'tag_name'     => $tag,
		'html_url'     => 'https://github.com/ecomiq/ecomiq-woocommerce/releases/tag/' . $tag,
		'published_at' => '2026-11-01T00:00:00Z',
		'body'         => "* New - One\n* Fix - Two",
		'assets'       => array(
			array(
				'name'                 => 'ecomiq-for-woocommerce.zip',
				'browser_download_url' => $package,
			),
		),
	);
};
$check_updates = function () {
	delete_transient( Updater::TRANSIENT );
	delete_site_transient( 'update_plugins' );
	wp_update_plugins();
	return get_site_transient( 'update_plugins' );
};

try {
	$release = $release_for( 'v99.0.0' );
	$updates = $check_updates();
	$check( 'a newer release is offered', isset( $updates->response[ $file ] ) && '99.0.0' === $updates->response[ $file ]->new_version );
	$check( 'the package is the release asset', 'https://github.com/ecomiq/ecomiq-woocommerce/releases/download/v99.0.0/ecomiq-for-woocommerce.zip' === $updates->response[ $file ]->package );
	$check( 'the Ecomiq screen sees the new version', '99.0.0' === Updater::available() );

	$release = $release_for( 'v' . VERSION );
	$updates = $check_updates();
	$check( 'the installed version is not offered', ! isset( $updates->response[ $file ] ) && isset( $updates->no_update[ $file ] ) );
	$check( 'no notice without a newer version', null === Updater::available() );

	$release = $release_for( 'v99.0.0', 'https://example.com/ecomiq-for-woocommerce.zip' );
	$updates = $check_updates();
	$check( 'a package outside the repository is ignored', ! isset( $updates->response[ $file ] ) );

	$release = array_merge( $release_for( 'v99.0.0' ), array( 'prerelease' => true ) );
	$updates = $check_updates();
	$check( 'a prerelease is not offered', ! isset( $updates->response[ $file ] ) );

	$release = new WP_Error( 'http_request_failed', 'Fixture timeout.' );
	$calls   = 0;
	delete_transient( Updater::TRANSIENT );
	$data   = get_plugin_data( FILE );
	$first  = Updater::update( false, $data, $file );
	$second = Updater::update( false, $data, $file );
	$check( 'a failed check is not repeated right away', false === $first && false === $second && 1 === $calls );

	$release = $release_for( 'v99.0.0' );
	delete_transient( Updater::TRANSIENT );
	$info = plugins_api( 'plugin_information', array( 'slug' => 'ecomiq-for-woocommerce' ) );
	$check( 'View details shows the release notes', is_object( $info ) && '99.0.0' === $info->version && false !== strpos( $info->sections['changelog'], '<li>New - One</li>' ) );
} finally {
	remove_filter( 'pre_http_request', $fake, 10 );
	delete_transient( Updater::TRANSIENT );
	delete_site_transient( 'update_plugins' );
}
