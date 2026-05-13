# HKDEV SMS Suite

A professional WordPress WooCommerce plugin for SMS gateway integration, OTP verification, and order spam protection.

## Features

- 🔐 **OTP Verification** - Two-factor authentication for checkout process
- 📱 **SMS Gateway Integration** - Support for any SMS provider with flexible API configuration
- 🚫 **Order Blocker** - Prevent duplicate orders and spam
- 📊 **Activity Logging** - Track all SMS transactions
- 💰 **Balance Checking** - Monitor SMS credit balance
- 📨 **Order Notifications** - Automatic SMS on order confirmation and status changes
- 🎯 **Custom Templates** - Flexible message templates with dynamic placeholders
- 📱 **Responsive UI** - Professional admin dashboard with mobile support

## Requirements

- WordPress 5.0+
- WooCommerce 3.0+
- PHP 7.2+

## Installation

1. Download the plugin files
2. Upload the `hkdev-sms-suite` folder to `/wp-content/plugins/`
3. Activate the plugin from WordPress Admin > Plugins
4. Go to HKDEV SMS > General Settings to configure

## Configuration

### Step 1: Add API Credentials

1. Navigate to **HKDEV SMS > General Settings > API Credentials**
2. Enter your SMS provider's:
   - Gateway API URL
   - API Token/Key
   - Sender ID
   - HTTP Method (GET or POST)
   - Parameter names for your provider

### Step 2: Configure OTP Settings

1. Go to **General Settings** tab
2. Enable WooCommerce OTP
3. Set OTP length (4, 5, 6, or 8 digits)
4. Set OTP expiry time and cooldown period

### Step 3: Customize SMS Templates

1. Go to **SMS Templates** tab
2. Edit message templates with available placeholders:
   - `{OTP}` - One-time password
   - `{ORDER_ID}` - Order ID
   - `{CUSTOMER_NAME}` - Customer first name
   - `{ORDER_TOTAL}` - Order amount
   - `{STATUS}` - Order status

### Step 4: Order Blocker Setup (Optional)

1. Go to **Order Blocker** tab
2. Set block duration (days, hours, minutes)
3. Enable combined IP + Phone blocking if desired

## SMS Provider Setup Examples

### Example: Twilio

- **Gateway API URL:** `https://api.twilio.com/2010-04-01/Accounts/{ACCOUNT_SID}/Messages.json`
- **HTTP Method:** POST
- **Token Parameter:** `AuthToken` (use Account Token)
- **Sender Parameter:** `From` (your Twilio phone number)
- **Number Parameter:** `To`
- **Message Parameter:** `Body`

### Example: Nexmo/Vonage

- **Gateway API URL:** `https://rest.nexmo.com/sms/json`
- **HTTP Method:** GET
- **Token Parameter:** `api_key`
- **Sender Parameter:** `from`
- **Number Parameter:** `to`
- **Message Parameter:** `text`

### Example: Local SMS Provider (Bangladesh)

- **Gateway API URL:** `https://api.localprovider.com/send`
- **HTTP Method:** POST or GET
- **Token Parameter:** `token` or `api_key`
- **Sender Parameter:** `sender` or `from`
- **Number Parameter:** `number` or `to`
- **Message Parameter:** `message` or `msg`

## Usage

### Admin Dashboard

The plugin provides a comprehensive admin interface with:

- **Overview Tab:** Quick status and balance information
- **API Credentials Tab:** Configure SMS gateway connection
- **SMS Templates Tab:** Customize message content
- **SMS Logs Tab:** Monitor all SMS activity
- **Order Blocker Tab:** Manage spam prevention settings

### Frontend Integration

The OTP verification modal opens when the customer tries to place the order on checkout/funnel pages. Customers can:

1. Enter their phone number
2. Receive an OTP via SMS
3. Enter the OTP to verify
4. Complete their order

## Available Actions & Filters

### Send Custom SMS

```php
$sms_gateway = new HKDEV_SMS_Gateway();
$result = $sms_gateway->send_sms($phone_number, $message);
```

### Generate OTP

```php
$otp_handler = new HKDEV_OTP_Handler();
$result = $otp_handler->generate_otp($phone_number);
```

### Verify OTP

```php
$otp_handler = new HKDEV_OTP_Handler();
$result = $otp_handler->verify_otp($phone_number, $otp_code);
```

## Database & Transients

The plugin uses WordPress transients for:

- OTP storage with automatic expiry
- Cooldown periods between OTP requests
- IP/Phone blocking with duration-based cleanup
- Balance cache (user-initiated)

No custom database tables are created. All data is stored in WordPress options and transients.

## Security Features

- ✅ Nonce verification on all AJAX requests
- ✅ Capability checks for admin actions
- ✅ Input sanitization and validation
- ✅ XSS protection via `esc_*` functions
- ✅ SQL injection protection via transients/options API
- ✅ CSRF protection with WordPress nonces
- ✅ Secure password field handling

## Troubleshooting

### SMS Not Sending

1. Verify API credentials are correct
2. Check SMS Logs for error messages
3. Test connection using "Test SMS" button
4. Ensure gateway URL is accessible
5. Check internet connectivity on server

### OTP Not Received

1. Verify phone number format is correct
2. Check balance in SMS account
3. Test with a different phone number
4. Review SMS Logs for failures
5. Verify OTP is actually being sent via logs

### Modal Not Appearing

1. Ensure WooCommerce OTP is enabled
2. Check browser console for JavaScript errors
3. Verify Phosphor Icons library is loaded
4. Clear browser cache
5. Test in an incognito window

## Support & Issues

For bugs, feature requests, or support:
- WhatsApp: https://wa.me/8801781115586
- Facebook: https://facebook.com/hayatulla.oop

## License

GPL v3 - See LICENSE file for details

## Changelog

### Version 2.0.0
- Complete rewrite with modular architecture
- New professional admin dashboard
- Enhanced OTP verification system
- Improved order blocker functionality
- Better error handling and logging
- Responsive mobile-friendly UI
- Support for multiple SMS providers

### Version 1.0.0
- Initial release

## Contributors

- **HKDEV** - https://facebook.com/hayatulla.oop

---

Made with ❤️ for WooCommerce developers
