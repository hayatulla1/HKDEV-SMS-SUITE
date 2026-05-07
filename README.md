# HKDEV_OTP_Verification

Professional WordPress plugin structure for an OTP-enabled universal SMS gateway.

## Plugin Path

`/universal-sms-pro-gateway`

## Structure

- `universal-sms-pro-gateway.php` — bootstrap loader
- `includes/class-universal-sms-pro.php` — core plugin logic (admin, API, OTP, WooCommerce hooks)
- `assets/css/admin.css` — admin UI styles
- `assets/css/frontend.css` — checkout OTP modal styles
- `assets/js/frontend.js` — checkout OTP interactions and AJAX calls

## Features

- Any SMS API support with configurable parameter mapping
- Multiple sender IDs with failover
- OTP verification for selected WooCommerce products
- Order confirmation and status SMS notifications
- WooCommerce order delay blocker (IP/phone, manual block, automatic logs)
- Admin logs (last 50 requests)
- Separate PHP/CSS/JS files with cleaner organization
