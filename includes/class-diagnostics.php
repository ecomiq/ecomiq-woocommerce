<?php
/**
 * Connection diagnostics.
 *
 * @package Ecomiq\WooCommerce
 */

namespace Ecomiq\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Checks what can stop Ecomiq from reaching the store, or the store from reaching Ecomiq.
 */
final class Diagnostics {

	const TRANSIENT     = 'ecomiq_diagnostics';
	const TTL           = 600;
	const STALE_SECONDS = 7200;

	const SECURITY_PLUGINS = array(
		'wordfence/wordfence.php'                       => array( 'Wordfence', 'Wordfence → Firewall → All Firewall Options → Allowlisted IP addresses' ),
		'better-wp-security/better-wp-security.php'     => array( 'Solid Security', 'Security → Settings → Firewall → Authorized IPs' ),
		'ithemes-security-pro/ithemes-security-pro.php' => array( 'Solid Security Pro', 'Security → Settings → Firewall → Authorized IPs' ),
		'all-in-one-wp-security-and-firewall/wp-security.php' => array( 'All-In-One Security', 'WP Security → Firewall → Allowlist' ),
		'sucuri-scanner/sucuri.php'                     => array( 'Sucuri', 'Sucuri dashboard → Firewall → Access Control → Allowlist IP' ),
		'wp-cerber/wp-cerber.php'                       => array( 'WP Cerber', 'WP Cerber → Access Lists → White IP Access List' ),
		'ninjafirewall/ninjafirewall.php'               => array( 'NinjaFirewall', 'NinjaFirewall → Access Control → IP Access Control' ),
		'wp-simple-firewall/icwp-wpsf.php'              => array( 'Shield Security', 'Shield → IP Manager → Bypass list' ),
		'defender-security/wp-defender.php'             => array( 'Defender', 'Defender → Firewall → IP Banning → Allowlist' ),
		'disable-json-api/disable-json-api.php'         => array( 'Disable REST API', 'Settings → Disable REST API: allow the WooCommerce and Ecomiq routes' ),
		'disable-wp-rest-api/disable-wp-rest-api.php'   => array( 'Disable WP REST API', 'Deactivate it, or allow the WooCommerce and Ecomiq routes' ),
		'cloudflare/cloudflare.php'                     => array( 'Cloudflare', 'Cloudflare dashboard → Security → WAF → Tools → IP Access Rules: Allow' ),
	);

	/**
	 * Returns every check, cached for a few minutes.
	 *
	 * @return array[] Checks with label, status and detail.
	 */
	public static function checks() {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$checks = array(
			self::https(),
			self::permalinks(),
			self::rest(),
			self::outbound(),
		);
		$access = self::access();
		if ( null !== $access ) {
			$checks[] = $access;
		}
		$webhooks = self::webhooks();
		if ( null !== $webhooks ) {
			$checks[] = $webhooks;
		}
		foreach ( self::firewalls() as $firewall ) {
			$checks[] = $firewall;
		}
		set_transient( self::TRANSIENT, $checks, self::TTL );
		return $checks;
	}

	/**
	 * Forgets the cached checks so the next read runs them again.
	 */
	public static function reset() {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Whether any check failed.
	 *
	 * @return bool
	 */
	public static function has_problems() {
		foreach ( self::checks() as $check ) {
			if ( 'error' === $check['status'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns the IP addresses Ecomiq connects from, as Ecomiq sent them.
	 *
	 * @return string[]
	 */
	public static function outbound_ips() {
		$connection = get_option( Connection::OPTION );
		if ( ! is_array( $connection ) || empty( $connection['outbound_ips'] ) || ! is_array( $connection['outbound_ips'] ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'strval', $connection['outbound_ips'] ) ) );
	}

	/**
	 * Checks that WordPress sees HTTPS.
	 *
	 * @return array
	 */
	private static function https() {
		if ( is_ssl() ) {
			return self::check( 'https', 'ok', __( 'HTTPS', 'ecomiq-for-woocommerce' ), '' );
		}
		return self::check( 'https', 'error', __( 'HTTPS', 'ecomiq-for-woocommerce' ), __( 'WordPress does not detect HTTPS. WooCommerce rejects API keys without it.', 'ecomiq-for-woocommerce' ) );
	}

	/**
	 * Checks that permalinks are on, which the REST API needs.
	 *
	 * @return array
	 */
	private static function permalinks() {
		if ( '' !== (string) get_option( 'permalink_structure' ) ) {
			return self::check( 'permalinks', 'ok', __( 'Permalinks', 'ecomiq-for-woocommerce' ), '' );
		}
		return self::check( 'permalinks', 'error', __( 'Permalinks', 'ecomiq-for-woocommerce' ), __( 'Turn on permalinks in Settings → Permalinks.', 'ecomiq-for-woocommerce' ) );
	}

	/**
	 * Calls the store's own REST API to find plugins or firewalls that block it.
	 *
	 * @return array
	 */
	private static function rest() {
		$label    = __( 'REST API', 'ecomiq-for-woocommerce' );
		$response = wp_remote_get(
			rest_url( Rest::NAMESPACE_V1 . '/status' ),
			array(
				'timeout'   => 8,
				'sslverify' => false,
			)
		);
		if ( is_wp_error( $response ) ) {
			return self::check( 'rest', 'unknown', $label, __( 'This server could not call itself, so the REST API was not tested. Some hosts block that test.', 'ecomiq-for-woocommerce' ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( 200 === $code && is_array( $body ) && isset( $body['plugin'] ) ) {
			return self::check( 'rest', 'ok', $label, '' );
		}
		$blocker = self::blocker( $response );
		if ( '' !== $blocker ) {
			return self::check(
				'rest',
				'error',
				$label,
				sprintf(
					/* translators: 1: firewall name, 2: HTTP status */
					__( '%1$s answered instead of the REST API (HTTP %2$d). Ecomiq will be blocked the same way.', 'ecomiq-for-woocommerce' ),
					$blocker,
					$code
				)
			);
		}
		return self::check(
			'rest',
			'error',
			$label,
			sprintf(
				/* translators: %d: HTTP status */
				__( 'The REST API answered HTTP %d. A security plugin or the host may be restricting it.', 'ecomiq-for-woocommerce' ),
				$code
			)
		);
	}

	/**
	 * Checks that this server can open connections to Ecomiq, which webhooks need.
	 *
	 * @return array
	 */
	private static function outbound() {
		$label    = __( 'Connection to Ecomiq', 'ecomiq-for-woocommerce' );
		$target   = self::webhook_origin();
		$response = wp_remote_head(
			$target,
			array(
				'timeout'     => 8,
				'redirection' => 0,
			)
		);
		if ( is_wp_error( $response ) ) {
			return self::check(
				'outbound',
				'error',
				$label,
				sprintf(
					/* translators: 1: address, 2: error message */
					__( 'This server cannot reach %1$s (%2$s). Changes in the store will not reach Ecomiq until the host allows it.', 'ecomiq-for-woocommerce' ),
					$target,
					$response->get_error_message()
				)
			);
		}
		return self::check( 'outbound', 'ok', $label, '' );
	}

	/**
	 * Warns when Ecomiq has not used its API key for a while.
	 *
	 * @return array|null
	 */
	private static function access() {
		$connection = Connection::current();
		if ( null === $connection ) {
			return null;
		}
		$label = __( 'Ecomiq access', 'ecomiq-for-woocommerce' );
		$since = (int) $connection['connected_at'];
		if ( ! empty( $connection['last_access'] ) ) {
			$since = (int) $connection['last_access'];
		}
		if ( time() - $since < self::STALE_SECONDS ) {
			return self::check( 'access', 'ok', $label, '' );
		}
		return self::check(
			'access',
			'warning',
			$label,
			sprintf(
				/* translators: %s: human time difference */
				__( 'Ecomiq has not reached the store for %s. If a firewall protects the store, allow Ecomiq.', 'ecomiq-for-woocommerce' ),
				human_time_diff( $since, time() )
			)
		);
	}

	/**
	 * Checks that the Ecomiq webhooks are active and reaching Ecomiq.
	 *
	 * @return array|null
	 */
	private static function webhooks() {
		if ( null === Connection::current() ) {
			return null;
		}
		$label    = __( 'Webhooks', 'ecomiq-for-woocommerce' );
		$webhooks = self::ecomiq_webhooks();
		if ( empty( $webhooks ) ) {
			return self::check( 'webhooks', 'warning', $label, __( 'Ecomiq has not registered its webhooks yet, so it only sees changes on its scheduled checks.', 'ecomiq-for-woocommerce' ) );
		}
		$inactive = 0;
		foreach ( $webhooks as $webhook ) {
			if ( 'active' !== $webhook->get_status() ) {
				++$inactive;
			}
		}
		if ( $inactive > 0 ) {
			return self::check(
				'webhooks',
				'error',
				$label,
				sprintf(
					/* translators: %d: number of inactive webhooks */
					__( 'Ecomiq webhooks paused or disabled: %d. WooCommerce disables a webhook after 5 failed deliveries in a row. Turn them back on in WooCommerce → Settings → Advanced → Webhooks.', 'ecomiq-for-woocommerce' ),
					$inactive
				)
			);
		}
		$failed = 0;
		foreach ( Activity::events() as $event ) {
			if ( 'webhook_failed' === $event['type'] && (int) $event['time'] > time() - DAY_IN_SECONDS ) {
				++$failed;
			}
		}
		if ( 0 === $failed ) {
			return self::check( 'webhooks', 'ok', $label, '' );
		}
		return self::check(
			'webhooks',
			'warning',
			$label,
			sprintf(
				/* translators: %d: number of failed webhooks */
				__( 'Webhooks that did not reach Ecomiq in the last 24 hours: %d.', 'ecomiq-for-woocommerce' ),
				$failed
			)
		);
	}

	/**
	 * Lists active security plugins and Cloudflare, with where to allow Ecomiq.
	 *
	 * @return array[]
	 */
	private static function firewalls() {
		$checks = array();
		$active = (array) get_option( 'active_plugins', array() );
		foreach ( self::SECURITY_PLUGINS as $file => $plugin ) {
			if ( ! in_array( $file, $active, true ) ) {
				continue;
			}
			$checks[] = self::check(
				'firewall',
				'info',
				$plugin[0],
				sprintf(
					/* translators: %s: where to allow an IP address */
					__( 'If it blocks Ecomiq, allow its IP addresses in %s.', 'ecomiq-for-woocommerce' ),
					$plugin[1]
				)
			);
		}
		if ( ! empty( $_SERVER['HTTP_CF_RAY'] ) && ! in_array( 'cloudflare/cloudflare.php', $active, true ) ) {
			$checks[] = self::check(
				'firewall',
				'info',
				'Cloudflare',
				sprintf(
					/* translators: %s: where to allow an IP address */
					__( 'If it blocks Ecomiq, allow its IP addresses in %s.', 'ecomiq-for-woocommerce' ),
					self::SECURITY_PLUGINS['cloudflare/cloudflare.php'][1]
				)
			);
		}
		return $checks;
	}

	/**
	 * Names the firewall behind a blocked response, or returns an empty string.
	 *
	 * @param array $response HTTP response.
	 *
	 * @return string
	 */
	private static function blocker( $response ) {
		$body = strtolower( substr( (string) wp_remote_retrieve_body( $response ), 0, 20000 ) );
		if ( '' !== (string) wp_remote_retrieve_header( $response, 'cf-ray' ) && false !== strpos( $body, '<html' ) ) {
			return 'Cloudflare';
		}
		$signatures = array(
			'wordfence'                      => 'Wordfence',
			'sucuri website firewall'        => 'Sucuri',
			'imunify360'                     => 'Imunify360',
			'mod_security'                   => 'ModSecurity',
			'not acceptable!'                => 'ModSecurity',
			'the requested url was rejected' => 'F5',
		);
		foreach ( $signatures as $needle => $name ) {
			if ( false !== strpos( $body, $needle ) ) {
				return $name;
			}
		}
		if ( false !== strpos( $body, '<html' ) ) {
			return __( 'A firewall', 'ecomiq-for-woocommerce' );
		}
		return '';
	}

	/**
	 * Returns the origin webhooks are delivered to, or the Ecomiq address.
	 *
	 * @return string
	 */
	private static function webhook_origin() {
		foreach ( self::ecomiq_webhooks() as $webhook ) {
			$parts = wp_parse_url( $webhook->get_delivery_url() );
			if ( is_array( $parts ) && ! empty( $parts['scheme'] ) && ! empty( $parts['host'] ) ) {
				$origin = $parts['scheme'] . '://' . $parts['host'];
				if ( isset( $parts['port'] ) ) {
					$origin .= ':' . (int) $parts['port'];
				}
				return $origin;
			}
		}
		return Connection::server();
	}

	/**
	 * Returns the webhooks Ecomiq registered in the store.
	 *
	 * @return \WC_Webhook[]
	 */
	private static function ecomiq_webhooks() {
		if ( ! function_exists( 'wc_get_webhook' ) || ! class_exists( 'WC_Data_Store' ) ) {
			return array();
		}
		$ids      = \WC_Data_Store::load( 'webhook' )->search_webhooks(
			array(
				'search' => Connection::WEBHOOK_PREFIX,
				'limit'  => -1,
			)
		);
		$webhooks = array();
		foreach ( (array) $ids as $id ) {
			$webhook = wc_get_webhook( $id );
			if ( $webhook && 0 === strpos( $webhook->get_name(), Connection::WEBHOOK_PREFIX ) ) {
				$webhooks[] = $webhook;
			}
		}
		return $webhooks;
	}

	/**
	 * Builds one check.
	 *
	 * @param string $id     Check ID.
	 * @param string $status ok, warning, error, info or unknown.
	 * @param string $label  What was checked.
	 * @param string $detail What it means and what to do.
	 *
	 * @return array
	 */
	private static function check( $id, $status, $label, $detail ) {
		return array(
			'id'     => $id,
			'status' => $status,
			'label'  => $label,
			'detail' => $detail,
		);
	}
}
