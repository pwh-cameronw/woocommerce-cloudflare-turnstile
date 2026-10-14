# Cloudflare Turnstile for WooCommerce Checkout

A WordPress plugin that adds [Cloudflare Turnstile](https://developers.cloudflare.com/turnstile/) to the WooCommerce checkout, blocking bots and card-testing attacks without a CAPTCHA puzzle.

## Features

- Works with both the **classic (shortcode) checkout** and the **Checkout block**.
- Protects the **Store API** checkout endpoint (`/wp-json/wc/store/v1/checkout`), which bots can call directly even on sites that use the classic checkout.
- Server-side verification through Cloudflare's Siteverify API, bound to a dedicated `wc-checkout` action.
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
2. Activate **Cloudflare Turnstile for WooCommerce Checkout**.
3. In the Cloudflare dashboard, go to **Turnstile → Add widget**, add your domain, and copy the site key and secret key.
4. Go to **WooCommerce → Settings → Integration → Cloudflare Turnstile**, paste the keys, tick **Enable**, and save.

### Test keys

| Purpose        | Site key                   | Secret key                            |
| -------------- | -------------------------- | ------------------------------------- |
| Always passes  | `1x00000000000000000000AA` | `1x0000000000000000000000000000000AA` |
| Always fails   | `2x00000000000000000000AB` | `2x0000000000000000000000000000000AA` |

## How it works

| Checkout           | Where the widget appears                         | How the token is sent                         | Where it is verified                                         |
| ------------------ | ------------------------------------------------ | --------------------------------------------- | ------------------------------------------------------------ |
| Classic shortcode  | Above **Place order** (`woocommerce_review_order_before_submit`) | Hidden `cf-turnstile-response` form field     | `woocommerce_after_checkout_validation`                       |
| Checkout block     | Injected above **Place order**                   | Store API `extensions.cloudflare-turnstile.token` | `woocommerce_store_api_checkout_update_order_from_request` (before payment) |

## Developer hooks

`wcct_should_protect_checkout` — filter whether verification is required for the current request.

```php
// Example: skip Turnstile for a trusted headless client.
add_filter( 'wcct_should_protect_checkout', function ( $protect, $context ) {
	if ( 'store_api' === $context && my_request_is_trusted() ) {
		return false;
	}
	return $protect;
}, 10, 2 );
```

`$context` is `classic` or `store_api`.

## Notes

- **Express payment buttons** (Apple Pay / Google Pay via Stripe, PayPal, etc.) that submit the order without the checkout form will be rejected because they carry no Turnstile token. Use the filter above to exempt them if you rely on them.
- The **Pay for order** page and the order-received page are not challenged.
- Turnstile's `api.js` is loaded directly from `challenges.cloudflare.com` as Cloudflare requires; exclude it from JS minify/combine plugins if they rewrite it.

## License

GPL-2.0-or-later
