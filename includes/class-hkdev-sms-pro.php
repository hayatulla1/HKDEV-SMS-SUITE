<?php

if (!defined('ABSPATH')) {
    exit;
}

class HKDEV_SMS_Pro {

    private const MAX_CHECKOUT_POST_DATA_LENGTH = 20000;
    private const OTP_LENGTH_MIN = 4;
    private const OTP_LENGTH_MAX = 8;
    private const OTP_COOLDOWN_MIN_SECONDS = 10;

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
        add_action('wp_ajax_hkdev_search_products', array($this, 'ajax_search_products'));
        add_action('wp_ajax_hkdev_save_general_settings', array($this, 'ajax_save_general_settings'));
        add_action('wp_ajax_hkdev_save_api_settings', array($this, 'ajax_save_api_settings'));
        add_action('wp_ajax_hkdev_save_template_settings', array($this, 'ajax_save_template_settings'));
        add_action('wp_ajax_hkdev_save_blocker_settings', array($this, 'ajax_save_blocker_settings'));

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
        $general_group   = 'hkdev_settings_general_group';
        $api_group       = 'hkdev_settings_api_group';
        $template_group  = 'hkdev_settings_templates_group';
        $blocker_group   = 'hkdev_settings_blocker_group';

        register_setting($api_group, 'sib_gateway_url', array('sanitize_callback' => 'esc_url_raw'));
        register_setting($api_group, 'sib_api_token', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting($api_group, 'sib_sender_id', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting($api_group, 'sib_http_method', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting($api_group, 'sib_param_token', array('sanitize_callback' => 'sanitize_key'));
        register_setting($api_group, 'sib_param_sender', array('sanitize_callback' => 'sanitize_key'));
        register_setting($api_group, 'sib_param_number', array('sanitize_callback' => 'sanitize_key'));
        register_setting($api_group, 'sib_param_msg', array('sanitize_callback' => 'sanitize_key'));
        register_setting($api_group, 'hkdev_balance_api_url', array('sanitize_callback' => 'esc_url_raw'));
        register_setting($api_group, 'hkdev_balance_response_key', array('sanitize_callback' => 'sanitize_text_field'));

        register_setting($general_group, 'hkdev_enable_gateway', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting($general_group, 'hkdev_enable_otp', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting($general_group, 'hkdev_enable_order_confirmation_sms', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting($general_group, 'hkdev_enable_status_sms', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting($general_group, 'hkdev_enable_logs', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting($general_group, 'hkdev_enable_order_blocker', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting($general_group, 'hkdev_otp_length', array('sanitize_callback' => 'absint', 'default' => 6));
        register_setting($general_group, 'hkdev_otp_expiry_minutes', array('sanitize_callback' => 'absint', 'default' => 10));
        register_setting($general_group, 'hkdev_otp_cooldown_seconds', array('sanitize_callback' => 'absint', 'default' => 60));

        register_setting($template_group, 'sib_target_products', array('sanitize_callback' => 'sanitize_text_field'));
        register_setting($template_group, 'sib_otp_template', array('sanitize_callback' => 'wp_kses_post'));
        register_setting($template_group, 'sib_order_template', array('sanitize_callback' => 'wp_kses_post'));
        register_setting($template_group, 'sib_status_template', array('sanitize_callback' => 'wp_kses_post'));

        register_setting($blocker_group, 'usp_wcodb_block_duration_days', array('sanitize_callback' => 'absint', 'default' => 0));
        register_setting($blocker_group, 'usp_wcodb_block_duration_hours', array('sanitize_callback' => 'absint', 'default' => 0));
        register_setting($blocker_group, 'usp_wcodb_block_duration_minutes', array('sanitize_callback' => 'absint', 'default' => 60));
        register_setting($blocker_group, 'usp_wcodb_combined_block_enabled', array('sanitize_callback' => 'sanitize_text_field'));
    }

    public function enqueue_admin_assets($hook) {
        if ($hook !== 'toplevel_page_hkdev-sms-suite') {
            return;
        }

        wp_enqueue_style('hkdev-admin-css', HKDEV_PLUGIN_URL . 'assets/css/admin.css', array(), HKDEV_PLUGIN_VERSION);
        wp_enqueue_script('hkdev-admin-js', HKDEV_PLUGIN_URL . 'assets/js/admin.js', array('jquery'), HKDEV_PLUGIN_VERSION, true);

        wp_localize_script('hkdev-admin-js', 'hkdevAjax', array(
            'ajaxUrl'     => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce('hkdev_admin_nonce'),
            'otpNonce'    => wp_create_nonce('hkdev_otp_nonce'),
            'searchNonce' => wp_create_nonce('wc_product_search'),
            'otpLength'   => get_option('hkdev_otp_length', 6),
            'otpCooldown' => get_option('hkdev_otp_cooldown_seconds', 60),
        ));
    }

    public function enqueue_frontend_assets() {
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }
        if (!$this->is_otp_needed()) {
            return;
        }

        wp_enqueue_style('hkdev-frontend-css', HKDEV_PLUGIN_URL . 'assets/css/frontend.css', array(), HKDEV_PLUGIN_VERSION);
        wp_enqueue_script('hkdev-frontend-js', HKDEV_PLUGIN_URL . 'assets/js/frontend.js', array('jquery', 'wp-element'), HKDEV_PLUGIN_VERSION, true);

        wp_localize_script('hkdev-frontend-js', 'hkdevFrontendAjax', array(
            'ajaxUrl'       => admin_url('admin-ajax.php'),
            'nonce'         => wp_create_nonce('hkdev_otp_nonce'),
            'otpLength'     => get_option('hkdev_otp_length', 6),
            'cooldown'      => get_option('hkdev_otp_cooldown_seconds', 60),
            'verifyingText' => __('Verifying...', HKDEV_TEXT_DOMAIN),
            'verifiedText'  => __('Verified!', HKDEV_TEXT_DOMAIN),
            'modalTitle'        => __('Phone Verification Required', HKDEV_TEXT_DOMAIN),
            'modalDescription'  => __('Verify your phone to complete your order', HKDEV_TEXT_DOMAIN),
            'verifyButtonText'  => __('Verify & Continue Order', HKDEV_TEXT_DOMAIN),
            'resendPrefix'      => __('Resend code in', HKDEV_TEXT_DOMAIN),
            'resendButtonText'  => __('Resend OTP', HKDEV_TEXT_DOMAIN),
            'closeLabel'        => __('Close', HKDEV_TEXT_DOMAIN),
        ));
    }

    public function render_admin_dashboard() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', HKDEV_TEXT_DOMAIN));
        }

        $logs          = $this->sms_gateway->get_logs();
        $balance_cache = get_option('hkdev_balance_cache', array('amount' => 'N/A', 'checked_at' => ''));

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

        $target_setting = get_option('sib_target_products', '');
        $target_raw = is_array($target_setting) ? $target_setting : explode(',', (string) $target_setting);
        $targets = array_unique(array_filter(
            array_map('absint', (array) $target_raw),
            static function ($id) { return $id > 0; }
        ));

        if (empty($targets)) {
            return !WC()->cart->is_empty();
        }

        foreach (WC()->cart->get_cart() as $item) {
            $product_id   = isset($item['product_id'])   ? absint($item['product_id'])   : 0;
            $variation_id = isset($item['variation_id']) ? absint($item['variation_id']) : 0;
            if (
                ($product_id > 0   && in_array($product_id, $targets, true)) ||
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

        $otp_result = $this->otp_handler->generate_otp($phone);
        if (!$otp_result['success']) {
            wp_send_json_error($otp_result['message']);
        }

        $template = get_option('sib_otp_template', 'Your OTP is: {OTP}');
        $message  = str_replace('{OTP}', $otp_result['otp'], $template);

        $sms_result = $this->sms_gateway->send_sms($phone, $message);
        if (!$sms_result['success']) {
            wp_send_json_error($sms_result['message']);
        }

        wp_send_json_success(array(
            'message' => __('OTP sent successfully', HKDEV_TEXT_DOMAIN),
            'expiry'  => $otp_result['expiry_minutes'],
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
        $otp   = isset($_POST['otp'])   ? sanitize_text_field($_POST['otp'])   : '';

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

        $phone   = isset($_POST['phone'])   ? sanitize_text_field($_POST['phone'])     : '';
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

    // AJAX: Clear SMS Logs
    public function ajax_clear_logs() {
        check_ajax_referer('hkdev_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', HKDEV_TEXT_DOMAIN));
        }

        $this->sms_gateway->clear_logs();
        wp_send_json_success(__('Logs cleared successfully', HKDEV_TEXT_DOMAIN));
    }

    // AJAX: Search Products (for Free Delivery settings)
    public function ajax_search_products() {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!$nonce && isset($_POST['security'])) {
            $nonce = sanitize_text_field(wp_unslash($_POST['security']));
        }
        if (
            !$nonce ||
            (!wp_verify_nonce($nonce, 'hkdev_admin_nonce') && !wp_verify_nonce($nonce, 'wc_product_search'))
        ) {
            wp_send_json_error(__('Unauthorized', HKDEV_TEXT_DOMAIN));
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', HKDEV_TEXT_DOMAIN));
        }

        $term = isset($_POST['term']) ? sanitize_text_field($_POST['term']) : '';
        if (strlen($term) < 2) {
            wp_send_json_success(array());
        }

        $ids = array();
        $term_is_numeric = is_numeric($term);
        if ($term_is_numeric) {
            $term_id = absint($term);
            if ($term_id && 'publish' === get_post_status($term_id) && 'product' === get_post_type($term_id)) {
                $ids[] = $term_id;
            }
        }

        if (!$term_is_numeric || empty($ids)) {
            $query = new WP_Query(array(
                'post_type'      => 'product',
                'post_status'    => 'publish',
                's'              => $term,
                'posts_per_page' => 20,
                'fields'         => 'ids',
            ));
            if (!empty($query->posts)) {
                $ids = array_merge($ids, $query->posts);
            }
        }

        if (count($ids) < 20) {
            $remaining = max(1, 20 - count($ids));
            $sku_query = new WP_Query(array(
                'post_type'      => 'product',
                'post_status'    => 'publish',
                'posts_per_page' => $remaining,
                'fields'         => 'ids',
                'meta_query'     => array(
                    array(
                        'key'     => '_sku',
                        'value'   => $term,
                        'compare' => 'LIKE',
                    ),
                ),
            ));
            if (!empty($sku_query->posts)) {
                $ids = array_merge($ids, $sku_query->posts);
            }
        }

        $ids = array_values(array_unique(array_filter($ids)));

        $results = array();
        foreach (array_slice($ids, 0, 20) as $id) {
            $product = wc_get_product($id);
            if ($product) {
                $results[] = array('id' => $id, 'name' => $product->get_name());
            }
        }

        wp_send_json_success($results);
    }

    public function ajax_save_general_settings() {
        check_ajax_referer('hkdev_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', HKDEV_TEXT_DOMAIN));
        }

        $length = absint($_POST['hkdev_otp_length'] ?? 6);
        $length = max(self::OTP_LENGTH_MIN, min(self::OTP_LENGTH_MAX, $length));

        $settings = array(
            'hkdev_enable_gateway'               => !empty($_POST['hkdev_enable_gateway']) ? 'yes' : 'no',
            'hkdev_enable_otp'                   => !empty($_POST['hkdev_enable_otp']) ? 'yes' : 'no',
            'hkdev_enable_order_confirmation_sms'=> !empty($_POST['hkdev_enable_order_confirmation_sms']) ? 'yes' : 'no',
            'hkdev_enable_status_sms'            => !empty($_POST['hkdev_enable_status_sms']) ? 'yes' : 'no',
            'hkdev_enable_logs'                  => !empty($_POST['hkdev_enable_logs']) ? 'yes' : 'no',
            'hkdev_enable_order_blocker'         => !empty($_POST['hkdev_enable_order_blocker']) ? 'yes' : 'no',
            'hkdev_otp_length'                   => $length,
            'hkdev_otp_expiry_minutes'           => max(1, absint($_POST['hkdev_otp_expiry_minutes'] ?? 10)),
            'hkdev_otp_cooldown_seconds'         => max(self::OTP_COOLDOWN_MIN_SECONDS, absint($_POST['hkdev_otp_cooldown_seconds'] ?? 60)),
        );

        foreach ($settings as $key => $value) {
            update_option($key, $value);
        }

        wp_send_json_success(__('Settings saved successfully', HKDEV_TEXT_DOMAIN));
    }

    public function ajax_save_api_settings() {
        check_ajax_referer('hkdev_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', HKDEV_TEXT_DOMAIN));
        }

        $http_method = sanitize_text_field($_POST['sib_http_method'] ?? 'GET');
        if (!in_array($http_method, array('GET', 'POST'), true)) {
            $http_method = 'GET';
        }

        $settings = array(
            'sib_gateway_url'          => esc_url_raw($_POST['sib_gateway_url'] ?? ''),
            'sib_api_token'            => sanitize_text_field($_POST['sib_api_token'] ?? ''),
            'sib_sender_id'            => sanitize_text_field($_POST['sib_sender_id'] ?? ''),
            'sib_http_method'          => $http_method,
            'sib_param_token'          => sanitize_key($_POST['sib_param_token'] ?? 'token'),
            'sib_param_sender'         => sanitize_key($_POST['sib_param_sender'] ?? 'sender'),
            'sib_param_number'         => sanitize_key($_POST['sib_param_number'] ?? 'number'),
            'sib_param_msg'            => sanitize_key($_POST['sib_param_msg'] ?? 'message'),
            'hkdev_balance_api_url'    => esc_url_raw($_POST['hkdev_balance_api_url'] ?? ''),
            'hkdev_balance_response_key' => sanitize_text_field($_POST['hkdev_balance_response_key'] ?? 'balance'),
        );

        foreach ($settings as $key => $value) {
            update_option($key, $value);
        }

        wp_send_json_success(__('API settings saved successfully', HKDEV_TEXT_DOMAIN));
    }

    public function ajax_save_template_settings() {
        check_ajax_referer('hkdev_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', HKDEV_TEXT_DOMAIN));
        }

        $products = array();
        if (isset($_POST['products'])) {
            $products = array_filter(array_map('absint', (array) $_POST['products']));
        } elseif (isset($_POST['sib_target_products'])) {
            $products = array_filter(array_map('absint', (array) $_POST['sib_target_products']));
        }
        $product_string = implode(',', $products);

        $settings = array(
            'sib_target_products' => $product_string,
            'sib_otp_template'    => sanitize_textarea_field(wp_unslash($_POST['sib_otp_template'] ?? '')),
            'sib_order_template'  => sanitize_textarea_field(wp_unslash($_POST['sib_order_template'] ?? '')),
            'sib_status_template' => sanitize_textarea_field(wp_unslash($_POST['sib_status_template'] ?? '')),
        );

        foreach ($settings as $key => $value) {
            update_option($key, $value);
        }

        wp_send_json_success(__('Templates saved successfully', HKDEV_TEXT_DOMAIN));
    }

    public function ajax_save_blocker_settings() {
        check_ajax_referer('hkdev_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized', HKDEV_TEXT_DOMAIN));
        }

        $settings = array(
            'usp_wcodb_block_duration_days'    => max(0, absint($_POST['usp_wcodb_block_duration_days'] ?? 0)),
            'usp_wcodb_block_duration_hours'   => max(0, absint($_POST['usp_wcodb_block_duration_hours'] ?? 0)),
            'usp_wcodb_block_duration_minutes' => max(0, absint($_POST['usp_wcodb_block_duration_minutes'] ?? 60)),
            'usp_wcodb_combined_block_enabled' => !empty($_POST['usp_wcodb_combined_block_enabled']) ? 'yes' : 'no',
        );

        foreach ($settings as $key => $value) {
            update_option($key, $value);
        }

        wp_send_json_success(__('Blocker settings saved successfully', HKDEV_TEXT_DOMAIN));
    }

    // WooCommerce: Validate OTP at checkout
    public function validate_checkout_otp() {
        if (!$this->is_otp_needed()) {
            return;
        }

        $phone = isset($_POST['billing_phone']) ? sanitize_text_field(wp_unslash($_POST['billing_phone'])) : '';

        if (empty($phone) && isset($_POST['post_data'])) {
            $post_data     = array();
            $post_data_raw = wp_unslash($_POST['post_data']);
            if (is_string($post_data_raw) && strlen($post_data_raw) <= self::MAX_CHECKOUT_POST_DATA_LENGTH) {
                parse_str($post_data_raw, $post_data);
                if (!empty($post_data['billing_phone'])) {
                    $phone = sanitize_text_field($post_data['billing_phone']);
                }
            }
        }

        if (empty($phone)) {
            wc_add_notice(
                __('A valid phone number is required for OTP verification before checkout.', HKDEV_TEXT_DOMAIN),
                'error'
            );
            return;
        }

        if (!$this->otp_handler->is_phone_verified($phone)) {
            wc_add_notice(
                __('Please verify your phone number with OTP before checkout.', HKDEV_TEXT_DOMAIN),
                'error'
            );
        }
    }

    // WooCommerce: Order Confirmation SMS
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
        $message  = str_replace(
            array('{ORDER_ID}', '{CUSTOMER_NAME}', '{ORDER_TOTAL}'),
            array($order_id, $order->get_billing_first_name(), $order->get_total()),
            $template
        );

        $this->sms_gateway->send_sms($phone, $message);
    }

    // WooCommerce: Status Update SMS
    public function send_status_update_sms($order_id, $old_status, $new_status, $order) {
        if (!hkdev_option_is_enabled('hkdev_enable_status_sms', 'yes')) {
            return;
        }

        $phone = $order->get_billing_phone();
        if (empty($phone)) {
            return;
        }

        $template = get_option('sib_status_template', 'Your order status has been updated to: {STATUS}');
        $message  = str_replace('{STATUS}', ucwords(str_replace('-', ' ', $new_status)), $template);

        $this->sms_gateway->send_sms($phone, $message);
    }
}
