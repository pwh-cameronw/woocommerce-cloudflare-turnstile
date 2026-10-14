<?php
/**
 * Checkout integration: widget rendering, assets and order validation.
 *
 * @package WCCT
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
use Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema;

/**
 * Hooks Turnstile into the classic and block checkouts.
 */
class WCCT_Checkout {

	/**
	 * Store API extension namespace used to send the token from the Checkout block.
	 */
	const EXTENSION_NAMESPACE = 'cloudflare-turnstile';

	/**
	 * Name of the hidden field Turnstile adds to the classic checkout form.
	 */
	const RESPONSE_FIELD = 'cf-turnstile-response';

	/**
	 * Register hooks.
	 */
	public function init() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Classic (shortcode) checkout.
		add_action( 'woocommerce_review_order_before_submit', array( $this, 'render_classic_widget' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate_classic_checkout' ), 20, 2 );

		// Checkout block / Store API.
		add_action( 'woocommerce_blocks_loaded', array( $this, 'register_store_api_data' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'validate_store_api_checkout' ), 10, 2 );
	}

	/**
	 * Whether the current page is a checkout page that shows the widget.
	 *
	 * @return bool
	 */
	private function is_checkout_page() {
		return function_exists( 'is_checkout' )
			&& is_checkout()
			&& ! is_wc_endpoint_url( 'order-received' )
			&& ! is_wc_endpoint_url( 'order-pay' );
	}

	/**
	 * Whether the current checkout page is built with the Checkout block.
	 *
	 * @return bool
	 */
	private function is_block_checkout() {
		return function_exists( 'has_block' ) && has_block( 'woocommerce/checkout' );
	}

	/**
	 * Enqueue the Turnstile API and the checkout integration script.
	 */
	public function enqueue_assets() {
		if ( ! $this->is_checkout_page() ) {
			return;
		}

		$is_blocks = $this->is_block_checkout();
		$context   = $is_blocks ? 'store_api' : 'classic';
		if ( ! WCCT_Settings::should_protect( $context ) ) {
			return;
		}

		$deps = array( 'jquery' );
		if ( $is_blocks ) {
			$deps = array( 'wp-data' );
			if ( wp_script_is( 'wc-blocks-data-store', 'registered' ) ) {
				$deps[] = 'wc-blocks-data-store';
			}
		}

		wp_enqueue_style(
			'wcct-checkout',
			WCCT_PLUGIN_URL . 'assets/css/checkout.css',
			array(),
			WCCT_VERSION
		);

		wp_enqueue_script(
			'wcct-checkout',
			WCCT_PLUGIN_URL . 'assets/js/checkout.js',
			$deps,
			WCCT_VERSION,
			array( 'in_footer' => true )
		);

		wp_localize_script(
			'wcct-checkout',
			'wcctTurnstileConfig',
			array(
				'siteKey'    => WCCT_Settings::get( 'site_key' ),
				'action'     => WCCT_Verifier::ACTION,
				'theme'      => WCCT_Settings::get( 'theme' ),
				'size'       => WCCT_Settings::get( 'size' ),
				'appearance' => WCCT_Settings::get( 'appearance' ),
				'language'   => WCCT_Settings::get( 'language' ),
				'namespace'  => self::EXTENSION_NAMESPACE,
				'isBlocks'   => $is_blocks,
			)
		);

		// Must be loaded straight from Cloudflare; it depends on our script so the
		// onload callback is defined before the API runs.
		wp_enqueue_script(
			'wcct-turnstile-api',
			'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit&onload=wcctTurnstileOnload',
			array( 'wcct-checkout' ),
			null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- version query strings are not allowed by Cloudflare.
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}

	/**
	 * Output the widget container above the "Place order" button.
	 *
	 * The container lives inside the order review fragment, which WooCommerce
	 * replaces on every checkout refresh; checkout.js re-renders it each time.
	 */
	public function render_classic_widget() {
		if ( ! WCCT_Settings::should_protect( 'classic' ) ) {
			return;
		}
		echo '<div class="wcct-turnstile wcct-turnstile--classic"></div>';
	}

	/**
	 * Validate the token on the classic checkout.
	 *
	 * @param array    $data   Posted checkout data.
	 * @param WP_Error $errors Validation errors.
	 */
	public function validate_classic_checkout( $data, $errors ) {
		if ( ! WCCT_Settings::should_protect( 'classic' ) ) {
			return;
		}

		// No order will be placed anyway; don't spend the single-use token.
		if ( $errors->has_errors() ) {
			return;
		}

		// WooCommerce has already verified the checkout nonce at this point.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$token = isset( $_POST[ self::RESPONSE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::RESPONSE_FIELD ] ) ) : '';

		$result = WCCT_Verifier::verify( $token );
		if ( is_wp_error( $result ) ) {
			$errors->add( 'wcct_turnstile', $result->get_error_message() );
		}
	}

	/**
	 * Allow the Checkout block to send the token as Store API extension data.
	 */
	public function register_store_api_data() {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) || ! class_exists( CheckoutSchema::class ) ) {
			return;
		}

		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => CheckoutSchema::IDENTIFIER,
				'namespace'       => self::EXTENSION_NAMESPACE,
				'schema_callback' => function () {
					return array(
						'token' => array(
							'description' => __( 'Cloudflare Turnstile response token.', 'woocommerce-cloudflare-turnstile' ),
							'type'        => 'string',
							'context'     => array(),
						),
					);
				},
			)
		);
	}

	/**
	 * Validate the token for Store API checkouts (Checkout block and direct API calls).
	 *
	 * Runs before payment is processed. Applied to every Store API checkout,
	 * not just block-based checkout pages, because the endpoint is public.
	 *
	 * @param WC_Order        $order   Order being placed.
	 * @param WP_REST_Request $request Store API request.
	 * @throws RouteException When verification fails.
	 */
	public function validate_store_api_checkout( $order, $request ) {
		if ( 'POST' !== $request->get_method() ) {
			return;
		}

		if ( ! WCCT_Settings::should_protect( 'store_api' ) ) {
			return;
		}

		$extensions = $request->get_param( 'extensions' );
		$token      = '';
		if ( is_array( $extensions ) && isset( $extensions[ self::EXTENSION_NAMESPACE ]['token'] ) ) {
			$token = (string) $extensions[ self::EXTENSION_NAMESPACE ]['token'];
		}

		$result = WCCT_Verifier::verify( $token );
		if ( is_wp_error( $result ) ) {
			throw new RouteException( 'woocommerce_rest_cloudflare_turnstile_failed', esc_html( $result->get_error_message() ), 400 );
		}
	}
}
