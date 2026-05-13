<?php

if (!defined('ABSPATH')) {
    exit;
}

class HKDEV_WC_Order_Delay_Blocker {

    private const OPTION_DURATION_DAYS    = 'usp_wcodb_block_duration_days';
    private const OPTION_DURATION_HOURS   = 'usp_wcodb_block_duration_hours';
    private const OPTION_DURATION_MINUTES = 'usp_wcodb_block_duration_minutes';
    private const OPTION_COMBINED_BLOCK   = 'usp_wcodb_combined_block_enabled';
    private const OPTION_ACTIVE_BLOCKS    = 'hkdev_active_blocks';
    private const OPTION_BLOCK_LOGS       = 'hkdev_block_logs';

    private $block_transient_prefix = 'wcodb_block_';

    public function __construct() {
        add_action('admin_init', array($this, 'register_settings'));
        add_action('woocommerce_checkout_process', array($this, 'validate_bd_phone'));
        add_action('woocommerce_checkout_process', array($this, 'maybe_block_checkout'));
        add_action('woocommerce_thankyou', array($this, 'set_block_transient'), 10, 1);

        // AJAX handlers
        add_action('wp_ajax_hkdev_unblock_user',     array($this, 'ajax_unblock_user'));
        add_action('wp_ajax_hkdev_clear_all_blocks', array($this, 'ajax_clear_all_blocks'));
        add_action('wp_ajax_hkdev_clear_block_logs', array($this, 'ajax_clear_block_logs'));
    }

    public function register_settings() {
        $group = 'hkdev_settings_group';
        register_setting($group, self::OPTION_DURATION_MINUTES, array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 60));
        register_setting($group, self::OPTION_DURATION_HOURS,   array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0));
        register_setting($group, self::OPTION_DURATION_DAYS,    array('type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0));
        register_setting($group, self::OPTION_COMBINED_BLOCK,   array('sanitize_callback' => 'sanitize_text_field'));
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
            $post_data     = array();
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
        $customer_ip   = $this->get_customer_ip();

        if (empty($billing_phone) || empty($customer_ip)) {
            return;
        }

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
        $customer_ip   = $this->get_customer_ip();
        $user_agent    = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';

        if (empty($billing_phone) || empty($customer_ip)) {
            return;
        }

        $duration_seconds      = $this->calculate_block_duration();
        $enable_combined_block = hkdev_option_is_enabled(self::OPTION_COMBINED_BLOCK, 'off');

        // Set phone transient
        $phone_key = $this->block_transient_prefix . 'phone_' . md5($billing_phone);
        set_transient($phone_key, true, $duration_seconds);

        // Set IP transient if combined block enabled
        if ($enable_combined_block) {
            $ip_key = $this->block_transient_prefix . 'ip_' . md5($customer_ip);
            set_transient($ip_key, true, $duration_seconds);
        }

        // Build rich block entry
        $now        = current_time('Y-m-d H:i:s');
        $expires_at = gmdate('Y-m-d H:i:s', time() + $duration_seconds);

        $entry = array(
            'id'         => uniqid('blk_', true),
            'name'       => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'phone'      => $billing_phone,
            'ip'         => $customer_ip,
            'order_id'   => $order_id,
            'user_agent' => $user_agent,
            'device'     => $this->detect_device($user_agent),
            'browser'    => $this->detect_browser($user_agent),
            'os'         => $this->detect_os($user_agent),
            'blocked_at' => $now,
            'expires_at' => $expires_at,
            'reason'     => 'auto_block',
        );

        // Save to active blocks list
        $active = get_option(self::OPTION_ACTIVE_BLOCKS, array());
        if (!is_array($active)) {
            $active = array();
        }
        $active[] = $entry;
        update_option(self::OPTION_ACTIVE_BLOCKS, $active);

        // Log the block event
        $this->append_block_log(array_merge($entry, array('event' => 'blocked', 'timestamp' => $now)));
    }

    // Device / browser / OS detection helpers
    private function detect_device($ua) {
        if (preg_match('/tablet|ipad/i', $ua)) {
            return 'tablet';
        }
        if (preg_match('/mobile|android|iphone|ipod|blackberry|windows phone/i', $ua)) {
            return 'mobile';
        }
        return 'desktop';
    }

    private function detect_browser($ua) {
        if (preg_match('/edg\//i', $ua))        return 'Edge';
        if (preg_match('/opr\//i', $ua))         return 'Opera';
        if (preg_match('/chrome/i', $ua))        return 'Chrome';
        if (preg_match('/safari/i', $ua))        return 'Safari';
        if (preg_match('/firefox/i', $ua))       return 'Firefox';
        if (preg_match('/trident|msie/i', $ua))  return 'IE';
        return 'Unknown';
    }

    private function detect_os($ua) {
        if (preg_match('/windows nt/i', $ua))         return 'Windows';
        if (preg_match('/macintosh|mac os x/i', $ua)) return 'macOS';
        if (preg_match('/iphone|ipad|ipod/i', $ua))   return 'iOS';
        if (preg_match('/android/i', $ua))            return 'Android';
        if (preg_match('/linux/i', $ua))              return 'Linux';
        return 'Unknown';
    }

    // AJAX: Unblock a specific user
    public function ajax_unblock_user() {
        check_ajax_referer('hkdev_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $block_id = isset($_POST['block_id']) ? sanitize_text_field($_POST['block_id']) : '';
        if (empty($block_id)) {
            wp_send_json_error('Invalid block ID');
        }

        $active = get_option(self::OPTION_ACTIVE_BLOCKS, array());
        $found  = null;

        foreach ($active as $key => $entry) {
            if ($entry['id'] === $block_id) {
                $found = $entry;
                delete_transient($this->block_transient_prefix . 'phone_' . md5($entry['phone']));
                delete_transient($this->block_transient_prefix . 'ip_'    . md5($entry['ip']));
                unset($active[$key]);
                break;
            }
        }

        if (!$found) {
            wp_send_json_error('Block entry not found');
        }

        update_option(self::OPTION_ACTIVE_BLOCKS, array_values($active));

        $this->append_block_log(array(
            'id'        => uniqid('log_', true),
            'name'      => $found['name'],
            'phone'     => $found['phone'],
            'ip'        => $found['ip'],
            'device'    => $found['device'],
            'event'     => 'unblocked',
            'reason'    => 'manual_unblock',
            'timestamp' => current_time('Y-m-d H:i:s'),
        ));

        wp_send_json_success('User unblocked successfully');
    }

    // AJAX: Clear all blocks
    public function ajax_clear_all_blocks() {
        check_ajax_referer('hkdev_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $active = get_option(self::OPTION_ACTIVE_BLOCKS, array());
        foreach ($active as $entry) {
            delete_transient($this->block_transient_prefix . 'phone_' . md5($entry['phone']));
            delete_transient($this->block_transient_prefix . 'ip_'    . md5($entry['ip']));
        }
        update_option(self::OPTION_ACTIVE_BLOCKS, array());

        wp_send_json_success('All blocks cleared');
    }

    // AJAX: Clear block logs
    public function ajax_clear_block_logs() {
        check_ajax_referer('hkdev_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        update_option(self::OPTION_BLOCK_LOGS, array());
        wp_send_json_success('Block logs cleared');
    }

    // Static helper: get active blocks (filters expired entries by transient check)
    public static function get_active_blocks_static() {
        $prefix = 'wcodb_block_';
        $active = get_option(self::OPTION_ACTIVE_BLOCKS, array());
        $valid  = array();

        foreach ($active as $entry) {
            $phone_key = $prefix . 'phone_' . md5($entry['phone']);
            if (get_transient($phone_key) !== false) {
                $valid[] = $entry;
            }
        }

        if (count($valid) !== count($active)) {
            update_option(self::OPTION_ACTIVE_BLOCKS, $valid);
        }

        return $valid;
    }

    private function append_block_log($entry) {
        $logs = get_option(self::OPTION_BLOCK_LOGS, array());
        if (!is_array($logs)) {
            $logs = array();
        }

        if (!isset($entry['timestamp'])) {
            $entry['timestamp'] = current_time('Y-m-d H:i:s');
        }

        array_unshift($logs, $entry);
        $logs = array_slice($logs, 0, 500);
        update_option(self::OPTION_BLOCK_LOGS, $logs);
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

        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
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
        $days    = intval(get_option(self::OPTION_DURATION_DAYS, 0));
        $hours   = intval(get_option(self::OPTION_DURATION_HOURS, 0));
        $minutes = intval(get_option(self::OPTION_DURATION_MINUTES, 60));
        $seconds = ($days * DAY_IN_SECONDS) + ($hours * HOUR_IN_SECONDS) + ($minutes * MINUTE_IN_SECONDS);
        return max($seconds, 60);
    }
}
