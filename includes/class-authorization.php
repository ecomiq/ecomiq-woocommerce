<?php

namespace Ecomiq\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class Authorization {

	const TRANSIENT_PREFIX = 'ecomiq_code_';
	const CODE_TTL         = 600;
	const CALLBACK_PATH    = '/connect/woocommerce';

	public static function redirect_uri() {
		return Connection::server() . self::CALLBACK_PATH;
	}

	public static function request( array $source ) {
		$redirect_uri = self::text( $source, 'redirect_uri' );
		$state        = self::text( $source, 'state' );
		$challenge    = self::text( $source, 'code_challenge' );
		$method       = self::text( $source, 'code_challenge_method' );
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
			'store'                 => sanitize_text_field( self::text( $source, 'store' ) ),
		);
	}

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

	public static function challenge( $verifier ) {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}

	private static function text( array $source, $key ) {
		if ( ! isset( $source[ $key ] ) || ! is_string( $source[ $key ] ) ) {
			return '';
		}
		return trim( wp_unslash( $source[ $key ] ) );
	}
}
