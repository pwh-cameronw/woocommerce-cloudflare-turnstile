<?php
/**
 * Server-side Turnstile token verification.
 *
 * @package WCCT
 */

defined( 'ABSPATH' ) || exit;

/**
 * Calls the Turnstile Siteverify API.
 */
class WCCT_Verifier {

	/**
	 * Siteverify endpoint.
	 */
	const ENDPOINT = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

	/**
	 * Action name bound to the widget; checked on verification so tokens
	 * issued for other forms on the site cannot be reused at checkout.
	 */
	const ACTION = 'wc-checkout';

	/**
	 * Maximum token length accepted by Siteverify.
	 */
	const MAX_TOKEN_LENGTH = 2048;

	/**
	 * Verify a token.
	 *
	 * @param string $token Value of cf-turnstile-response.
	 * @return true|WP_Error True when the token is valid.
	 */
	public static function verify( $token ) {
		$token = is_string( $token ) ? trim( $token ) : '';

		if ( '' === $token ) {
			return new WP_Error( 'wcct_missing_token', WCCT_Settings::error_message() );
		}

		if ( strlen( $token ) > self::MAX_TOKEN_LENGTH ) {
			self::log( 'Rejected token longer than ' . self::MAX_TOKEN_LENGTH . ' characters.' );
			return new WP_Error( 'wcct_invalid_token', WCCT_Settings::error_message() );
		}

		$body = array(
			'secret'   => WCCT_Settings::get( 'secret_key' ),
			'response' => $token,
		);

		$remote_ip = class_exists( 'WC_Geolocation' ) ? WC_Geolocation::get_ip_address() : '';
		if ( $remote_ip ) {
			$body['remoteip'] = $remote_ip;
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => 10,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			self::log( 'Siteverify request failed: ' . $response->get_error_message() );
			return new WP_Error( 'wcct_unavailable', __( 'We could not verify the security check. Please try again.', 'woocommerce-cloudflare-turnstile' ) );
		}

		$code   = (int) wp_remote_retrieve_response_code( $response );
		$result = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $result ) ) {
			self::log( 'Unexpected Siteverify response (HTTP ' . $code . ').' );
			return new WP_Error( 'wcct_unavailable', __( 'We could not verify the security check. Please try again.', 'woocommerce-cloudflare-turnstile' ) );
		}

		if ( empty( $result['success'] ) ) {
			$errors = isset( $result['error-codes'] ) ? implode( ', ', (array) $result['error-codes'] ) : 'none';
			self::log( 'Verification failed. Error codes: ' . $errors );
			return new WP_Error( 'wcct_failed', WCCT_Settings::error_message() );
		}

		// Test keys return an empty action, so only compare when one is present.
		if ( ! empty( $result['action'] ) && self::ACTION !== $result['action'] ) {
			self::log( 'Token was issued for a different action: ' . $result['action'] );
			return new WP_Error( 'wcct_failed', WCCT_Settings::error_message() );
		}

		return true;
	}

	/**
	 * Write to the WooCommerce log when debug logging is on.
	 *
	 * @param string $message Message.
	 */
	private static function log( $message ) {
		if ( 'yes' !== WCCT_Settings::get( 'debug' ) || ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		wc_get_logger()->warning( $message, array( 'source' => 'cloudflare-turnstile' ) );
	}
}
