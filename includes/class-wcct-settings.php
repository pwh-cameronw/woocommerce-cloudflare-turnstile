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
			'enabled'               => 'no',
			'protect_checkout'      => 'yes',
			'protect_login'         => 'no',
			'protect_register'      => 'no',
			'protect_lost_password' => 'no',
			'site_key'              => '',
			'secret_key'            => '',
			'theme'                 => 'auto',
			'size'                  => 'flexible',
			'appearance'            => 'always',
			'language'              => 'auto',
			'skip_logged_in'        => 'no',
			'protect_store_api'     => 'yes',
			'error_message'         => '',
			'debug'                 => 'no',
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
	 * Setting that switches each protected form on or off, keyed by context.
	 */
	const CONTEXT_SETTINGS = array(
		'classic'       => 'protect_checkout',
		'store_api'     => 'protect_checkout',
		'login'         => 'protect_login',
		'register'      => 'protect_register',
		'lost_password' => 'protect_lost_password',
	);

	/**
	 * Whether a form should be protected for the current request.
	 *
	 * Protection is never enforced without keys, so a half-finished setup
	 * cannot lock customers out.
	 *
	 * @param string $context "classic" (shortcode checkout), "store_api" (Checkout block / Store API),
	 *                        "login", "register" or "lost_password" (My Account forms).
	 * @return bool
	 */
	public static function should_protect( $context = 'classic' ) {
		$protect = self::is_enabled() && self::has_keys();

		if ( $protect && isset( self::CONTEXT_SETTINGS[ $context ] ) && 'yes' !== self::get( self::CONTEXT_SETTINGS[ $context ] ) ) {
			$protect = false;
		}

		if ( $protect && 'store_api' === $context && 'yes' !== self::get( 'protect_store_api' ) ) {
			$protect = false;
		}

		if ( $protect && self::is_checkout_context( $context ) && 'yes' === self::get( 'skip_logged_in' ) && is_user_logged_in() ) {
			$protect = false;
		}

		if ( self::is_checkout_context( $context ) ) {
			/**
			 * Filter whether the Turnstile challenge is required for this checkout request.
			 *
			 * Useful to bypass verification for express payment flows (e.g. Apple Pay /
			 * Google Pay buttons) or trusted headless clients.
			 *
			 * @param bool   $protect Whether to require Turnstile.
			 * @param string $context "classic" for the shortcode checkout, "store_api" for the Checkout block / Store API.
			 */
			$protect = (bool) apply_filters( 'wcct_should_protect_checkout', $protect, $context );
		}

		/**
		 * Filter whether the Turnstile challenge is required for a form.
		 *
		 * @param bool   $protect Whether to require Turnstile.
		 * @param string $context "classic", "store_api", "login", "register" or "lost_password".
		 */
		return (bool) apply_filters( 'wcct_should_protect', $protect, $context );
	}

	/**
	 * Whether a context belongs to the checkout.
	 *
	 * @param string $context Context.
	 * @return bool
	 */
	public static function is_checkout_context( $context ) {
		return 'classic' === $context || 'store_api' === $context;
	}

	/**
	 * Message shown to customers when verification fails.
	 *
	 * @param string $context Context the message is shown in.
	 * @return string
	 */
	public static function error_message( $context = 'classic' ) {
		$message = self::get( 'error_message' );
		if ( '' !== $message ) {
			return $message;
		}
		if ( self::is_checkout_context( $context ) ) {
			return __( 'Please complete the security check before placing your order.', 'woocommerce-cloudflare-turnstile' );
		}
		return __( 'Please complete the security check and try again.', 'woocommerce-cloudflare-turnstile' );
	}
}
