#!/bin/sh
set -eu

target=${1:-http://host.docker.internal:8004}
cd "$(dirname "$0")"
docker compose run --rm cli wp eval "
foreach ( WC_Data_Store::load( 'webhook' )->search_webhooks( array( 'search' => 'ecomiq-', 'limit' => -1 ) ) as \$id ) {
	\$webhook = wc_get_webhook( \$id );
	\$url     = preg_replace( '#^https?://[^/]+(/api)?#', '$target', \$webhook->get_delivery_url() );
	\$webhook->set_delivery_url( \$url );
	\$webhook->set_failure_count( 0 );
	\$webhook->set_status( 'active' );
	\$webhook->save();
	echo \$webhook->get_topic() . ' -> ' . \$url . PHP_EOL;
}
"
