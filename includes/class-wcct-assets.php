<?php
/**
 * Front-end assets and widget markup shared by every protected form.
 *
 * @package WCCT
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the Turnstile API and prints widget containers.
 */
class WCCT_Assets {

	/**
	 * Whether the assets were already enqueued for this request.
	 *
	 * @var bool
	 */
	private static $enqueued = false;

	/**
	 * Print a widget container and make sure the scripts are loaded.
	 *
	 * Scripts enqueued while the page body renders are printed in the footer,
	 * so assets only load on pages that actually show a widget.
	 *
	 * @param string $context Context, see WCCT_Settings::should_protect().
	 */
	public static function render_widget( $context ) {
		self::enqueue();
		printf(
			'<div class="wcct-turnstile wcct-turnstile--%1$s" data-action="%2$s"></div>',
			esc_attr( str_replace( '_', '-', $context ) ),
			esc_attr( WCCT_Verifier::action( $context ) )
		);
	}

	/**
	 * Enqueue the Turnstile API and the integration script.
	 *
	 * @param bool $is_blocks Whether the page uses the Checkout block.
	 */
	public static function enqueue( $is_blocks = false ) {
		if ( self::$enqueued || wp_doing_ajax() ) {
			return;
		}
		self::$enqueued = true;

		$deps = array( 'jquery' );
		if ( $is_blocks ) {
			$deps = array( 'wp-data' );
			if ( wp_script_is( 'wc-blocks-data-store', 'registered' ) ) {
				$deps[] = 'wc-blocks-data-store';
			}
		}

		wp_enqueue_style(
			'wcct-turnstile',
			WCCT_PLUGIN_URL . 'assets/css/turnstile.css',
			array(),
			WCCT_VERSION
		);

		wp_enqueue_script(
			'wcct-turnstile',
			WCCT_PLUGIN_URL . 'assets/js/turnstile.js',
			$deps,
			WCCT_VERSION,
			array( 'in_footer' => true )
		);

		wp_localize_script(
			'wcct-turnstile',
			'wcctTurnstileConfig',
			array(
				'siteKey'        => WCCT_Settings::get( 'site_key' ),
				'theme'          => WCCT_Settings::get( 'theme' ),
				'size'           => WCCT_Settings::get( 'size' ),
				'appearance'     => WCCT_Settings::get( 'appearance' ),
				'language'       => WCCT_Settings::get( 'language' ),
				'isBlocks'       => $is_blocks,
				'checkoutAction' => WCCT_Verifier::action( 'store_api' ),
				'namespace'      => WCCT_Checkout::EXTENSION_NAMESPACE,
			)
		);

		// Must be loaded straight from Cloudflare; it depends on our script so the
		// onload callback is defined before the API runs.
		wp_enqueue_script(
			'wcct-turnstile-api',
			'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=wcctTurnstileOnload',
			array( 'wcct-turnstile' ),
			null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- version query strings are not allowed by Cloudflare.
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Read the token Turnstile adds to a submitted form.
	 *
	 * Callers run after WooCommerce has verified the form's nonce.
	 *
	 * @return string
	 */
	public static function posted_token() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return isset( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) ) : '';
	}
}
