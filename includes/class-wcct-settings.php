<?php
/**
 * Read-only access to the plugin settings.
 *
 * @package WCCT
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings helper.
 */
class WCCT_Settings {

	/**
	 * ID of the WooCommerce integration (also used in the option name).
	 */
	const INTEGRATION_ID = 'cloudflare_turnstile';

	/**
	 * Option name used by WC_Settings_API for the integration.
	 */
	const OPTION_NAME = 'woocommerce_cloudflare_turnstile_settings';

	/**
	 * Default values for every setting.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'           => 'no',
			'site_key'          => '',
			'secret_key'        => '',
			'theme'             => 'auto',
			'size'              => 'flexible',
			'appearance'        => 'always',
			'language'          => 'auto',
			'skip_logged_in'    => 'no',
			'protect_store_api' => 'yes',
			'error_message'     => '',
			'debug'             => 'no',
		);
	}

	/**
	 * Get a single setting.
	 *
	 * @param string $key Setting key.
	 * @return string
	 */
	public static function get( $key ) {
		$settings = get_option( self::OPTION_NAME, array() );
		$settings = is_array( $settings ) ? $settings : array();
		$defaults = self::defaults();

		if ( isset( $settings[ $key ] ) && '' !== $settings[ $key ] ) {
			return (string) $settings[ $key ];
		}

		return isset( $defaults[ $key ] ) ? (string) $defaults[ $key ] : '';
	}

	/**
	 * Whether the admin switched protection on.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return 'yes' === self::get( 'enabled' );
	}

	/**
	 * Whether both keys are configured.
	 *
	 * @return bool
	 */
	public static function has_keys() {
		return '' !== self::get( 'site_key' ) && '' !== self::get( 'secret_key' );
	}

	/**
	 * Whether the checkout should be protected for the current request.
	 *
	 * Protection is never enforced without keys, so a half-finished setup
	 * cannot lock customers out of the checkout.
	 *
	 * @param string $context Either "classic" or "store_api".
	 * @return bool
	 */
	public static function should_protect( $context = 'classic' ) {
		$protect = self::is_enabled() && self::has_keys();

		if ( $protect && 'store_api' === $context && 'yes' !== self::get( 'protect_store_api' ) ) {
			$protect = false;
		}

		if ( $protect && 'yes' === self::get( 'skip_logged_in' ) && is_user_logged_in() ) {
			$protect = false;
		}

		/**
		 * Filter whether the Turnstile challenge is required for this checkout request.
		 *
		 * Useful to bypass verification for express payment flows (e.g. Apple Pay /
		 * Google Pay buttons) or trusted headless clients.
		 *
		 * @param bool   $protect Whether to require Turnstile.
		 * @param string $context "classic" for the shortcode checkout, "store_api" for the Checkout block / Store API.
		 */
		return (bool) apply_filters( 'wcct_should_protect_checkout', $protect, $context );
	}

	/**
	 * Message shown to customers when verification fails.
	 *
	 * @return string
	 */
	public static function error_message() {
		$message = self::get( 'error_message' );
		if ( '' === $message ) {
			$message = __( 'Please complete the security check before placing your order.', 'woocommerce-cloudflare-turnstile' );
		}
		return $message;
	}
}
