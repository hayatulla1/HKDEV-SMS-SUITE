<?php

if (!defined('ABSPATH')) {
    exit;
}

class HKDEV_SMS_Pro {
    
    private $sms_gateway;
    private $otp_handler;

    public function __construct() {
        $this->sms_gateway = new HKDEV_SMS_Gateway();
        $this->otp_handler = new HKDEV_OTP_Handler();

        // Admin Hooks
        add_action('admin_menu', array($this, 'register_admin_menu'));
        add_action('admin_init', array($this, 'register_plugin_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // Frontend Hooks
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('wp_footer', array($this, 'render_frontend_modal'));

        // AJAX Hooks
        add_action('wp_ajax_hkdev_send_otp', array($this, 'ajax_send_otp'));
        add_action('wp_ajax_nopriv_hkdev_send_otp', array($this, 'ajax_send_otp'));
        add_action('wp_ajax_hkdev_verify_otp', array($this, 'ajax_verify_otp'));
        add_action('wp_ajax_nopriv_hkdev_verify_otp', array($this, 'ajax_verify_otp'));
        add_action('wp_ajax_hkdev_test_sms', array($this, 'ajax_test_sms'));
        add_action('wp_ajax_hkdev_check_balance', array($this, 'ajax_check_balance'));
        add_action('wp_ajax_hkdev_clear_logs', array($this, 'ajax_clear_logs'));

        // WooCommerce Hooks
        add_action('woocommerce_checkout_process', array($this, 'validate_checkout_otp'));
        add_action('woocommerce_thankyou', array($this, 'send_order_confirmation_sms'), 10, 1);
        add_action('woocommerce_order_status_changed', array($this, 'send_status_update_sms'), 10, 4);
    }

    public function register_admin_menu() {
        add_menu_page(
            __('HKDEV SMS Suite', HKDEV_TEXT_DOMAIN),
            __('HKDEV SMS', HKDEV_TEXT_DOMAIN),
            'manage_options',
            'hkdev-sms-suite',
            array($this, 'render_admin_dashboard'),
            'dashicons-smartphone',
            56
        );
    }

    public function register_plugin_settings() {
        $group = 'hkdev_settings_group';

        // API Settings
        register_setting($group, 'sib_gateway_url', array('sanitize_callback' => 'esc_url_raw'));
        register_setting($group, 'sib_api_token', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting($group, 'sib_sender_id', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting($group, 'sib_http_method', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting($group, 'sib_param_token', array('sanitize_callback' => 'sanitize_key'));
        register_setting($group, 'sib_param_sender', array('sanitize_callback' => 'sanitize_key'));
        register_setting($group, 'sib_param_number', array('sanitize_callback' => 'sanitize_key'));
        register_setting($group, 'sib_param_msg', array('sanitize_callback' => 'sanitize_key'));
        register_setting($group, 'hkdev_balance_api_url', array('sanitize_callback' => 'esc_url_raw'));
        register_setting($group, 'hkdev_balance_response_key', array('sanitize_callback' => 'sanitize_text_field'));

        // Features & Toggles
        register_setting($group, 'hkdev_enable_gateway', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting($group, 'hkdev_enable_otp', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting($group, 'hkdev_enable_order_confirmation_sms', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting($group, 'hkdev_enable_status_sms', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting($group, 'hkdev_enable_logs', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting($group, 'hkdev_enable_order_blocker', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting($group, 'hkdev_otp_length', array('sanitize_callback' => 'absint', 'default' => 6));

        // SMS Templates
        register_setting($group, 'sib_target_products', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting($group, 'sib_otp_template', array('sanitize_callback' => 'wp_kses_post'));
        register_setting($group, 'sib_order_template', array('sanitize_callback' => 'wp_kses_post'));
        register_setting($group, 'sib_status_template', array('sanitize_callback' => 'wp_kses_post'));
        register_setting($group, 'hkdev_otp_expiry_minutes', array('sanitize_callback' => 'absint', 'default' => 10));
        register_setting($group, 'hkdev_otp_cooldown_seconds', array('sanitize_callback' => 'absint', 'default' => 60));
    }

    public function enqueue_admin_assets($hook) {
        if ($hook !== 'toplevel_page_hkdev-sms-suite') {
            return;
        }

        wp_enqueue_script('phosphor-icons', 'https://unpkg.com/@phosphor-icons/web@2.0.2', array(), null, true);
        wp_enqueue_style('hkdev-admin-css', HKDEV_PLUGIN_URL . 'assets/css/admin.css', array(), HKDEV_PLUGIN_VERSION);
        wp_enqueue_script('hkdev-admin-js', HKDEV_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), HKDEV_PLUGIN_VERSION, true);

        wp_localize_script('hkdev-admin-js', 'hkdevAjax', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hkdev_admin_nonce')
        ));
    }

    public function enqueue_frontend_assets() {
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }

        if (!$this->is_otp_needed()) {
            return;
        }

        wp_enqueue_script('phosphor-icons', 'https://unpkg.com/@phosphor-icons/web@2.0.2', array(), null, true);
        wp_enqueue_style('hkdev-frontend-css', HKDEV_PLUGIN_URL . 'assets/css/frontend.css', array(), HKDEV_PLUGIN_VERSION);
        wp_enqueue_script('hkdev-frontend-js', HKDEV_PLUGIN_URL . 'assets/js/frontend.js', array('jquery'), HKDEV_PLUGIN_VERSION, true);

        wp_localize_script('hkdev-frontend-js', 'hkdevFrontendAjax', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hkdev_otp_nonce'),
            'otpLength' => get_option('hkdev_otp_length', 6),
            'cooldown' => get_option('hkdev_otp_cooldown_seconds', 60)
        ));
    }

    public function render_admin_dashboard() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', HKDEV_TEXT_DOMAIN));
        }

        $logs = $this->sms_gateway->get_logs();
        $balance_cache = get_option('hkdev_balance_cache', array('amount' => 'Not Checked', 'checked_at' => ''));

        include HKDEV_PLUGIN_DIR . 'views/admin-dashboard.php';
    }

    public function render_frontend_modal() {
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }

        if (!$this->is_otp_needed()) {
            return;
        }

        include HKDEV_PLUGIN_DIR . 'views/frontend-modal.php';
    }

    private function is_otp_needed() {
        if (!hkdev_option_is_enabled('hkdev_enable_otp', 'yes')) {
            return false;
        }

        if (is_user_logged_in()) {
            return false;
        }

        if (!function_exists('WC') || !WC()->cart) {
            return false;
        }

        $targets = array_unique(array_filter(
            array_map('absint', explode(',', get_option('sib_target_products', ''))),
            static function ($id) {
                return $id > 0;
            }
        ));
        if (empty($targets)) {
            return false;
        }

        foreach (WC()->cart->get_cart() as $item) {
            $product_id = isset($item['product_id']) ? absint($item['product_id']) : 0;
            $variation_id = isset($item['variation_id']) ? absint($item['variation_id']) : 0;

            if (
                ($product_id > 0 && in_array($product_id, $targets, true)) ||
                ($variation_id > 0 && in_array($variation_id, $targets, true))
            ) {
                return true;
            }
        }

        return false;
    }

    // AJAX: Send OTP
    public function ajax_send_otp() {
        check_ajax_referer('hkdev_otp_nonce', 'nonce');

        if (!hkdev_option_is_enabled('hkdev_enable_otp', 'yes')) {
            wp_send_json_error(__('OTP verification is not enabled.', HKDEV_TEXT_DOMAIN));
            return;
        }

        $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';

        if (empty($phone)) {
            wp_send_json_error(__('Phone number is required', HKDEV_TEXT_DOMAIN));
        }

        // Generate OTP
        $otp_result = $this->otp_handler->generate_otp($phone);

        if (!$otp_result['success']) {
            wp_send_json_error($otp_result['message']);
        }

        $otp = $otp_result['otp'];

        // Prepare SMS message
        $template = get_option('sib_otp_template', 'Your OTP is: {OTP}');
        $message = str_replace('{OTP}', $otp, $template);

        // Send SMS
        $sms_result = $this->sms_gateway->send_sms($phone, $message);

        if (!$sms_result['success']) {
            wp_send_json_error($sms_result['message']);
        }

        wp_send_json_success(array(
            'message' => __('OTP sent successfully', HKDEV_TEXT_DOMAIN),
            'expiry' => $otp_result['expiry_minutes']
        ));
    }

    // AJAX: Verify OTP
    public function ajax_verify_otp() {
        check_ajax_referer('hkdev_otp_nonce', 'nonce');

        if (!hkdev_option_is_enabled('hkdev_enable_otp', 'yes')) {
            wp_send_json_error(__('OTP verification is not enabled.', HKDEV_TEXT_DOMAIN));
            return;
        }

        $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
        $otp = isset($_POST['otp']) ? sanitize_text_field($_POST['otp']) : '';

        if (empty($phone) || empty($otp)) {
            wp_send_json_error(__('Phone and OTP are required', HKDEV_TEXT_DOMAIN));
        }

        $result = $this->otp_handler->verify_otp($phone, $otp);

        if (!$result['success']) {
            wp_send_json_error($result['message']);
        }

        wp_send_json_success($result['message']);
    }

    // AJAX: Test SMS
    public function ajax_test_sms() {
        check_ajax_referer('hkdev_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', HKDEV_TEXT_DOMAIN));
        }

        $phone = isset($_POST['phone']) ? sanitize_text_field($_POST['phone']) : '';
        $message = isset($_POST['message']) ? sanitize_textarea_field($_POST['message']) : 'Test SMS from HKDEV SMS Suite';

        if (empty($phone)) {
            wp_send_json_error(__('Phone number is required', HKDEV_TEXT_DOMAIN));
        }

        $result = $this->sms_gateway->send_sms($phone, $message);

        if ($result['success']) {
            wp_send_json_success($result['message']);
        } else {
            wp_send_json_error($result['message']);
        }
    }

    // AJAX: Check Balance
    public function ajax_check_balance() {
        check_ajax_referer('hkdev_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', HKDEV_TEXT_DOMAIN));
        }

        $result = $this->sms_gateway->check_balance();

        if ($result['success']) {
            wp_send_json_success(array('amount' => $result['amount']));
        } else {
            wp_send_json_error($result['message']);
        }
    }

    // AJAX: Clear Logs
    public function ajax_clear_logs() {
        check_ajax_referer('hkdev_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', HKDEV_TEXT_DOMAIN));
        }

        $this->sms_gateway->clear_logs();
        wp_send_json_success(__('Logs cleared successfully', HKDEV_TEXT_DOMAIN));
    }

    // WooCommerce: Validate Checkout OTP
    public function validate_checkout_otp() {
        if (!$this->is_otp_needed()) {
            return;
        }

        $phone = isset($_POST['billing_phone']) ? sanitize_text_field(wp_unslash($_POST['billing_phone'])) : '';

        if (empty($phone) && isset($_POST['post_data'])) {
            $post_data = array();
            $post_data_raw = sanitize_text_field(wp_unslash($_POST['post_data']));
            parse_str($post_data_raw, $post_data);
            if (!empty($post_data['billing_phone'])) {
                $phone = sanitize_text_field($post_data['billing_phone']);
            }
        }

        if (empty($phone)) {
            return;
        }

        if (!$this->otp_handler->is_phone_verified($phone)) {
            wc_add_notice(
                __('Please verify your phone number with OTP before checkout.', HKDEV_TEXT_DOMAIN),
                'error'
            );
        }
    }

    // WooCommerce: Send Order Confirmation SMS
    public function send_order_confirmation_sms($order_id) {
        if (!hkdev_option_is_enabled('hkdev_enable_order_confirmation_sms', 'yes')) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $phone = $order->get_billing_phone();
        if (empty($phone)) {
            return;
        }

        $template = get_option('sib_order_template', 'Thank you for your order! Order ID: {ORDER_ID}');
        $message = str_replace(
            array('{ORDER_ID}', '{CUSTOMER_NAME}', '{ORDER_TOTAL}'),
            array($order_id, $order->get_billing_first_name(), $order->get_total()),
            $template
        );

        $this->sms_gateway->send_sms($phone, $message);
    }

    // WooCommerce: Send Status Update SMS
    public function send_status_update_sms($order_id, $old_status, $new_status, $order) {
        if (!hkdev_option_is_enabled('hkdev_enable_status_sms', 'yes')) {
            return;
        }

        $phone = $order->get_billing_phone();
        if (empty($phone)) {
            return;
        }

        $template = get_option('sib_status_template', 'Your order status has been updated to: {STATUS}');
        $message = str_replace('{STATUS}', ucwords(str_replace('-', ' ', $new_status)), $template);

        $this->sms_gateway->send_sms($phone, $message);
    }
}
