<?php
/**
 * Plugin Name: HKDEV SMS Suite
 * Description: Professional SMS, OTP, Order Blocker & Free Delivery toolkit for WooCommerce
 * Version: 3.0.0
 * Author: HKDEV
 * Author URI: https://facebook.com/hayatulla.oop
 * License: GPL v3
 * Text Domain: hkdev-sms-suite
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

define('HKDEV_PLUGIN_FILE', __FILE__);
define('HKDEV_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HKDEV_PLUGIN_URL', plugin_dir_url(__FILE__));
define('HKDEV_PLUGIN_VERSION', '3.0.0');
define('HKDEV_TEXT_DOMAIN', 'hkdev-sms-suite');

function hkdev_option_is_enabled($option_name, $default = 'yes') {
    $value = get_option($option_name, $default);
    return in_array($value, array('yes', 'on', '1', 1, true), true);
}

function hkdev_normalize_phone($phone_number) {
    $phone_number = sanitize_text_field((string) $phone_number);
    return preg_replace('/[^0-9+]/', '', $phone_number);
}

register_activation_hook(__FILE__, 'hkdev_plugin_activate');
register_deactivation_hook(__FILE__, 'hkdev_plugin_deactivate');

function hkdev_plugin_activate() {
    if (!get_option('hkdev_plugin_activated')) {
        update_option('hkdev_plugin_activated', true);
        update_option('hkdev_enable_gateway', 'yes');
        update_option('hkdev_enable_otp', 'yes');
        update_option('hkdev_enable_logs', 'yes');
        update_option('hkdev_otp_length', 6);
        update_option('hkdev_otp_expiry_minutes', 10);
        update_option('hkdev_otp_cooldown_seconds', 60);
        update_option('sib_sms_logs', array());
        update_option('hkdev_active_blocks', array());
        update_option('hkdev_block_logs', array());
    }
}

function hkdev_plugin_deactivate() {
    // Cleanup
}

require_once HKDEV_PLUGIN_DIR . 'includes/class-hkdev-sms-gateway.php';
require_once HKDEV_PLUGIN_DIR . 'includes/class-hkdev-otp-handler.php';
require_once HKDEV_PLUGIN_DIR . 'includes/class-hkdev-sms-pro.php';
require_once HKDEV_PLUGIN_DIR . 'includes/class-hkdev-order-delay-blocker.php';
require_once HKDEV_PLUGIN_DIR . 'includes/class-hkdev-free-delivery.php';

add_action('plugins_loaded', 'hkdev_initialize_plugin', 10);

function hkdev_initialize_plugin() {
    if (!function_exists('is_plugin_active') || !is_plugin_active('woocommerce/woocommerce.php')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>';
            echo __('HKDEV SMS Suite requires WooCommerce to be installed and active.', HKDEV_TEXT_DOMAIN);
            echo '</p></div>';
        });
        return;
    }

    new HKDEV_SMS_Pro();

    if (hkdev_option_is_enabled('hkdev_enable_order_blocker', 'yes')) {
        new HKDEV_WC_Order_Delay_Blocker();
    }

    HKDEV_Free_Delivery_Module::instance();
}

add_action('init', function() {
    load_plugin_textdomain(HKDEV_TEXT_DOMAIN, false, dirname(plugin_basename(__FILE__)) . '/languages');
});
