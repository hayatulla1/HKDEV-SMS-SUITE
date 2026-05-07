<?php
/**
 * Plugin Name: Universal SMS Pro Gateway (Any Provider & OTP)
 * Description: Multi-provider SMS solution for WordPress with failover, logs, and WooCommerce OTP verification.
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

require_once USP_SMS_PLUGIN_DIR . 'includes/class-universal-sms-pro.php';

add_action('plugins_loaded', static function () {
    new Universal_SMS_Pro();
});
