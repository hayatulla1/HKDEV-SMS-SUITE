<?php

if (!defined('ABSPATH')) {
    exit;
}

class HKDEV_WC_Order_Delay_Blocker {
    
    private const OPTION_DURATION_DAYS = 'usp_wcodb_block_duration_days';
    private const OPTION_DURATION_HOURS = 'usp_wcodb_block_duration_hours';
    private const OPTION_DURATION_MINUTES = 'usp_wcodb_block_duration_minutes';
    private const OPTION_COMBINED_BLOCK = 'usp_wcodb_combined_block_enabled';
    private const OPTION_MANUAL_BLOCKED_LIST = 'usp_wcodb_manual_blocked_list';
    private const OPTION_AUTOMATIC_BLOCK_LOG = 'usp_wcodb_automatic_block_log';

    private $block_transient_prefix = 'wcodb_block_';

    public function __construct() {
        add_action('admin_init', array($this, 'register_settings'));
        add_action('woocommerce_checkout_process', array($this, 'validate_bd_phone'));
        add_action('woocommerce_checkout_process', array($this, 'maybe_block_checkout'));
        add_action('woocommerce_thankyou', array($this, 'set_block_transient'), 10, 1);
    }

    public function register_settings() {
        $group = 'hkdev_settings_group';
        
        register_setting($group, self::OPTION_DURATION_MINUTES, array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 60
        ));
        register_setting($group, self::OPTION_DURATION_HOURS, array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 0
        ));
        register_setting($group, self::OPTION_DURATION_DAYS, array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 0
        ));
        register_setting($group, self::OPTION_COMBINED_BLOCK, array(
            'sanitize_callback' => 'sanitize_text_field'
        ));
    }

    public function validate_bd_phone() {
        if (!$this->is_checkout_nonce_valid()) {
            return;
        }

        $billing_phone = '';

        if (isset($_POST['billing_phone'])) {
            $billing_phone = sanitize_text_field(wp_unslash($_POST['billing_phone']));
        }

        if (empty($billing_phone) && isset($_POST['post_data'])) {
            $post_data = array();
            $post_data_raw = sanitize_text_field(wp_unslash($_POST['post_data']));
            parse_str($post_data_raw, $post_data);
            if (!empty($post_data['billing_phone'])) {
                $billing_phone = sanitize_text_field($post_data['billing_phone']);
            }
        }

        if (empty($billing_phone)) {
            return;
        }

        $billing_phone = hkdev_normalize_phone($billing_phone);
        if (empty($billing_phone)) {
            return;
        }

        // Validate phone is in correct format
        if (!preg_match('/^(?:\+88|88)?01[0-9]{9}$/', $billing_phone)) {
            wc_add_notice(
                __('Please enter a valid Bengali (BD) phone number', HKDEV_TEXT_DOMAIN),
                'error'
            );
        }
    }

    public function maybe_block_checkout() {
        if (!$this->is_checkout_nonce_valid()) {
            return;
        }

        $billing_phone = isset($_POST['billing_phone']) ? sanitize_text_field(wp_unslash($_POST['billing_phone'])) : '';
        $billing_phone = hkdev_normalize_phone($billing_phone);
        $customer_ip = $this->get_customer_ip();

        if (empty($billing_phone) || empty($customer_ip)) {
            return;
        }

        // Check if phone or IP is blocked
        if ($this->is_phone_blocked($billing_phone) || $this->is_ip_blocked($customer_ip)) {
            wc_add_notice(
                __('Your order cannot be processed at this time. Please try again later.', HKDEV_TEXT_DOMAIN),
                'error'
            );
        }
    }

    public function set_block_transient($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $billing_phone = hkdev_normalize_phone($order->get_billing_phone());
        $customer_ip = $this->get_customer_ip();

        if (empty($billing_phone) || empty($customer_ip)) {
            return;
        }

        // Calculate block duration in seconds
        $duration_seconds = $this->calculate_block_duration();

        $combined_block = hkdev_option_is_enabled(self::OPTION_COMBINED_BLOCK, 'off');

        if (!empty($billing_phone)) {
            $phone_key = $this->block_transient_prefix . 'phone_' . md5($billing_phone);
            set_transient($phone_key, true, $duration_seconds);
        }

        if (!empty($customer_ip) && ($combined_block || empty($billing_phone))) {
            $ip_key = $this->block_transient_prefix . 'ip_' . md5($customer_ip);
            set_transient($ip_key, true, $duration_seconds);
        }

        // Log the block
        $this->log_block($billing_phone, $customer_ip, $order_id);
    }

    private function is_phone_blocked($phone) {
        $phone_key = $this->block_transient_prefix . 'phone_' . md5($phone);
        return get_transient($phone_key) !== false;
    }

    private function is_ip_blocked($ip) {
        $ip_key = $this->block_transient_prefix . 'ip_' . md5($ip);
        return get_transient($ip_key) !== false;
    }

    private function get_customer_ip() {
        if (function_exists('wc_get_customer_ip_address')) {
            $ip = wc_get_customer_ip_address();
            if (!empty($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        $candidates = array('REMOTE_ADDR', 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR');

        foreach ($candidates as $candidate) {
            if (empty($_SERVER[$candidate])) {
                continue;
            }

            $ip = wp_unslash($_SERVER[$candidate]);
            if ($candidate === 'HTTP_X_FORWARDED_FOR') {
                $parts = array_map('trim', explode(',', $ip));
                if (!empty($parts)) {
                    $ip = end($parts);
                } else {
                    $ip = '';
                }
            }

            $ip = sanitize_text_field($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return '';
    }

    private function is_checkout_nonce_valid() {
        if (!isset($_POST['woocommerce-process-checkout-nonce'])) {
            return false;
        }

        $nonce = sanitize_text_field(wp_unslash($_POST['woocommerce-process-checkout-nonce']));
        return wp_verify_nonce($nonce, 'woocommerce-process_checkout');
    }

    private function calculate_block_duration() {
        $days = intval(get_option(self::OPTION_DURATION_DAYS, 0));
        $hours = intval(get_option(self::OPTION_DURATION_HOURS, 0));
        $minutes = intval(get_option(self::OPTION_DURATION_MINUTES, 60));

        $seconds = ($days * DAY_IN_SECONDS) + ($hours * HOUR_IN_SECONDS) + ($minutes * MINUTE_IN_SECONDS);

        return max($seconds, 60); // Minimum 60 seconds
    }

    private function log_block($phone, $ip, $order_id) {
        $logs = get_option(self::OPTION_AUTOMATIC_BLOCK_LOG, array());

        if (!is_array($logs)) {
            $logs = array();
        }

        $logs[] = array(
            'phone' => $phone,
            'ip' => $ip,
            'order_id' => $order_id,
            'timestamp' => current_time('Y-m-d H:i:s')
        );

        // Keep only last 500 logs
        $logs = array_slice($logs, -500);

        update_option(self::OPTION_AUTOMATIC_BLOCK_LOG, $logs);
    }


    public function get_block_logs() {
        return get_option(self::OPTION_AUTOMATIC_BLOCK_LOG, array());
    }

    public function clear_block_logs() {
        update_option(self::OPTION_AUTOMATIC_BLOCK_LOG, array());
    }
}
