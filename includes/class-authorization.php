<?php
/**
 * Authorization codes with PKCE.
 *
 * @package Ecomiq\WooCommerce
 */

namespace Ecomiq\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Authorization codes for the Ecomiq connection, with PKCE (RFC 7636).
 */
final class Authorization {

	const TRANSIENT_PREFIX = 'ecomiq_code_';
	const CODE_TTL         = 600;
	const CALLBACK_PATH    = '/connect/woocommerce';

	/**
	 * Returns the only address Ecomiq may return to.
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return Connection::server() . self::CALLBACK_PATH;
	}

	/**
	 * Reads the authorization fields from a request.
	 *
	 * @param array $source Request values.
	 *
	 * @return array
	 */
	public static function fields( array $source ) {
		$fields = array();
		foreach ( array( 'redirect_uri', 'state', 'code_challenge', 'code_challenge_method', 'store' ) as $key ) {
			$fields[ $key ] = '';
			if ( isset( $source[ $key ] ) && is_string( $source[ $key ] ) ) {
				$fields[ $key ] = trim( sanitize_text_field( wp_unslash( $source[ $key ] ) ) );
			}
		}
		return $fields;
	}

	/**
	 * Validates an authorization request.
	 *
	 * @param array $fields Authorization fields.
	 *
	 * @return array|\WP_Error Validated request or the reason it is invalid.
	 */
	public static function request( array $fields ) {
		$redirect_uri = $fields['redirect_uri'];
		$state        = $fields['state'];
		$challenge    = $fields['code_challenge'];
		$method       = $fields['code_challenge_method'];
		if ( '' === $state || strlen( $state ) > 4096 || 1 !== preg_match( '/^[A-Za-z0-9._~-]+$/', $state ) ) {
			return new \WP_Error( 'ecomiq_incomplete' );
		}
		if ( 'S256' !== $method || 1 !== preg_match( '/^[A-Za-z0-9_-]{43}$/', $challenge ) ) {
			return new \WP_Error( 'ecomiq_incomplete' );
		}
		if ( self::redirect_uri() !== $redirect_uri ) {
			return new \WP_Error( 'ecomiq_redirect', '', array( 'redirect_uri' => $redirect_uri ) );
		}
		return array(
			'redirect_uri'          => $redirect_uri,
			'state'                 => $state,
			'code_challenge'        => $challenge,
			'code_challenge_method' => $method,
			'store'                 => $fields['store'],
		);
	}

	/**
	 * Issues a single-use authorization code.
	 *
	 * @param array $request Validated request.
	 * @param int   $user_id User who approves.
	 *
	 * @return string
	 */
	public static function issue( array $request, $user_id ) {
		$code = bin2hex( random_bytes( 32 ) );
		set_transient(
			self::TRANSIENT_PREFIX . hash( 'sha256', $code ),
			array(
				'user_id'      => (int) $user_id,
				'redirect_uri' => $request['redirect_uri'],
				'challenge'    => $request['code_challenge'],
			),
			self::CODE_TTL
		);
		return $code;
	}

	/**
	 * Redeems an authorization code once.
	 *
	 * @param string $code         Authorization code.
	 * @param string $verifier     PKCE code verifier.
	 * @param string $redirect_uri Redirect URI of the request.
	 *
	 * @return int|\WP_Error User ID or the error.
	 */
	public static function redeem( $code, $verifier, $redirect_uri ) {
		$invalid = new \WP_Error( 'invalid_grant', __( 'The authorization code is invalid or expired. Connect again from Ecomiq.', 'ecomiq-for-woocommerce' ), array( 'status' => 400 ) );
		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $code ) || 1 !== preg_match( '/^[A-Za-z0-9._~-]{43,128}$/', $verifier ) ) {
			return $invalid;
		}
		$name  = self::TRANSIENT_PREFIX . hash( 'sha256', $code );
		$grant = get_transient( $name );
		if ( ! is_array( $grant ) || ! delete_transient( $name ) ) {
			return $invalid;
		}
		if ( ! hash_equals( (string) $grant['redirect_uri'], (string) $redirect_uri ) ) {
			return $invalid;
		}
		if ( ! hash_equals( (string) $grant['challenge'], self::challenge( $verifier ) ) ) {
			return $invalid;
		}
		$user = get_userdata( (int) $grant['user_id'] );
		if ( ! $user || ! user_can( $user, 'manage_woocommerce' ) ) {
			return $invalid;
		}
		return (int) $user->ID;
	}

	/**
	 * Returns the S256 challenge of a verifier.
	 *
	 * @param string $verifier PKCE code verifier.
	 *
	 * @return string
	 */
	public static function challenge( $verifier ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE S256 (RFC 7636) is base64url.
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}
}
