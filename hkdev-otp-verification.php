<?php
/**
 * Plugin Name: HKDEV
 * Description: HKDEV SMS solution for WordPress with failover, logs, and WooCommerce OTP verification.
 * Version: 4.0.0
 * Author: Gemini
 * Text Domain: universal-sms-pro-gateway
 */

if (!defined('ABSPATH')) {
    exit;
}

define('USP_SMS_PLUGIN_FILE', __FILE__);
define('USP_SMS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('USP_SMS_PLUGIN_URL', plugin_dir_url(__FILE__));
define('USP_SMS_PLUGIN_VERSION', '4.0.0');

require_once USP_SMS_PLUGIN_DIR . 'includes/class-hkdev-sms-pro.php';
require_once USP_SMS_PLUGIN_DIR . 'includes/class-hkdev-order-delay-blocker.php';

add_action('plugins_loaded', static function () {
    new HKDEV_SMS_Pro();
    if (
        class_exists('WooCommerce') &&
        class_exists('USP_WC_Order_Delay_Blocker') &&
        get_option('hkdev_enable_order_blocker', 'yes') === 'yes'
    ) {
        new USP_WC_Order_Delay_Blocker();
    }
});
