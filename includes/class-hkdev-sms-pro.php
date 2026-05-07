<?php

if (!defined('ABSPATH')) {
    exit;
}

class HKDEV_SMS_Pro
{
    private $log_option = 'sib_sms_logs';
    private $balance_option = 'hkdev_balance_cache';
    private $notice_param = 'hkdev_notice';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'create_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        add_action('woocommerce_after_checkout_form', [$this, 'inject_otp_ui']);

        add_action('wp_ajax_sib_send_otp', [$this, 'ajax_send_otp']);
        add_action('wp_ajax_nopriv_sib_send_otp', [$this, 'ajax_send_otp']);
        add_action('wp_ajax_sib_verify_otp', [$this, 'ajax_verify_otp']);
        add_action('wp_ajax_nopriv_sib_verify_otp', [$this, 'ajax_verify_otp']);
        add_action('wp_ajax_sib_test_sms', [$this, 'ajax_test_sms']);
        add_action('wp_ajax_hkdev_test_sms', [$this, 'ajax_test_sms']);
        add_action('wp_ajax_hkdev_check_balance', [$this, 'ajax_check_balance']);

        add_action('woocommerce_checkout_process', [$this, 'validate_checkout_otp']);
        add_action('woocommerce_thankyou', [$this, 'send_order_confirmation_sms'], 10, 1);
        add_action('woocommerce_order_status_changed', [$this, 'send_status_update_sms'], 10, 4);
    }

    public function enqueue_admin_assets($hook)
    {
        $allowed_hooks = ['toplevel_page_sib-pro', 'sib-pro_page_usp-order-delay-blocker'];

        if (!in_array($hook, $allowed_hooks, true)) {
            return;
        }

        wp_enqueue_style(
            'hkdev-admin-style',
            USP_SMS_PLUGIN_URL . 'assets/css/admin.css',
            [],
            USP_SMS_PLUGIN_VERSION
        );

        wp_enqueue_script(
            'hkdev-admin-script',
            USP_SMS_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            USP_SMS_PLUGIN_VERSION,
            true
        );

        wp_localize_script('hkdev-admin-script', 'hkdevAdminData', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hkdev_admin_nonce'),
            'messages' => [
                'balanceError' => __('Unable to fetch balance.', 'universal-sms-pro-gateway'),
                'balanceLoading' => __('Checking balance...', 'universal-sms-pro-gateway'),
                'balanceSuccess' => __('Balance updated.', 'universal-sms-pro-gateway'),
                'testSending' => __('Sending test SMS...', 'universal-sms-pro-gateway'),
                'testSent' => __('Test SMS sent.', 'universal-sms-pro-gateway'),
                'testError' => __('Failed to send test SMS.', 'universal-sms-pro-gateway'),
            ],
        ]);
    }

    public function enqueue_frontend_assets()
    {
        if (
            !$this->is_feature_enabled('hkdev_enable_otp') ||
            !$this->is_feature_enabled('hkdev_enable_gateway') ||
            !function_exists('is_checkout') ||
            !is_checkout() ||
            !$this->is_otp_needed()
        ) {
            return;
        }

        wp_enqueue_style(
            'usp-frontend-style',
            USP_SMS_PLUGIN_URL . 'assets/css/frontend.css',
            [],
            USP_SMS_PLUGIN_VERSION
        );

        wp_enqueue_script(
            'usp-frontend-script',
            USP_SMS_PLUGIN_URL . 'assets/js/frontend.js',
            ['jquery'],
            USP_SMS_PLUGIN_VERSION,
            true
        );

        wp_localize_script('usp-frontend-script', 'uspSmsData', [
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('sib_otp_nonce'),
            'isVerified' => (bool) WC()->session->get('sib_otp_verified'),
            'expirySeconds' => max(1, absint(get_option('hkdev_otp_expiry_minutes', 10))) * MINUTE_IN_SECONDS,
            'messages'   => [
                'invalidOtp' => __('Invalid OTP', 'universal-sms-pro-gateway'),
                'sendFailed' => __('Failed to send OTP.', 'universal-sms-pro-gateway'),
                'phoneRequired' => __('Please enter a valid phone number.', 'universal-sms-pro-gateway'),
                'otpSent' => __('OTP sent. Please check your phone.', 'universal-sms-pro-gateway'),
                'otpExpired' => __('OTP expired. Please request a new one.', 'universal-sms-pro-gateway'),
                'otpExpiresIn' => __('Code expires in %s', 'universal-sms-pro-gateway'),
                'sendingOtp' => __('Sending OTP...', 'universal-sms-pro-gateway'),
                'verifyingOtp' => __('Verifying...', 'universal-sms-pro-gateway'),
            ],
        ]);
    }

    public function create_menu()
    {
        add_menu_page(
            __('HKDEV', 'universal-sms-pro-gateway'),
            __('HKDEV', 'universal-sms-pro-gateway'),
            'manage_options',
            'sib-pro',
            [$this, 'main_page'],
            'dashicons-email-alt'
        );
    }

    public function register_settings()
    {
        register_setting('sib-pro-settings', 'sib_gateway_url', ['sanitize_callback' => 'esc_url_raw']);
        register_setting('sib-pro-settings', 'sib_api_token', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('sib-pro-settings', 'sib_sender_id', ['sanitize_callback' => 'sanitize_text_field']);
        register_setting('sib-pro-settings', 'sib_http_method', ['sanitize_callback' => [$this, 'sanitize_http_method']]);

        register_setting('sib-pro-settings', 'sib_param_token', ['sanitize_callback' => 'sanitize_key']);
        register_setting('sib-pro-settings', 'sib_param_sender', ['sanitize_callback' => 'sanitize_key']);
        register_setting('sib-pro-settings', 'sib_param_number', ['sanitize_callback' => 'sanitize_key']);
        register_setting('sib-pro-settings', 'sib_param_msg', ['sanitize_callback' => 'sanitize_key']);

        register_setting('sib-pro-settings', 'sib_target_products', ['sanitize_callback' => [$this, 'sanitize_target_products']]);
        register_setting('sib-pro-settings', 'sib_otp_template', ['sanitize_callback' => 'sanitize_textarea_field']);
        register_setting('sib-pro-settings', 'sib_order_template', ['sanitize_callback' => 'sanitize_textarea_field']);

        register_setting('sib-pro-settings', 'hkdev_enable_gateway', ['sanitize_callback' => [$this, 'sanitize_checkbox'], 'default' => 'yes']);
        register_setting('sib-pro-settings', 'hkdev_enable_otp', ['sanitize_callback' => [$this, 'sanitize_checkbox'], 'default' => 'yes']);
        register_setting('sib-pro-settings', 'hkdev_enable_order_confirmation_sms', ['sanitize_callback' => [$this, 'sanitize_checkbox'], 'default' => 'yes']);
        register_setting('sib-pro-settings', 'hkdev_enable_status_sms', ['sanitize_callback' => [$this, 'sanitize_checkbox'], 'default' => 'yes']);
        register_setting('sib-pro-settings', 'hkdev_enable_logs', ['sanitize_callback' => [$this, 'sanitize_checkbox'], 'default' => 'yes']);
        register_setting('sib-pro-settings', 'hkdev_enable_order_blocker', ['sanitize_callback' => [$this, 'sanitize_checkbox'], 'default' => 'yes']);
        register_setting('sib-pro-settings', 'hkdev_enable_failover', ['sanitize_callback' => [$this, 'sanitize_checkbox'], 'default' => 'yes']);

        register_setting('sib-pro-settings', 'hkdev_otp_expiry_minutes', ['sanitize_callback' => 'absint', 'default' => 10]);
        register_setting('sib-pro-settings', 'hkdev_otp_cooldown_seconds', ['sanitize_callback' => 'absint', 'default' => 60]);
        register_setting('sib-pro-settings', 'hkdev_otp_length', ['sanitize_callback' => [$this, 'sanitize_otp_length'], 'default' => 6]);

        register_setting('sib-pro-settings', 'hkdev_balance_api_url', ['sanitize_callback' => 'esc_url_raw']);
        register_setting('sib-pro-settings', 'hkdev_balance_http_method', ['sanitize_callback' => [$this, 'sanitize_http_method']]);
        register_setting('sib-pro-settings', 'hkdev_balance_param_token', ['sanitize_callback' => 'sanitize_key']);
        register_setting('sib-pro-settings', 'hkdev_balance_response_key', ['sanitize_callback' => 'sanitize_text_field']);
    }

    public function sanitize_http_method($method)
    {
        return strtoupper($method) === 'GET' ? 'GET' : 'POST';
    }

    public function sanitize_checkbox($value)
    {
        return $value === 'yes' ? 'yes' : 'no';
    }

    public function sanitize_otp_length($value)
    {
        $length = absint($value);
        return in_array($length, [4, 5, 6, 8], true) ? $length : 6;
    }

    public function sanitize_target_products($value)
    {
        $ids = array_filter(array_map('trim', explode(',', sanitize_text_field($value))), static function ($id) {
            return ctype_digit((string) $id);
        });

        if (function_exists('wc_get_product')) {
            $ids = array_filter($ids, static function ($id) {
                return (bool) wc_get_product((int) $id);
            });
        }

        return implode(',', $ids);
    }

    private function is_feature_enabled($option, $default = 'yes')
    {
        return get_option($option, $default) === 'yes';
    }

    private function get_balance_cache()
    {
        $cache = get_option($this->balance_option, []);
        return is_array($cache) ? $cache : [];
    }

    private function verify_ajax_nonce()
    {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';

        if (!wp_verify_nonce($nonce, 'sib_otp_nonce')) {
            wp_send_json_error(__('Security check failed.', 'universal-sms-pro-gateway'));
        }
    }

    private function verify_admin_nonce()
    {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';

        if (!wp_verify_nonce($nonce, 'hkdev_admin_nonce')) {
            wp_send_json_error(__('Security check failed.', 'universal-sms-pro-gateway'));
        }
    }

    private function get_phone_from_request()
    {
        $phone = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
        return $this->normalize_phone($phone);
    }

    private function normalize_phone($phone)
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if (strpos($digits, '88') !== 0) {
            $digits = '88' . ltrim($digits, '0');
        }

        return $digits;
    }

    private function is_gateway_response_successful($body, $body_raw)
    {
        $normalized_raw      = strtolower(trim((string) $body_raw));
        $status_success      = isset($body['status']) && strtolower((string) $body['status']) === 'success';
        $raw_text_success    = in_array($normalized_raw, ['success', 'ok', 'sent'], true);
        $raw_code_success    = (bool) preg_match('/^1000$/', $normalized_raw);
        $raw_contains_errors = strpos($normalized_raw, 'error') !== false || strpos($normalized_raw, 'failed') !== false;

        return $status_success || (($raw_text_success || $raw_code_success) && !$raw_contains_errors);
    }

    private function log_sms($number, $message, $status, $response)
    {
        if (!$this->is_feature_enabled('hkdev_enable_logs')) {
            return;
        }
        $logs = get_option($this->log_option, []);

        array_unshift($logs, [
            'time'     => current_time('mysql'),
            'number'   => sanitize_text_field($number),
            'message'  => sanitize_text_field($message),
            'status'   => sanitize_text_field($status),
            'response' => sanitize_text_field((string) $response),
        ]);

        update_option($this->log_option, array_slice($logs, 0, 50));
    }

    public function send_sms($number, $message)
    {
        if (!$this->is_feature_enabled('hkdev_enable_gateway')) {
            return ['status' => 'error', 'message' => __('SMS sending is disabled in settings.', 'universal-sms-pro-gateway')];
        }

        $api_url     = get_option('sib_gateway_url', 'https://api.smsinbd.com/sms-api/sendsms');
        $api_token   = get_option('sib_api_token', '');
        $raw_senders = get_option('sib_sender_id', '');
        $method      = get_option('sib_http_method', 'POST');

        $p_token  = get_option('sib_param_token', 'api_token');
        $p_sender = get_option('sib_param_sender', 'senderid');
        $p_number = get_option('sib_param_number', 'contact_number');
        $p_msg    = get_option('sib_param_msg', 'message');

        $sender_ids = array_filter(array_map('trim', explode(',', $raw_senders)));
        $is_failover_enabled = $this->is_feature_enabled('hkdev_enable_failover');

        if (empty($sender_ids)) {
            $this->log_sms($number, $message, 'Failed', 'No Sender ID configured');
            return ['status' => 'error', 'message' => 'No Sender ID configured'];
        }

        if (!$is_failover_enabled) {
            $sender_ids = [reset($sender_ids)];
        }

        $last_error = 'Unknown gateway error';

        foreach ($sender_ids as $sid) {
            $data = [
                $p_token  => $api_token,
                $p_sender => $sid,
                $p_number => $number,
                $p_msg    => $message,
            ];

            if ($method === 'GET') {
                $request_url = add_query_arg($data, $api_url);
                $response    = wp_remote_get($request_url, ['timeout' => 20]);
            } else {
                $response = wp_remote_post($api_url, [
                    'body'    => $data,
                    'timeout' => 20,
                ]);
            }

            if (is_wp_error($response)) {
                $last_error = $response->get_error_message();
                continue;
            }

            $body_raw = wp_remote_retrieve_body($response);
            $body     = json_decode($body_raw, true);

            if ($this->is_gateway_response_successful($body, $body_raw)) {
                $this->log_sms($number, $message, 'Success', "Gateway: {$api_url} | Sender: {$sid}");
                return ['status' => 'success', 'message' => 'SMS Sent Successfully'];
            }

            $last_error = is_array($body) && isset($body['message']) ? $body['message'] : $body_raw;
        }

        $this->log_sms($number, $message, 'Failed', $last_error);

        return ['status' => 'error', 'message' => $last_error];
    }

    public function main_page()
    {
        $this->handle_admin_actions();

        $tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'overview';
        if (!in_array($tab, ['overview', 'settings', 'features', 'templates', 'logs'], true)) {
            $tab = 'overview';
        }

        $logs = get_option($this->log_option, []);
        $log_count = is_array($logs) ? count($logs) : 0;
        $latest_log = $log_count ? $logs[0] : null;

        $balance_cache = $this->get_balance_cache();
        $balance_value = $balance_cache['amount'] ?? __('Not checked', 'universal-sms-pro-gateway');
        $balance_time_raw = $balance_cache['checked_at'] ?? '';
        $balance_time = $balance_time_raw ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($balance_time_raw)) : '';

        $notice = isset($_GET[$this->notice_param]) ? sanitize_text_field(wp_unslash($_GET[$this->notice_param])) : '';
        $checkout_preview_url = function_exists('wc_get_checkout_url') ? wc_get_checkout_url() : home_url('/');
        ?>
        <div class="wrap hkdev-wrap">
            <div class="hkdev-header">
                <div>
                    <h1><?php esc_html_e('HKDEV SMS Suite', 'universal-sms-pro-gateway'); ?></h1>
                    <p><?php esc_html_e('Professional SMS, OTP, and order protection toolkit for WooCommerce.', 'universal-sms-pro-gateway'); ?></p>
                </div>
                <div class="hkdev-header-actions">
                    <a class="button button-secondary" href="<?php echo esc_url(admin_url('admin.php?page=usp-order-delay-blocker')); ?>">
                        <?php esc_html_e('Order Delay Blocker', 'universal-sms-pro-gateway'); ?>
                    </a>
                </div>
            </div>

            <?php $this->render_admin_notice($notice); ?>

            <div class="hkdev-view-switcher">
                <a href="<?php echo esc_url(admin_url('admin.php?page=sib-pro&tab=overview')); ?>" class="button button-primary">
                    <?php esc_html_e('1. View Plugin Overview', 'universal-sms-pro-gateway'); ?>
                </a>
                <a href="<?php echo esc_url($checkout_preview_url); ?>" class="button button-secondary" target="_blank" rel="noopener noreferrer">
                    <?php esc_html_e('2. View Checkout Modal', 'universal-sms-pro-gateway'); ?>
                </a>
            </div>

            <h2 class="nav-tab-wrapper hkdev-nav-tab-wrapper">
                <a href="?page=sib-pro&tab=features" class="nav-tab <?php echo $tab === 'features' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('General Settings', 'universal-sms-pro-gateway'); ?></a>
                <a href="?page=sib-pro&tab=settings" class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('API Credentials', 'universal-sms-pro-gateway'); ?></a>
                <a href="?page=sib-pro&tab=templates" class="nav-tab <?php echo $tab === 'templates' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('SMS Templates', 'universal-sms-pro-gateway'); ?></a>
                <a href="?page=sib-pro&tab=logs" class="nav-tab <?php echo $tab === 'logs' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('SMS Logs', 'universal-sms-pro-gateway'); ?></a>
            </h2>

            <?php if ($tab === 'overview') : ?>
                <div class="hkdev-grid hkdev-grid--stats">
                    <div class="hkdev-card hkdev-stat-card">
                        <span class="hkdev-stat-label"><?php esc_html_e('Gateway Balance', 'universal-sms-pro-gateway'); ?></span>
                        <div class="hkdev-stat-value" id="hkdev-balance-value"><?php echo esc_html($balance_value); ?></div>
                        <div class="hkdev-stat-meta" id="hkdev-balance-time">
                            <?php echo $balance_time ? esc_html(sprintf(__('Last checked: %s', 'universal-sms-pro-gateway'), $balance_time)) : esc_html__('Not checked yet.', 'universal-sms-pro-gateway'); ?>
                        </div>
                        <div class="hkdev-card-actions">
                            <button class="button" type="button" id="hkdev-refresh-balance"><?php esc_html_e('Refresh Balance', 'universal-sms-pro-gateway'); ?></button>
                            <span class="hkdev-inline-status" id="hkdev-balance-status"></span>
                        </div>
                    </div>

                    <div class="hkdev-card hkdev-stat-card">
                        <span class="hkdev-stat-label"><?php esc_html_e('SMS Gateway', 'universal-sms-pro-gateway'); ?></span>
                        <div class="hkdev-stat-value">
                            <?php echo $this->is_feature_enabled('hkdev_enable_gateway') ? esc_html__('Enabled', 'universal-sms-pro-gateway') : esc_html__('Disabled', 'universal-sms-pro-gateway'); ?>
                        </div>
                        <div class="hkdev-stat-meta">
                            <?php
                            $sender_ids = array_filter(array_map('trim', explode(',', (string) get_option('sib_sender_id', ''))));
                            echo esc_html(sprintf(__('Sender IDs: %d', 'universal-sms-pro-gateway'), count($sender_ids)));
                            ?>
                        </div>
                    </div>

                    <div class="hkdev-card hkdev-stat-card">
                        <span class="hkdev-stat-label"><?php esc_html_e('OTP Verification', 'universal-sms-pro-gateway'); ?></span>
                        <div class="hkdev-stat-value">
                            <?php echo $this->is_feature_enabled('hkdev_enable_otp') ? esc_html__('Active', 'universal-sms-pro-gateway') : esc_html__('Paused', 'universal-sms-pro-gateway'); ?>
                        </div>
                        <div class="hkdev-stat-meta">
                            <?php
                            $expiry = absint(get_option('hkdev_otp_expiry_minutes', 10));
                            $cooldown = absint(get_option('hkdev_otp_cooldown_seconds', 60));
                            echo esc_html(sprintf(__('Expiry: %d min | Cooldown: %d sec', 'universal-sms-pro-gateway'), $expiry, $cooldown));
                            ?>
                        </div>
                    </div>

                    <div class="hkdev-card hkdev-stat-card">
                        <span class="hkdev-stat-label"><?php esc_html_e('SMS Activity', 'universal-sms-pro-gateway'); ?></span>
                        <div class="hkdev-stat-value"><?php echo esc_html((string) $log_count); ?></div>
                        <div class="hkdev-stat-meta">
                            <?php
                            if (!empty($latest_log['time'])) {
                                echo esc_html(sprintf(__('Last sent: %s', 'universal-sms-pro-gateway'), $latest_log['time']));
                            } else {
                                esc_html_e('No activity yet.', 'universal-sms-pro-gateway');
                            }
                            ?>
                        </div>
                    </div>
                </div>

                <div class="hkdev-grid hkdev-grid--two">
                    <div class="hkdev-card">
                        <h3><?php esc_html_e('Quick Test SMS', 'universal-sms-pro-gateway'); ?></h3>
                        <p><?php esc_html_e('Send a test message to confirm your gateway configuration.', 'universal-sms-pro-gateway'); ?></p>
                        <div class="hkdev-inline-form">
                            <input type="text" id="hkdev-test-phone" class="regular-text" placeholder="<?php esc_attr_e('01XXXXXXXXX', 'universal-sms-pro-gateway'); ?>">
                            <button class="button button-primary" type="button" id="hkdev-test-sms"><?php esc_html_e('Send Test', 'universal-sms-pro-gateway'); ?></button>
                        </div>
                        <p class="hkdev-inline-status" id="hkdev-test-status"></p>
                    </div>

                    <div class="hkdev-card">
                        <h3><?php esc_html_e('Feature Snapshot', 'universal-sms-pro-gateway'); ?></h3>
                        <ul class="hkdev-feature-list">
                            <li>
                                <span><?php esc_html_e('Order Confirmation SMS', 'universal-sms-pro-gateway'); ?></span>
                                <span class="hkdev-pill <?php echo $this->is_feature_enabled('hkdev_enable_order_confirmation_sms') ? 'is-on' : 'is-off'; ?>">
                                    <?php echo $this->is_feature_enabled('hkdev_enable_order_confirmation_sms') ? esc_html__('On', 'universal-sms-pro-gateway') : esc_html__('Off', 'universal-sms-pro-gateway'); ?>
                                </span>
                            </li>
                            <li>
                                <span><?php esc_html_e('Status Update SMS', 'universal-sms-pro-gateway'); ?></span>
                                <span class="hkdev-pill <?php echo $this->is_feature_enabled('hkdev_enable_status_sms') ? 'is-on' : 'is-off'; ?>">
                                    <?php echo $this->is_feature_enabled('hkdev_enable_status_sms') ? esc_html__('On', 'universal-sms-pro-gateway') : esc_html__('Off', 'universal-sms-pro-gateway'); ?>
                                </span>
                            </li>
                            <li>
                                <span><?php esc_html_e('Order Delay Blocker', 'universal-sms-pro-gateway'); ?></span>
                                <span class="hkdev-pill <?php echo $this->is_feature_enabled('hkdev_enable_order_blocker') ? 'is-on' : 'is-off'; ?>">
                                    <?php echo $this->is_feature_enabled('hkdev_enable_order_blocker') ? esc_html__('On', 'universal-sms-pro-gateway') : esc_html__('Off', 'universal-sms-pro-gateway'); ?>
                                </span>
                            </li>
                            <li>
                                <span><?php esc_html_e('Logging', 'universal-sms-pro-gateway'); ?></span>
                                <span class="hkdev-pill <?php echo $this->is_feature_enabled('hkdev_enable_logs') ? 'is-on' : 'is-off'; ?>">
                                    <?php echo $this->is_feature_enabled('hkdev_enable_logs') ? esc_html__('On', 'universal-sms-pro-gateway') : esc_html__('Off', 'universal-sms-pro-gateway'); ?>
                                </span>
                            </li>
                        </ul>
                    </div>
                </div>
            <?php elseif ($tab === 'settings') : ?>
                <div class="hkdev-card">
                    <form method="post" action="options.php">
                        <?php settings_fields('sib-pro-settings'); ?>

                        <h3><?php esc_html_e('Provider Configuration', 'universal-sms-pro-gateway'); ?></h3>
                        <table class="form-table">
                            <tr>
                                <th><?php esc_html_e('API Endpoint URL', 'universal-sms-pro-gateway'); ?></th>
                                <td><input type="url" name="sib_gateway_url" value="<?php echo esc_attr(get_option('sib_gateway_url', 'https://api.smsinbd.com/sms-api/sendsms')); ?>" class="large-text"></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('HTTP Method', 'universal-sms-pro-gateway'); ?></th>
                                <td>
                                    <select name="sib_http_method">
                                        <option value="POST" <?php selected(get_option('sib_http_method', 'POST'), 'POST'); ?>>POST</option>
                                        <option value="GET" <?php selected(get_option('sib_http_method', 'POST'), 'GET'); ?>>GET</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('API Token / Key', 'universal-sms-pro-gateway'); ?></th>
                                <td><input type="text" name="sib_api_token" value="<?php echo esc_attr(get_option('sib_api_token')); ?>" class="regular-text"></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Sender IDs (Failover)', 'universal-sms-pro-gateway'); ?></th>
                                <td><input type="text" name="sib_sender_id" value="<?php echo esc_attr(get_option('sib_sender_id')); ?>" class="large-text" placeholder="FitForLife,880961..." /></td>
                            </tr>
                        </table>

                        <h3><?php esc_html_e('API Parameter Mapping', 'universal-sms-pro-gateway'); ?></h3>
                        <table class="form-table">
                            <tr>
                                <th><?php esc_html_e('Token Field Name', 'universal-sms-pro-gateway'); ?></th>
                                <td><input type="text" name="sib_param_token" value="<?php echo esc_attr(get_option('sib_param_token', 'api_token')); ?>"></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Sender ID Field Name', 'universal-sms-pro-gateway'); ?></th>
                                <td><input type="text" name="sib_param_sender" value="<?php echo esc_attr(get_option('sib_param_sender', 'senderid')); ?>"></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Number Field Name', 'universal-sms-pro-gateway'); ?></th>
                                <td><input type="text" name="sib_param_number" value="<?php echo esc_attr(get_option('sib_param_number', 'contact_number')); ?>"></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Message Field Name', 'universal-sms-pro-gateway'); ?></th>
                                <td><input type="text" name="sib_param_msg" value="<?php echo esc_attr(get_option('sib_param_msg', 'message')); ?>"></td>
                            </tr>
                        </table>

                        <h3><?php esc_html_e('Balance API Settings', 'universal-sms-pro-gateway'); ?></h3>
                        <table class="form-table">
                            <tr>
                                <th><?php esc_html_e('Balance API URL', 'universal-sms-pro-gateway'); ?></th>
                                <td><input type="url" name="hkdev_balance_api_url" value="<?php echo esc_attr(get_option('hkdev_balance_api_url')); ?>" class="large-text"></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Balance HTTP Method', 'universal-sms-pro-gateway'); ?></th>
                                <td>
                                    <select name="hkdev_balance_http_method">
                                        <option value="POST" <?php selected(get_option('hkdev_balance_http_method', 'GET'), 'POST'); ?>>POST</option>
                                        <option value="GET" <?php selected(get_option('hkdev_balance_http_method', 'GET'), 'GET'); ?>>GET</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Balance Token Field', 'universal-sms-pro-gateway'); ?></th>
                                <td><input type="text" name="hkdev_balance_param_token" value="<?php echo esc_attr(get_option('hkdev_balance_param_token', 'api_token')); ?>"></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Balance Response Key', 'universal-sms-pro-gateway'); ?></th>
                                <td>
                                    <input type="text" name="hkdev_balance_response_key" value="<?php echo esc_attr(get_option('hkdev_balance_response_key', 'balance')); ?>" class="regular-text">
                                    <p class="description"><?php esc_html_e('Use dot notation for nested JSON keys (e.g., data.balance).', 'universal-sms-pro-gateway'); ?></p>
                                </td>
                            </tr>
                        </table>

                        <?php submit_button(__('Save Settings', 'universal-sms-pro-gateway')); ?>
                    </form>
                </div>
            <?php elseif ($tab === 'features') : ?>
                <div class="hkdev-card">
                    <form method="post" action="options.php">
                        <?php settings_fields('sib-pro-settings'); ?>
                        <div class="hkdev-section-header">
                            <h3><?php esc_html_e('OTP Verification Options', 'universal-sms-pro-gateway'); ?></h3>
                        </div>
                        <div class="hkdev-toggle-list">
                            <?php $this->render_toggle('hkdev_enable_otp', __('Enable WooCommerce OTP', 'universal-sms-pro-gateway'), __('Require customers to verify their phone number during checkout.', 'universal-sms-pro-gateway')); ?>
                            <div class="hkdev-toggle-row">
                                <div class="hkdev-toggle-text">
                                    <strong><?php esc_html_e('OTP Length', 'universal-sms-pro-gateway'); ?></strong>
                                </div>
                                <div class="hkdev-select-wrap">
                                    <?php $otp_length = $this->sanitize_otp_length(get_option('hkdev_otp_length', 6)); ?>
                                    <select name="hkdev_otp_length">
                                        <option value="4" <?php selected($otp_length, 4); ?>><?php esc_html_e('4 Digits', 'universal-sms-pro-gateway'); ?></option>
                                        <option value="5" <?php selected($otp_length, 5); ?>><?php esc_html_e('5 Digits', 'universal-sms-pro-gateway'); ?></option>
                                        <option value="6" <?php selected($otp_length, 6); ?>><?php esc_html_e('6 Digits', 'universal-sms-pro-gateway'); ?></option>
                                        <option value="8" <?php selected($otp_length, 8); ?>><?php esc_html_e('8 Digits', 'universal-sms-pro-gateway'); ?></option>
                                    </select>
                                </div>
                            </div>
                            <?php $this->render_toggle('hkdev_enable_failover', __('Enable SMS Failover', 'universal-sms-pro-gateway'), __('Automatically switch to fallback sender IDs if the primary fails.', 'universal-sms-pro-gateway')); ?>
                            <?php $this->render_toggle('hkdev_enable_gateway', __('Enable SMS Gateway', 'universal-sms-pro-gateway'), __('Turn off to pause all outgoing messages.', 'universal-sms-pro-gateway')); ?>
                            <?php $this->render_toggle('hkdev_enable_order_confirmation_sms', __('Enable Order Confirmation SMS', 'universal-sms-pro-gateway'), __('Send confirmation SMS after successful orders.', 'universal-sms-pro-gateway')); ?>
                            <?php $this->render_toggle('hkdev_enable_status_sms', __('Enable Status Update SMS', 'universal-sms-pro-gateway'), __('Notify customers when order status changes.', 'universal-sms-pro-gateway')); ?>
                            <?php $this->render_toggle('hkdev_enable_order_blocker', __('Enable Order Delay Blocker', 'universal-sms-pro-gateway'), __('Protect against rapid duplicate orders.', 'universal-sms-pro-gateway')); ?>
                            <?php $this->render_toggle('hkdev_enable_logs', __('Enable SMS Logs', 'universal-sms-pro-gateway'), __('Store the last 50 SMS attempts.', 'universal-sms-pro-gateway')); ?>
                        </div>
                        <?php submit_button(__('Save Changes', 'universal-sms-pro-gateway')); ?>
                    </form>
                </div>
            <?php elseif ($tab === 'templates') : ?>
                <div class="hkdev-card">
                    <form method="post" action="options.php">
                        <?php settings_fields('sib-pro-settings'); ?>
                        <table class="form-table">
                            <tr>
                                <th><?php esc_html_e('OTP Target Products (IDs, comma-separated)', 'universal-sms-pro-gateway'); ?></th>
                                <td><input type="text" name="sib_target_products" value="<?php echo esc_attr(get_option('sib_target_products')); ?>" class="regular-text"></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('OTP Message Template', 'universal-sms-pro-gateway'); ?></th>
                                <td><textarea name="sib_otp_template" class="large-text" rows="2"><?php echo esc_textarea(get_option('sib_otp_template', 'Your OTP is {otp}.')); ?></textarea></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('OTP Expiry (minutes)', 'universal-sms-pro-gateway'); ?></th>
                                <td><input type="number" min="1" name="hkdev_otp_expiry_minutes" value="<?php echo esc_attr(get_option('hkdev_otp_expiry_minutes', 10)); ?>" class="small-text"></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('OTP Resend Cooldown (seconds)', 'universal-sms-pro-gateway'); ?></th>
                                <td><input type="number" min="0" name="hkdev_otp_cooldown_seconds" value="<?php echo esc_attr(get_option('hkdev_otp_cooldown_seconds', 60)); ?>" class="small-text"></td>
                            </tr>
                            <tr>
                                <th><?php esc_html_e('Order Confirmation Template', 'universal-sms-pro-gateway'); ?></th>
                                <td><textarea name="sib_order_template" class="large-text" rows="2"><?php echo esc_textarea(get_option('sib_order_template', 'Order #{order_id} confirmed. Total: {total}')); ?></textarea></td>
                            </tr>
                        </table>
                        <?php submit_button(__('Save Templates', 'universal-sms-pro-gateway')); ?>
                    </form>
                </div>
            <?php else : ?>
                <div class="hkdev-card">
                    <div class="hkdev-card-header">
                        <h2><?php esc_html_e('Recent Activities', 'universal-sms-pro-gateway'); ?></h2>
                        <form method="post">
                            <?php wp_nonce_field('hkdev_clear_logs_action'); ?>
                            <input type="hidden" name="hkdev_clear_logs" value="1">
                            <?php submit_button(__('Clear Logs', 'universal-sms-pro-gateway'), 'secondary', '', false); ?>
                        </form>
                    </div>
                    <table class="hkdev-log-table">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Time', 'universal-sms-pro-gateway'); ?></th>
                                <th><?php esc_html_e('Recipient', 'universal-sms-pro-gateway'); ?></th>
                                <th><?php esc_html_e('Message', 'universal-sms-pro-gateway'); ?></th>
                                <th><?php esc_html_e('Status', 'universal-sms-pro-gateway'); ?></th>
                                <th><?php esc_html_e('Details', 'universal-sms-pro-gateway'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs)) : ?>
                                <tr><td colspan="5"><?php esc_html_e('No logs yet.', 'universal-sms-pro-gateway'); ?></td></tr>
                            <?php else : ?>
                                <?php foreach ($logs as $log) : ?>
                                    <tr>
                                        <td><small><?php echo esc_html($log['time']); ?></small></td>
                                        <td><?php echo esc_html($log['number']); ?></td>
                                        <td><?php echo esc_html($log['message']); ?></td>
                                        <td><span class="<?php echo $log['status'] === 'Success' ? 'status-success' : 'status-error'; ?>"><?php echo esc_html($log['status']); ?></span></td>
                                        <td><span class="gateway-badge"><?php echo esc_html($log['response']); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function handle_admin_actions()
    {
        $request_method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
        if ($request_method !== 'POST' || !current_user_can('manage_options')) {
            return;
        }

        if (isset($_POST['hkdev_clear_logs'])) {
            check_admin_referer('hkdev_clear_logs_action');
            update_option($this->log_option, []);
            wp_safe_redirect(admin_url('admin.php?page=sib-pro&tab=logs&' . $this->notice_param . '=logs_cleared'));
            exit;
        }
    }

    private function render_admin_notice($notice)
    {
        if ($notice === '') {
            return;
        }

        $messages = [
            'logs_cleared' => __('SMS logs cleared successfully.', 'universal-sms-pro-gateway'),
        ];

        if (!isset($messages[$notice])) {
            return;
        }

        printf(
            '<div class="notice notice-success"><p>%s</p></div>',
            esc_html($messages[$notice])
        );
    }

    private function render_toggle($option, $label, $description)
    {
        $is_enabled = $this->is_feature_enabled($option);
        ?>
        <div class="hkdev-toggle-row">
            <div class="hkdev-toggle-text">
                <strong><?php echo esc_html($label); ?></strong>
                <p class="description"><?php echo esc_html($description); ?></p>
            </div>
            <label class="hkdev-switch">
                <input type="hidden" name="<?php echo esc_attr($option); ?>" value="no">
                <input type="checkbox" name="<?php echo esc_attr($option); ?>" value="yes" <?php checked($is_enabled, true); ?>>
                <span class="hkdev-slider"></span>
            </label>
        </div>
        <?php
    }

    public function ajax_test_sms()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized request.', 'universal-sms-pro-gateway'));
        }

        $this->verify_admin_nonce();

        if (!$this->is_feature_enabled('hkdev_enable_gateway')) {
            wp_send_json_error(__('SMS sending is disabled in settings.', 'universal-sms-pro-gateway'));
        }

        $phone = $this->get_phone_from_request();

        if (empty($phone)) {
            wp_send_json_error(__('Phone number is required.', 'universal-sms-pro-gateway'));
        }

        $res = $this->send_sms($phone, 'Test SMS via Universal Gateway.');
        $res['status'] === 'success'
            ? wp_send_json_success(__('Sent!', 'universal-sms-pro-gateway'))
            : wp_send_json_error($res['message']);
    }

    public function ajax_check_balance()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized request.', 'universal-sms-pro-gateway'));
        }

        $this->verify_admin_nonce();

        $result = $this->fetch_balance();
        if ($result['status'] === 'success') {
            wp_send_json_success($result['data']);
        }

        wp_send_json_error($result['message']);
    }

    private function fetch_balance()
    {
        $api_url = get_option('hkdev_balance_api_url', '');
        if ($api_url === '') {
            return ['status' => 'error', 'message' => __('Balance API URL is not configured.', 'universal-sms-pro-gateway')];
        }

        $api_token = get_option('sib_api_token', '');
        $method = get_option('hkdev_balance_http_method', 'GET');
        $param_token = get_option('hkdev_balance_param_token', 'api_token');

        $data = [];
        if ($api_token !== '') {
            $data[$param_token] = $api_token;
        }

        if ($method === 'GET') {
            $request_url = add_query_arg($data, $api_url);
            $response = wp_remote_get($request_url, ['timeout' => 20]);
        } else {
            $response = wp_remote_post($api_url, [
                'body' => $data,
                'timeout' => 20,
            ]);
        }

        if (is_wp_error($response)) {
            return ['status' => 'error', 'message' => $response->get_error_message()];
        }

        $body_raw = wp_remote_retrieve_body($response);
        $balance_key = trim((string) get_option('hkdev_balance_response_key', 'balance'));
        $value = $this->extract_balance_value($body_raw, $balance_key);

        if ($value === null || $value === '') {
            return ['status' => 'error', 'message' => __('Balance response was empty.', 'universal-sms-pro-gateway')];
        }

        $display = is_array($value) ? wp_json_encode($value) : (string) $value;
        $cache = [
            'amount' => sanitize_text_field($display),
            'checked_at' => current_time('mysql'),
        ];
        update_option($this->balance_option, $cache);

        return ['status' => 'success', 'data' => $cache];
    }

    private function extract_balance_value($body_raw, $balance_key)
    {
        $decoded = json_decode($body_raw, true);
        if (is_array($decoded)) {
            if ($balance_key !== '') {
                $value = $decoded;
                foreach (explode('.', $balance_key) as $segment) {
                    if (is_array($value) && array_key_exists($segment, $value)) {
                        $value = $value[$segment];
                        continue;
                    }
                    $value = null;
                    break;
                }
                if ($value !== null) {
                    return $value;
                }
            }
            return $decoded;
        }

        $raw = trim((string) $body_raw);
        return $raw !== '' ? $raw : null;
    }

    public function ajax_send_otp()
    {
        $this->verify_ajax_nonce();

        if (!$this->is_feature_enabled('hkdev_enable_gateway') || !$this->is_feature_enabled('hkdev_enable_otp')) {
            wp_send_json_error(__('OTP sending is disabled.', 'universal-sms-pro-gateway'));
        }

        if (!function_exists('WC') || !WC()->session) {
            wp_send_json_error(__('WooCommerce session unavailable.', 'universal-sms-pro-gateway'));
        }

        $phone = $this->get_phone_from_request();

        if (empty($phone)) {
            wp_send_json_error(__('Phone number is required.', 'universal-sms-pro-gateway'));
        }

        $cooldown = absint(get_option('hkdev_otp_cooldown_seconds', 60));
        $now = current_time('timestamp');
        $last_sent = (int) WC()->session->get('sib_otp_last_sent');
        if ($cooldown > 0 && $last_sent > 0 && ($now - $last_sent) < $cooldown) {
            $remaining = $cooldown - ($now - $last_sent);
            wp_send_json_error(sprintf(
                __('Please wait %s seconds before requesting another OTP.', 'universal-sms-pro-gateway'),
                (int) $remaining
            ));
        }

        $otp_length = $this->sanitize_otp_length(get_option('hkdev_otp_length', 6));
        try {
            $otp = $this->create_numeric_otp($otp_length);
        } catch (Throwable $exception) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Universal SMS Pro OTP fallback used: ' . $exception->getMessage());
            }
            $otp = $this->generate_fallback_otp($otp_length);
        }

        WC()->session->set('sib_otp', $otp);
        WC()->session->set('sib_otp_verified', false);
        WC()->session->set('sib_otp_last_sent', $now);

        $expiry_minutes = max(1, absint(get_option('hkdev_otp_expiry_minutes', 10)));
        WC()->session->set('sib_otp_expires_at', $now + ($expiry_minutes * MINUTE_IN_SECONDS));

        $message = str_replace('{otp}', $otp, get_option('sib_otp_template', 'OTP: {otp}'));
        $res     = $this->send_sms($phone, $message);

        $res['status'] === 'success'
            ? wp_send_json_success(__('OTP sent.', 'universal-sms-pro-gateway'))
            : wp_send_json_error($res['message']);
    }

    public function ajax_verify_otp()
    {
        $this->verify_ajax_nonce();

        if (!$this->is_feature_enabled('hkdev_enable_otp')) {
            wp_send_json_error(__('OTP verification is disabled.', 'universal-sms-pro-gateway'));
        }

        if (!function_exists('WC') || !WC()->session) {
            wp_send_json_error(__('WooCommerce session unavailable.', 'universal-sms-pro-gateway'));
        }

        $otp = isset($_POST['otp']) ? sanitize_text_field(wp_unslash($_POST['otp'])) : '';
        $expires_at = (int) WC()->session->get('sib_otp_expires_at');

        if ($expires_at && current_time('timestamp') > $expires_at) {
            WC()->session->set('sib_otp_verified', false);
            wp_send_json_error(__('OTP expired. Please request a new one.', 'universal-sms-pro-gateway'));
        }

        if ($otp && $otp === (string) WC()->session->get('sib_otp')) {
            WC()->session->set('sib_otp_verified', true);
            WC()->session->set('sib_otp_expires_at', 0);
            wp_send_json_success();
        }

        wp_send_json_error(__('Invalid OTP', 'universal-sms-pro-gateway'));
    }

    private function is_otp_needed()
    {
        if (!$this->is_feature_enabled('hkdev_enable_otp')) {
            return false;
        }

        if (!class_exists('WooCommerce') || !function_exists('WC') || !WC()->cart) {
            return false;
        }

        $targets = array_filter(array_map('trim', explode(',', get_option('sib_target_products', ''))));

        if (empty($targets)) {
            return false;
        }

        foreach (WC()->cart->get_cart() as $item) {
            if (in_array((string) $item['product_id'], $targets, true)) {
                return true;
            }
        }

        return false;
    }

    public function validate_checkout_otp()
    {
        if (!$this->is_feature_enabled('hkdev_enable_otp')) {
            return;
        }

        if (!function_exists('WC') || !WC()->session) {
            return;
        }

        if ($this->is_otp_needed() && !WC()->session->get('sib_otp_verified')) {
            wc_add_notice(__('Please verify phone via OTP.', 'universal-sms-pro-gateway'), 'error');
        }
    }

    public function send_order_confirmation_sms($order_id)
    {
        if (!$this->is_feature_enabled('hkdev_enable_order_confirmation_sms')) {
            return;
        }

        if (!function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        $phone = $this->normalize_phone($order->get_billing_phone());

        if (empty($phone)) {
            return;
        }

        $template = get_option('sib_order_template', 'Order #{order_id} confirmed.');
        $message  = str_replace(['{order_id}', '{total}'], [$order_id, $order->get_total()], $template);

        $this->send_sms($phone, $message);
    }

    public function send_status_update_sms($order_id, $from, $to, $order)
    {
        if (!$this->is_feature_enabled('hkdev_enable_status_sms')) {
            return;
        }

        if (!$order || !is_object($order)) {
            return;
        }

        $phone = $this->normalize_phone($order->get_billing_phone());

        if (empty($phone)) {
            return;
        }

        $this->send_sms($phone, sprintf('Order #%d status: %s', $order_id, strtoupper($to)));
    }

    public function inject_otp_ui($checkout = null)
    {
        if (
            !$this->is_feature_enabled('hkdev_enable_otp') ||
            !function_exists('is_checkout') ||
            !is_checkout() ||
            !$this->is_otp_needed()
        ) {
            return;
        }
        $otp_length = $this->sanitize_otp_length(get_option('hkdev_otp_length', 6));
        ?>
        <div id="sib-otp-overlay" class="sib-otp-overlay" aria-hidden="true">
            <div class="sib-otp-modal" role="dialog" aria-modal="true" aria-labelledby="sib-otp-title" aria-describedby="sib-otp-subtitle">
                <h3 id="sib-otp-title"><?php esc_html_e('Phone Verification', 'universal-sms-pro-gateway'); ?></h3>
                <p id="sib-otp-subtitle" class="sib-otp-subtitle"><?php esc_html_e('Enter the OTP sent to your phone to complete checkout securely.', 'universal-sms-pro-gateway'); ?></p>
                <label for="sib_otp_code" class="sib-otp-label"><?php esc_html_e('OTP Code', 'universal-sms-pro-gateway'); ?></label>
                <input type="tel" id="sib_otp_code" maxlength="<?php echo esc_attr((string) $otp_length); ?>" inputmode="numeric" autocomplete="one-time-code" aria-required="true" aria-describedby="sib-otp-subtitle" placeholder="<?php esc_attr_e('Enter OTP', 'universal-sms-pro-gateway'); ?>">
                <p id="sib_otp_timer" class="sib-otp-timer" aria-live="polite"></p>
                <button type="button" id="sib_verify"><?php esc_html_e('Verify & Complete Order', 'universal-sms-pro-gateway'); ?></button>
                <p id="sib_msg" class="sib-otp-message" role="alert"></p>
            </div>
        </div>
        <?php
    }

    private function create_numeric_otp($length)
    {
        [$min, $max] = $this->get_otp_bounds($length);
        return random_int($min, $max);
    }

    private function generate_fallback_otp($length = 6)
    {
        [$min, $max] = $this->get_otp_bounds($length);

        if (function_exists('openssl_random_pseudo_bytes')) {
            $is_strong = false;
            $bytes = openssl_random_pseudo_bytes(4, $is_strong);

            if ($bytes !== false && $is_strong) {
                $random_number = unpack('N', $bytes)[1];
                return $min + ($random_number % ($max - $min + 1));
            }
        }

        return wp_rand($min, $max);
    }

    private function get_otp_bounds($length)
    {
        $otp_length = $this->sanitize_otp_length($length);
        $range_map = [
            4 => [1000, 9999],
            5 => [10000, 99999],
            6 => [100000, 999999],
            8 => [10000000, 99999999],
        ];

        return $range_map[$otp_length];
    }
}
