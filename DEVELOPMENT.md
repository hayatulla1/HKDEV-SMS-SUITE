# HKDEV SMS Suite - Developer Documentation

## Plugin Architecture

### Directory Structure

```
hkdev-sms-suite/
├── hkdev-sms-suite.php          # Main plugin file
├── README.md                     # User documentation
├── DEVELOPMENT.md                # This file
├── includes/
│   ├── class-hkdev-sms-gateway.php       # SMS gateway API handling
│   ├── class-hkdev-otp-handler.php       # OTP generation & verification
│   ├── class-hkdev-sms-pro.php           # Main plugin class
│   └── class-hkdev-order-delay-blocker.php # Order spam protection
├── views/
│   ├── admin-dashboard.php      # Admin interface
│   └── frontend-modal.php        # OTP modal for customers
├── assets/
│   ├── css/
│   │   ├── admin.css           # Admin dashboard styles
│   │   └── frontend.css        # Frontend modal styles
│   └── js/
│       ├── admin.js            # Admin dashboard functionality
│       └── frontend.js         # OTP modal functionality
└── languages/                   # Translation files
```

## Class Overview

### HKDEV_SMS_Gateway

Handles all SMS API communication and gateway operations.

**Key Methods:**

```php
// Send SMS to phone number
send_sms($phone_number, $message): array

// Check account balance
check_balance(): array

// Get SMS logs
get_logs(): array

// Clear all logs
clear_logs(): void

// Check if gateway is configured
is_configured(): bool
```

**Properties:**

- `$gateway_url` - SMS provider API endpoint
- `$api_token` - API authentication token
- `$sender_id` - Sender ID/name for SMS
- `$http_method` - GET or POST request method
- `$param_*` - Parameter names for API calls

### HKDEV_OTP_Handler

Manages OTP generation, storage, and verification.

**Key Methods:**

```php
// Generate new OTP for phone
generate_otp($phone_number): array

// Verify OTP code
verify_otp($phone_number, $otp_code): array

// Check if phone is verified
is_phone_verified($phone_number): bool

// Get OTP configuration
get_otp_config(): array
```

**Transient Keys:**

- `hkdev_otp_{phone_hash}` - Stores generated OTP
- `hkdev_otp_attempts_{phone_hash}` - Rate limiting
- `hkdev_verified_phone_{user_hash}` - Verified status
- `{cooldown}_{phone_hash}` - Cooldown period

### HKDEV_SMS_Pro

Main plugin class handling admin interface and WooCommerce integration.

**Key Methods:**

```php
// Register admin menu and pages
register_admin_menu(): void

// Register all plugin settings
register_plugin_settings(): void

// Enqueue admin assets
enqueue_admin_assets($hook): void

// Enqueue frontend assets
enqueue_frontend_assets(): void

// Render admin dashboard
render_admin_dashboard(): void

// AJAX: Send OTP
ajax_send_otp(): void

// AJAX: Verify OTP
ajax_verify_otp(): void

// WooCommerce: Validate OTP at checkout
validate_checkout_otp(): void

// WooCommerce: Send order confirmation SMS
send_order_confirmation_sms($order_id): void

// WooCommerce: Send status update SMS
send_status_update_sms($order_id, $old, $new, $order): void
```

### HKDEV_WC_Order_Delay_Blocker

Handles duplicate order prevention and spam protection.

**Key Methods:**

```php
// Check if phone is currently blocked
is_phone_blocked($phone): bool

// Check if IP is currently blocked
is_ip_blocked($ip): bool

// Set block transient after order
set_block_transient($order_id): void

// Get all block logs
get_block_logs(): array

// Clear block logs
clear_block_logs(): void
```

**Transient Keys:**

- `wcodb_block_phone_{hash}` - Phone number blocks
- `wcodb_block_ip_{hash}` - IP address blocks

## WordPress Integration

### Hooks Used

**Actions:**

```php
// Plugin initialization
plugins_loaded
admin_menu
admin_init
admin_enqueue_scripts
wp_enqueue_scripts
wp_footer

// AJAX
wp_ajax_hkdev_send_otp
wp_ajax_nopriv_hkdev_send_otp
wp_ajax_hkdev_verify_otp
wp_ajax_nopriv_hkdev_verify_otp
wp_ajax_hkdev_test_sms
wp_ajax_hkdev_check_balance
wp_ajax_hkdev_clear_logs

// WooCommerce
woocommerce_checkout_process
woocommerce_thankyou
woocommerce_order_status_changed
```

### Options (Settings)

All plugin settings are stored as WordPress options:

**Gateway Configuration:**
- `sib_gateway_url` - API endpoint URL
- `sib_api_token` - API authentication
- `sib_sender_id` - SMS sender identifier
- `sib_http_method` - Request method
- `sib_param_*` - Parameter names
- `hkdev_balance_api_url` - Balance check endpoint
- `hkdev_balance_response_key` - Balance response key

**Feature Toggles:**
- `hkdev_enable_gateway` - Master SMS toggle
- `hkdev_enable_otp` - OTP verification
- `hkdev_enable_order_confirmation_sms` - Order SMS
- `hkdev_enable_status_sms` - Status updates
- `hkdev_enable_logs` - Activity logging
- `hkdev_enable_order_blocker` - Spam protection

**OTP Settings:**
- `hkdev_otp_length` - OTP digits (4-8)
- `hkdev_otp_expiry_minutes` - Validity period
- `hkdev_otp_cooldown_seconds` - Rate limit

**Templates & Logs:**
- `sib_otp_template` - OTP message template
- `sib_order_template` - Order confirmation template
- `sib_status_template` - Status update template
- `sib_target_products` - Product IDs requiring OTP
- `sib_sms_logs` - SMS transaction logs
- `hkdev_balance_cache` - Cached balance info

## Extending the Plugin

### Creating a Custom SMS Provider

1. Create a child class of `HKDEV_SMS_Gateway`:

```php
class My_SMS_Gateway extends HKDEV_SMS_Gateway {
    public function send_sms($phone_number, $message) {
        // Custom implementation
        return parent::send_sms($phone_number, $message);
    }
}
```

2. Use in your code:

```php
$gateway = new My_SMS_Gateway();
$result = $gateway->send_sms($phone, $message);
```

### Creating Custom OTP Logic

```php
class My_OTP_Handler extends HKDEV_OTP_Handler {
    public function generate_otp($phone_number) {
        // Custom OTP generation logic
        $otp = $this->custom_generate();
        return [
            'success' => true,
            'otp' => $otp
        ];
    }
}
```

### Adding Custom Admin Settings

1. Register your setting:

```php
add_action('admin_init', function() {
    register_setting('hkdev_settings_general_group', 'my_custom_option');
});
```

2. Add to admin dashboard view:

```php
<div class="form-group">
    <label>My Custom Option</label>
    <input type="text" name="my_custom_option" value="<?php echo esc_attr(get_option('my_custom_option')); ?>">
</div>
```

### Adding Custom AJAX Endpoints

```php
add_action('wp_ajax_my_custom_action', function() {
    check_ajax_referer('hkdev_admin_nonce', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized');
    }
    // Your logic
    wp_send_json_success(['data' => 'value']);
});
```

### Custom SMS Notification

```php
add_action('woocommerce_order_status_changed', function($order_id, $old_status, $new_status, $order) {
    if ($new_status === 'completed') {
        $sms_gateway = new HKDEV_SMS_Gateway();
        $phone = $order->get_billing_phone();
        $message = 'Your order has been shipped!';
        $sms_gateway->send_sms($phone, $message);
    }
}, 20, 4);
```

## Database

The plugin uses only **WordPress options** and **transients**:

- **Options:** Store plugin configuration and logs
- **Transients:** Store temporary data (OTP, blocks) with auto-expiry

**No custom database tables are created.**

## Security Best Practices

1. **Always validate and sanitize input:**
   ```php
   $phone = sanitize_text_field($_POST['phone']);
   $url = esc_url_raw($url);
   ```

2. **Use nonces for AJAX:**
   ```php
   check_ajax_referer('nonce_action', 'nonce_param');
   ```

3. **Check capabilities:**
   ```php
   if (!current_user_can('manage_options')) {
       return;
   }
   ```

4. **Escape output:**
   ```php
   echo esc_html($value);
   echo esc_attr($value);
   echo esc_textarea($value);
   ```

## Performance Optimization

1. **Cache frequently accessed data:**
   ```php
   $balance = get_transient('balance_cache');
   ```

2. **Limit log retention:**
   - Logs are limited to 1000 entries
   - Oldest entries are automatically removed

3. **Use transients for expiring data:**
   - OTP automatically expires
   - Blocks automatically lift
   - Cooldowns automatically reset

## Testing

### Manual Testing

1. **Test OTP Generation:**
   - Send OTP to test number
   - Verify it arrives within seconds
   - Check OTP logs for success

2. **Test OTP Verification:**
   - Generate OTP
   - Enter correct code → Should succeed
   - Enter wrong code → Should fail
   - Test cooldown period

3. **Test Order Blocking:**
   - Place order from IP
   - Try to place another → Should be blocked
   - Wait for block duration to expire
   - Should be able to order again

### Automated Testing

```php
// Test SMS Gateway
$gateway = new HKDEV_SMS_Gateway();
$result = $gateway->send_sms('+880xxxxxxxxxx', 'Test message');
assert($result['success']);

// Test OTP
$otp_handler = new HKDEV_OTP_Handler();
$otp_result = $otp_handler->generate_otp('+880xxxxxxxxxx');
$verify_result = $otp_handler->verify_otp('+880xxxxxxxxxx', $otp_result['otp']);
assert($verify_result['success']);
```

## Common Issues & Solutions

### OTP Not Sending

**Cause:** Gateway not configured or API error

**Solution:**
1. Check `sib_gateway_url` and `sib_api_token`
2. Review SMS Logs for specific error
3. Test with Test SMS button
4. Verify API credentials with provider

### Duplicate Logs

**Cause:** Multiple SMS sending from same event

**Solution:**
1. Check for duplicate action hooks
2. Remove conflicting plugins
3. Verify WooCommerce settings

### Transient Not Expiring

**Cause:** WordPress transients cache plugin

**Solution:**
1. Check cache plugin settings
2. Manually clear cache
3. Consider disabling cache during testing

## Contributing

To contribute improvements:

1. Follow existing code style
2. Use proper PHP documentation comments
3. Add security checks for new features
4. Test thoroughly before submitting
5. Document your changes

## License

GPL v3 - See LICENSE file
