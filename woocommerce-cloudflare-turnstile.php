<?php
/**
 * Plugin Name:          Cloudflare Turnstile for WooCommerce Checkout
 * Plugin URI:           https://github.com/pwh-cameronw/woocommerce-cloudflare-turnstile
 * Description:          Protects the WooCommerce checkout (classic shortcode and Checkout block) with Cloudflare Turnstile.
 * Version:              1.0.0
 * Requires at least:    6.3
 * Requires PHP:         7.4
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.0
 * WC tested up to:      10.2
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          woocommerce-cloudflare-turnstile
 * Domain Path:          /languages
 *
 * @package WCCT
 */

defined( 'ABSPATH' ) || exit;

define( 'WCCT_VERSION', '1.0.0' );
define( 'WCCT_PLUGIN_FILE', __FILE__ );
define( 'WCCT_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCCT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Declare compatibility with WooCommerce features (HPOS and Cart/Checkout blocks).
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

/**
 * Boot the plugin once all plugins are loaded.
 */
function wcct_init() {
	load_plugin_textdomain( 'woocommerce-cloudflare-turnstile', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

	if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_Integration' ) ) {
		add_action( 'admin_notices', 'wcct_missing_woocommerce_notice' );
		return;
	}

	require_once WCCT_PLUGIN_DIR . 'includes/class-wcct-settings.php';
	require_once WCCT_PLUGIN_DIR . 'includes/class-wcct-integration.php';
	require_once WCCT_PLUGIN_DIR . 'includes/class-wcct-verifier.php';
	require_once WCCT_PLUGIN_DIR . 'includes/class-wcct-checkout.php';

	add_filter( 'woocommerce_integrations', 'wcct_register_integration' );
	add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wcct_plugin_action_links' );
	add_action( 'admin_notices', 'wcct_missing_keys_notice' );

	( new WCCT_Checkout() )->init();
}
add_action( 'plugins_loaded', 'wcct_init' );

/**
 * Register the settings screen under WooCommerce > Settings > Integration.
 *
 * @param array $integrations Registered integration class names.
 * @return array
 */
function wcct_register_integration( $integrations ) {
	$integrations[] = 'WCCT_Integration';
	return $integrations;
}

/**
 * Add a "Settings" link on the Plugins screen.
 *
 * @param array $links Existing action links.
 * @return array
 */
function wcct_plugin_action_links( $links ) {
	$url = admin_url( 'admin.php?page=wc-settings&tab=integration&section=' . WCCT_Settings::INTEGRATION_ID );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'woocommerce-cloudflare-turnstile' ) . '</a>' );
	return $links;
}

/**
 * Admin notice shown when WooCommerce is not active.
 */
function wcct_missing_woocommerce_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p>' . esc_html__( 'Cloudflare Turnstile for WooCommerce Checkout requires WooCommerce to be installed and active.', 'woocommerce-cloudflare-turnstile' ) . '</p></div>';
}

/**
 * Admin notice shown when Turnstile is enabled but keys are missing.
 */
function wcct_missing_keys_notice() {
	if ( ! current_user_can( 'manage_woocommerce' ) ) {
		return;
	}
	if ( ! WCCT_Settings::is_enabled() || WCCT_Settings::has_keys() ) {
		return;
	}
	$url = admin_url( 'admin.php?page=wc-settings&tab=integration&section=' . WCCT_Settings::INTEGRATION_ID );
	printf(
		'<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
		esc_html__( 'Cloudflare Turnstile is enabled but the site key or secret key is missing, so the checkout is not protected.', 'woocommerce-cloudflare-turnstile' ),
		esc_url( $url ),
		esc_html__( 'Add your keys', 'woocommerce-cloudflare-turnstile' )
	);
}
