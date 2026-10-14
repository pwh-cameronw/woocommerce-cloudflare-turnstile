<?php
/**
 * Settings screen under WooCommerce > Settings > Integration.
 *
 * @package WCCT
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce integration that stores the Turnstile settings.
 */
class WCCT_Integration extends WC_Integration {

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id                 = WCCT_Settings::INTEGRATION_ID;
		$this->method_title       = __( 'Cloudflare Turnstile', 'woocommerce-cloudflare-turnstile' );
		$this->method_description = sprintf(
			/* translators: %s: Cloudflare dashboard URL */
			__( 'Protect the checkout from bots with Cloudflare Turnstile. Create a widget and get your keys from the <a href="%s" target="_blank" rel="noopener noreferrer">Cloudflare dashboard</a>.', 'woocommerce-cloudflare-turnstile' ),
			'https://dash.cloudflare.com/?to=/:account/turnstile'
		);

		$this->init_form_fields();
		$this->init_settings();

		add_action( 'woocommerce_update_options_integration_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/**
	 * Settings fields.
	 */
	public function init_form_fields() {
		$defaults = WCCT_Settings::defaults();

		$this->form_fields = array(
			'enabled'           => array(
				'title'   => __( 'Enable', 'woocommerce-cloudflare-turnstile' ),
				'type'    => 'checkbox',
				'label'   => __( 'Require Turnstile on the checkout page', 'woocommerce-cloudflare-turnstile' ),
				'default' => $defaults['enabled'],
			),
			'site_key'          => array(
				'title'       => __( 'Site key', 'woocommerce-cloudflare-turnstile' ),
				'type'        => 'text',
				'description' => __( 'For testing, the site key 1x00000000000000000000AA always passes.', 'woocommerce-cloudflare-turnstile' ),
				'default'     => '',
			),
			'secret_key'        => array(
				'title'       => __( 'Secret key', 'woocommerce-cloudflare-turnstile' ),
				'type'        => 'password',
				'description' => __( 'For testing, the secret key 1x0000000000000000000000000000000AA always passes.', 'woocommerce-cloudflare-turnstile' ),
				'default'     => '',
			),
			'theme'             => array(
				'title'   => __( 'Theme', 'woocommerce-cloudflare-turnstile' ),
				'type'    => 'select',
				'default' => $defaults['theme'],
				'options' => array(
					'auto'  => __( 'Auto (follow visitor preference)', 'woocommerce-cloudflare-turnstile' ),
					'light' => __( 'Light', 'woocommerce-cloudflare-turnstile' ),
					'dark'  => __( 'Dark', 'woocommerce-cloudflare-turnstile' ),
				),
			),
			'size'              => array(
				'title'   => __( 'Size', 'woocommerce-cloudflare-turnstile' ),
				'type'    => 'select',
				'default' => $defaults['size'],
				'options' => array(
					'flexible' => __( 'Flexible (full width)', 'woocommerce-cloudflare-turnstile' ),
					'normal'   => __( 'Normal', 'woocommerce-cloudflare-turnstile' ),
					'compact'  => __( 'Compact', 'woocommerce-cloudflare-turnstile' ),
				),
			),
			'appearance'        => array(
				'title'   => __( 'Appearance', 'woocommerce-cloudflare-turnstile' ),
				'type'    => 'select',
				'default' => $defaults['appearance'],
				'options' => array(
					'always'           => __( 'Always visible', 'woocommerce-cloudflare-turnstile' ),
					'interaction-only' => __( 'Only when interaction is required', 'woocommerce-cloudflare-turnstile' ),
				),
			),
			'language'          => array(
				'title'       => __( 'Language', 'woocommerce-cloudflare-turnstile' ),
				'type'        => 'text',
				'description' => __( 'Widget language, e.g. "en" or "de-DE". Use "auto" to follow the visitor\'s browser.', 'woocommerce-cloudflare-turnstile' ),
				'default'     => $defaults['language'],
			),
			'skip_logged_in'    => array(
				'title'   => __( 'Logged-in customers', 'woocommerce-cloudflare-turnstile' ),
				'type'    => 'checkbox',
				'label'   => __( 'Do not require Turnstile for logged-in customers', 'woocommerce-cloudflare-turnstile' ),
				'default' => $defaults['skip_logged_in'],
			),
			'protect_store_api' => array(
				'title'       => __( 'Store API', 'woocommerce-cloudflare-turnstile' ),
				'type'        => 'checkbox',
				'label'       => __( 'Require Turnstile for Store API checkouts (Checkout block)', 'woocommerce-cloudflare-turnstile' ),
				'description' => __( 'Keep this on: bots can place orders by calling /wp-json/wc/store/v1/checkout directly even if your site uses the classic checkout. Only disable it if a headless front end places orders through the Store API.', 'woocommerce-cloudflare-turnstile' ),
				'default'     => $defaults['protect_store_api'],
			),
			'error_message'     => array(
				'title'       => __( 'Error message', 'woocommerce-cloudflare-turnstile' ),
				'type'        => 'text',
				'description' => __( 'Shown when the security check was not completed. Leave blank for the default.', 'woocommerce-cloudflare-turnstile' ),
				'default'     => '',
				'placeholder' => __( 'Please complete the security check before placing your order.', 'woocommerce-cloudflare-turnstile' ),
			),
			'debug'             => array(
				'title'       => __( 'Debug log', 'woocommerce-cloudflare-turnstile' ),
				'type'        => 'checkbox',
				'label'       => __( 'Log failed verifications', 'woocommerce-cloudflare-turnstile' ),
				'description' => __( 'Logs are written to WooCommerce > Status > Logs (source: cloudflare-turnstile).', 'woocommerce-cloudflare-turnstile' ),
				'default'     => $defaults['debug'],
			),
		);
	}

	/**
	 * Trim the secret key.
	 *
	 * @param string $key   Field key.
	 * @param string $value Posted value.
	 * @return string
	 */
	public function validate_secret_key_field( $key, $value ) {
		return trim( $this->validate_password_field( $key, $value ) );
	}

	/**
	 * Trim the site key.
	 *
	 * @param string $key   Field key.
	 * @param string $value Posted value.
	 * @return string
	 */
	public function validate_site_key_field( $key, $value ) {
		return trim( $this->validate_text_field( $key, $value ) );
	}
}
