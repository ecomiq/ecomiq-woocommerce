<?php
/**
 * Removes the plugin data when the plugin is deleted.
 *
 * @package Ecomiq\WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-connection.php';
require_once __DIR__ . '/includes/class-activity.php';
require_once __DIR__ . '/includes/class-admin.php';

\Ecomiq\WooCommerce\Connection::uninstall();
