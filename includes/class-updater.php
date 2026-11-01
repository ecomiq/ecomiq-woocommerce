<?php
/**
 * Plugin updates from the GitHub releases.
 *
 * @package Ecomiq\WooCommerce
 */

namespace Ecomiq\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Tells WordPress about newer releases so the plugin updates from the Plugins screen, like any other.
 */
final class Updater {

	const SLUG        = 'ecomiq-for-woocommerce';
	const RELEASE_URL = 'https://api.github.com/repos/ecomiq/ecomiq-woocommerce/releases/latest';
	const PACKAGE_URL = 'https://github.com/ecomiq/ecomiq-woocommerce/releases/download/';
	const PACKAGE     = 'ecomiq-for-woocommerce.zip';
	const TRANSIENT   = 'ecomiq_latest_release';
	const TTL         = 6 * HOUR_IN_SECONDS;
	const FAILURE_TTL = HOUR_IN_SECONDS;

	/**
	 * Hooks the update check and the release details.
	 */
	public static function register() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'update' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_action( 'load-update-core.php', array( __CLASS__, 'forget_on_check' ) );
	}

	/**
	 * Answers WordPress' update check for this plugin with the latest release.
	 *
	 * @param array|false $update      Update offered by another filter, or false.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin path relative to the plugins folder.
	 * @return array|false
	 */
	public static function update( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( FILE ) !== $plugin_file ) {
			return $update;
		}
		$release = self::latest();
		if ( null === $release ) {
			return $update;
		}
		return array(
			'slug'         => self::SLUG,
			'version'      => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires'     => $plugin_data['RequiresWP'] ?? '',
			'requires_php' => $plugin_data['RequiresPHP'] ?? '',
		);
	}

	/**
	 * Fills the "View details" window with the latest release.
	 *
	 * @param false|object|array $result Details found by another filter.
	 * @param string             $action Requested information.
	 * @param object             $args   Request arguments.
	 * @return false|object|array
	 */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || self::SLUG !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$release = self::latest();
		if ( null === $release ) {
			return $result;
		}
		return (object) array(
			'name'          => 'Ecomiq for WooCommerce',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="https://ecomiq.pe">Ecomiq</a>',
			'homepage'      => 'https://ecomiq.pe',
			'requires'      => '6.5',
			'requires_php'  => '7.4',
			'last_updated'  => $release['published'],
			'download_link' => $release['package'],
			'sections'      => array( 'changelog' => self::changelog( $release['notes'] ) ),
		);
	}

	/**
	 * Forgets the cached release when the user asks WordPress to check again.
	 */
	public static function forget_on_check() {
		if ( isset( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			delete_transient( self::TRANSIENT );
		}
	}

	/**
	 * Returns the newer version WordPress already found, or null.
	 *
	 * @return string|null
	 */
	public static function available() {
		$updates = get_site_transient( 'update_plugins' );
		$file    = plugin_basename( FILE );
		if ( ! is_object( $updates ) || ! isset( $updates->response[ $file ]->new_version ) ) {
			return null;
		}
		$version = (string) $updates->response[ $file ]->new_version;
		if ( ! version_compare( $version, VERSION, '>' ) ) {
			return null;
		}
		return $version;
	}

	/**
	 * Address that updates the plugin now.
	 *
	 * @return string
	 */
	public static function update_url() {
		$file = plugin_basename( FILE );
		return wp_nonce_url( self_admin_url( 'update.php?action=upgrade-plugin&plugin=' . rawurlencode( $file ) ), 'upgrade-plugin_' . $file );
	}

	/**
	 * Latest published release, cached.
	 *
	 * @return array|null
	 */
	private static function latest() {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) && array_key_exists( 'release', $cached ) ) {
			return $cached['release'];
		}
		$release = self::fetch();
		$ttl     = self::TTL;
		if ( null === $release ) {
			$ttl = self::FAILURE_TTL;
		}
		set_transient( self::TRANSIENT, array( 'release' => $release ), $ttl );
		return $release;
	}

	/**
	 * Reads the latest release from GitHub.
	 *
	 * @return array|null
	 */
	private static function fetch() {
		$response = wp_remote_get(
			self::RELEASE_URL,
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'ecomiq-for-woocommerce/' . VERSION,
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! empty( $body['draft'] ) || ! empty( $body['prerelease'] ) ) {
			return null;
		}
		$version = ltrim( (string) ( $body['tag_name'] ?? '' ), 'v' );
		if ( 1 !== preg_match( '/^\d+\.\d+\.\d+$/', $version ) ) {
			return null;
		}
		$package = null;
		foreach ( (array) ( $body['assets'] ?? array() ) as $asset ) {
			$url = (string) ( $asset['browser_download_url'] ?? '' );
			if ( self::PACKAGE === ( $asset['name'] ?? '' ) && 0 === strpos( $url, self::PACKAGE_URL ) ) {
				$package = $url;
			}
		}
		if ( null === $package ) {
			return null;
		}
		return array(
			'version'   => $version,
			'package'   => $package,
			'url'       => (string) ( $body['html_url'] ?? '' ),
			'notes'     => (string) ( $body['body'] ?? '' ),
			'published' => (string) ( $body['published_at'] ?? '' ),
		);
	}

	/**
	 * Turns the release notes into the HTML WordPress shows in "View details".
	 *
	 * @param string $notes Release notes, one change per line.
	 * @return string
	 */
	private static function changelog( $notes ) {
		$html = '';
		$list = false;
		foreach ( preg_split( '/\R/', $notes ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			if ( 1 === preg_match( '/^[-*]\s+(.*)$/', $line, $match ) ) {
				if ( ! $list ) {
					$html .= '<ul>';
					$list  = true;
				}
				$html .= '<li>' . esc_html( $match[1] ) . '</li>';
				continue;
			}
			if ( $list ) {
				$html .= '</ul>';
				$list  = false;
			}
			$html .= '<p>' . esc_html( ltrim( $line, '# ' ) ) . '</p>';
		}
		if ( $list ) {
			$html .= '</ul>';
		}
		return $html;
	}
}
