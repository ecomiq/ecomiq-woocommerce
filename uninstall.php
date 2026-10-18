<?php

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-connection.php';
require_once __DIR__ . '/includes/class-authorization.php';

\Ecomiq\WooCommerce\Connection::uninstall();
