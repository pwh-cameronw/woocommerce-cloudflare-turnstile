<?php
/**
 * Remove plugin data on uninstall.
 *
 * @package WCCT
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woocommerce_cloudflare_turnstile_settings' );
