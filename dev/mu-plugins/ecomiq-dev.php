<?php

add_filter(
	'http_request_host_is_external',
	function ( $external, $host ) {
		return $external || 'host.docker.internal' === $host;
	},
	10,
	2
);

add_filter(
	'http_allowed_safe_ports',
	function ( $ports, $host ) {
		if ( 'host.docker.internal' === $host ) {
			$ports[] = 8004;
		}
		return $ports;
	},
	10,
	2
);
