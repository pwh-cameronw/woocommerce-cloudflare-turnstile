# Cloudflare Turnstile for WooCommerce

A WordPress plugin that adds [Cloudflare Turnstile](https://developers.cloudflare.com/turnstile/) to the WooCommerce checkout and My Account forms, blocking bots, card-testing and credential-stuffing attacks without a CAPTCHA puzzle.

## Features

- Works with both the **classic (shortcode) checkout** and the **Checkout block**.
- Optional protection for the **My Account login, registration and lost password** forms (the login setting also covers the checkout's "Returning customer?" login form).
- Protects the **Store API** checkout endpoint (`/wp-json/wc/store/v1/checkout`), which bots can call directly even on sites that use the classic checkout.
- Server-side verification through Cloudflare's Siteverify API. Each form uses its own action (`wc-checkout`, `wc-login`, `wc-register`, `wc-lost-password`), so a token solved on one form can't be replayed on another.
- Fresh token after each failed order attempt (tokens are single use) and after checkout refreshes.
- Theme, size, appearance and language options; optional bypass for logged-in customers.
- Never enforced until both keys are entered, so a half-finished setup can't lock customers out.
- Declares compatibility with HPOS and Cart/Checkout blocks.

## Requirements

- WordPress 6.3+
- WooCommerce 8.0+
- PHP 7.4+

## Installation

1. Copy this folder into `wp-content/plugins/woocommerce-cloudflare-turnstile` (or upload a zip of it via **Plugins → Add New → Upload**).
2. Activate **Cloudflare Turnstile for WooCommerce**.
3. In the Cloudflare dashboard, go to **Turnstile → Add widget**, add your domain, and copy the site key and secret key.
4. Go to **WooCommerce → Settings → Integration → Cloudflare Turnstile**, paste the keys, tick **Enable**, choose which forms to protect (checkout is on by default; the My Account forms are opt-in), and save.

### Test keys

| Purpose        | Site key                   | Secret key                            |
| -------------- | -------------------------- | ------------------------------------- |
| Always passes  | `1x00000000000000000000AA` | `1x0000000000000000000000000000000AA` |
| Always fails   | `2x00000000000000000000AB` | `2x0000000000000000000000000000000AA` |

## How it works

| Form               | Where the widget appears                         | How the token is sent                         | Where it is verified                                         |
| ------------------ | ------------------------------------------------ | --------------------------------------------- | ------------------------------------------------------------ |
| Classic shortcode  | Above **Place order** (`woocommerce_review_order_before_submit`) | Hidden `cf-turnstile-response` form field     | `woocommerce_after_checkout_validation`                       |
| Checkout block     | Injected above **Place order**                   | Store API `extensions.cloudflare-turnstile.token` | `woocommerce_store_api_checkout_update_order_from_request` (before payment) |
| My Account login   | `woocommerce_login_form`                         | Hidden `cf-turnstile-response` form field     | `woocommerce_process_login_errors`                            |
| Registration       | `woocommerce_register_form`                      | Hidden `cf-turnstile-response` form field     | `woocommerce_process_registration_errors`                     |
| Lost password      | `woocommerce_lostpassword_form`                  | Hidden `cf-turnstile-response` form field     | `lostpassword_post` (WooCommerce form only)                   |

## Developer hooks

`wcct_should_protect` — filter whether verification is required for a form. `$context` is one of `classic` (shortcode checkout), `store_api` (Checkout block / Store API), `login`, `register` or `lost_password`.

```php
// Example: skip Turnstile for a trusted headless client.
add_filter( 'wcct_should_protect', function ( $protect, $context ) {
	if ( 'store_api' === $context && my_request_is_trusted() ) {
		return false;
	}
	return $protect;
}, 10, 2 );
```

`wcct_should_protect_checkout` — same, but only called for the two checkout contexts.

## Notes

- **Express payment buttons** (Apple Pay / Google Pay via Stripe, PayPal, etc.) that submit the order without the checkout form will be rejected because they carry no Turnstile token. Use the filter above to exempt them if you rely on them.
- The **Pay for order** page, the order-received page and the password **reset** form (reached from the emailed link) are not challenged.
- WordPress's own `wp-login.php` forms are not changed.
- Accounts created during checkout are covered by the checkout check, not the registration setting.
- Turnstile's `api.js` is loaded directly from `challenges.cloudflare.com` as Cloudflare requires; exclude it from JS minify/combine plugins if they rewrite it.

## License

GPL-2.0-or-later
