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
	 * Enqueue assets on Checkout block pages.
	 *
	 * The classic checkout loads them when its widget is printed; the Checkout
	 * block has no PHP hook for that, so it is detected here.
	 */
	public function enqueue_assets() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'order-pay' ) ) {
			return;
		}

		if ( function_exists( 'has_block' ) && has_block( 'woocommerce/checkout' ) && WCCT_Settings::should_protect( 'store_api' ) ) {
			WCCT_Assets::enqueue( true );
		}
	}

	/**
	 * Output the widget container above the "Place order" button.
	 *
	 * The container lives inside the order review fragment, which WooCommerce
	 * replaces on every checkout refresh; turnstile.js re-renders it each time.
	 */
	public function render_classic_widget() {
		if ( ! WCCT_Settings::should_protect( 'classic' ) ) {
			return;
		}
		WCCT_Assets::render_widget( 'classic' );
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

		$result = WCCT_Verifier::verify( WCCT_Assets::posted_token(), 'classic' );
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

		$result = WCCT_Verifier::verify( $token, 'store_api' );
		if ( is_wp_error( $result ) ) {
			throw new RouteException( 'woocommerce_rest_cloudflare_turnstile_failed', esc_html( $result->get_error_message() ), 400 );
		}
	}
}
