<?php
/**
 * My Account integration: login, registration and lost password forms.
 *
 * @package WCCT
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hooks Turnstile into the WooCommerce account forms.
 */
class WCCT_Account {

	/**
	 * Register hooks.
	 */
	public function init() {
		// Login (My Account page and the checkout's "Returning customer?" form).
		add_action( 'woocommerce_login_form', array( $this, 'render_login_widget' ) );
		add_filter( 'woocommerce_process_login_errors', array( $this, 'validate_login' ) );

		// Registration. Only WC_Form_Handler fires this filter, so accounts
		// created during checkout are covered by the checkout check instead.
		// Priority 30 places the widget after the privacy policy text (priority 20),
		// directly above the Register button.
		add_action( 'woocommerce_register_form', array( $this, 'render_register_widget' ), 30 );
		add_filter( 'woocommerce_process_registration_errors', array( $this, 'validate_registration' ) );

		// Lost password.
		add_action( 'woocommerce_lostpassword_form', array( $this, 'render_lost_password_widget' ) );
		add_action( 'lostpassword_post', array( $this, 'validate_lost_password' ) );
	}

	/**
	 * Output the widget in the login form.
	 */
	public function render_login_widget() {
		$this->maybe_render( 'login' );
	}

	/**
	 * Output the widget in the registration form.
	 */
	public function render_register_widget() {
		$this->maybe_render( 'register' );
	}

	/**
	 * Output the widget in the lost password form.
	 */
	public function render_lost_password_widget() {
		$this->maybe_render( 'lost_password' );
	}

	/**
	 * Validate the login form.
	 *
	 * @param WP_Error $errors Validation errors.
	 * @return WP_Error
	 */
	public function validate_login( $errors ) {
		return $this->validate( 'login', $errors );
	}

	/**
	 * Validate the registration form.
	 *
	 * @param WP_Error $errors Validation errors.
	 * @return WP_Error
	 */
	public function validate_registration( $errors ) {
		return $this->validate( 'register', $errors );
	}

	/**
	 * Validate the lost password form.
	 *
	 * The lostpassword_post action also fires for wp-login.php, which has no
	 * widget, so only WooCommerce's form (identified by its hidden field) is checked.
	 *
	 * @param WP_Error $errors Validation errors.
	 */
	public function validate_lost_password( $errors ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the nonce before calling retrieve_password().
		if ( ! isset( $_POST['wc_reset_password'] ) || ! $errors instanceof WP_Error ) {
			return;
		}
		$this->validate( 'lost_password', $errors );
	}

	/**
	 * Print the widget when the form is protected.
	 *
	 * @param string $context Form context.
	 */
	private function maybe_render( $context ) {
		if ( WCCT_Settings::should_protect( $context ) ) {
			WCCT_Assets::render_widget( $context );
		}
	}

	/**
	 * Verify the posted token and record a failure on the errors object.
	 *
	 * @param string   $context Form context.
	 * @param WP_Error $errors  Validation errors.
	 * @return WP_Error
	 */
	private function validate( $context, $errors ) {
		if ( ! $errors instanceof WP_Error || ! WCCT_Settings::should_protect( $context ) ) {
			return $errors;
		}

		$result = WCCT_Verifier::verify( WCCT_Assets::posted_token(), $context );
		if ( is_wp_error( $result ) ) {
			$errors->add( 'wcct_turnstile', $result->get_error_message() );
		}

		return $errors;
	}
}
