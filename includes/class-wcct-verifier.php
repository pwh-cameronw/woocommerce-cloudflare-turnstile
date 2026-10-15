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
	 * Action names bound to each widget, keyed by context. Checked on
	 * verification so a token issued for one form cannot be reused on another.
	 */
	const ACTIONS = array(
		'classic'       => 'wc-checkout',
		'store_api'     => 'wc-checkout',
		'login'         => 'wc-login',
		'register'      => 'wc-register',
		'lost_password' => 'wc-lost-password',
	);

	/**
	 * Maximum token length accepted by Siteverify.
	 */
	const MAX_TOKEN_LENGTH = 2048;

	/**
	 * Turnstile action name for a context.
	 *
	 * @param string $context Context, see WCCT_Settings::should_protect().
	 * @return string
	 */
	public static function action( $context ) {
		return isset( self::ACTIONS[ $context ] ) ? self::ACTIONS[ $context ] : 'wc-' . $context;
	}

	/**
	 * Verify a token.
	 *
	 * @param string $token   Value of cf-turnstile-response.
	 * @param string $context Form the token was submitted from.
	 * @return true|WP_Error True when the token is valid.
	 */
	public static function verify( $token, $context = 'classic' ) {
		$token   = is_string( $token ) ? trim( $token ) : '';
		$action  = self::action( $context );
		$message = WCCT_Settings::error_message( $context );

		if ( '' === $token ) {
			return new WP_Error( 'wcct_missing_token', $message );
		}

		if ( strlen( $token ) > self::MAX_TOKEN_LENGTH ) {
			self::log( 'Rejected token longer than ' . self::MAX_TOKEN_LENGTH . ' characters.' );
			return new WP_Error( 'wcct_invalid_token', $message );
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
			return new WP_Error( 'wcct_failed', $message );
		}

		// Test keys return an empty action, so only compare when one is present.
		if ( ! empty( $result['action'] ) && $action !== $result['action'] ) {
			self::log( 'Token was issued for a different action: ' . $result['action'] );
			return new WP_Error( 'wcct_failed', $message );
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
