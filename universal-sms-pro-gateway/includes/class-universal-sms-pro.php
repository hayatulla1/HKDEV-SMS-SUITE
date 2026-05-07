<?php

if (!defined('ABSPATH')) {
    exit;
}

class Universal_SMS_Pro
{
    private $log_option = 'sib_sms_logs';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'create_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        add_action('wp_footer', [$this, 'inject_otp_ui']);

        add_action('wp_ajax_sib_send_otp', [$this, 'ajax_send_otp']);
        add_action('wp_ajax_nopriv_sib_send_otp', [$this, 'ajax_send_otp']);
        add_action('wp_ajax_sib_verify_otp', [$this, 'ajax_verify_otp']);
        add_action('wp_ajax_nopriv_sib_verify_otp', [$this, 'ajax_verify_otp']);
        add_action('wp_ajax_sib_test_sms', [$this, 'ajax_test_sms']);

        add_action('woocommerce_checkout_process', [$this, 'validate_checkout_otp']);
        add_action('woocommerce_thankyou', [$this, 'send_order_confirmation_sms'], 10, 1);
        add_action('woocommerce_order_status_changed', [$this, 'send_status_update_sms'], 10, 4);
    }

    public function enqueue_admin_assets($hook)
    {
        if ($hook !== 'toplevel_page_sib-pro') {
            return;
        }

        wp_enqueue_style(
            'usp-admin-style',
            USP_SMS_PLUGIN_URL . 'assets/css/admin.css',
            [],
            '4.0.0'
        );
    }

    public function enqueue_frontend_assets()
    {
        if (!function_exists('is_checkout') || !is_checkout() || !$this->is_otp_needed()) {
            return;
        }

        wp_enqueue_style(
            'usp-frontend-style',
            USP_SMS_PLUGIN_URL . 'assets/css/frontend.css',
            [],
            '4.0.0'
        );

        wp_enqueue_script(
            'usp-frontend-script',
            USP_SMS_PLUGIN_URL . 'assets/js/frontend.js',
            ['jquery'],
            '4.0.0',
            true
        );

        wp_localize_script('usp-frontend-script', 'uspSmsData', [
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('sib_otp_nonce'),
            'isVerified' => (bool) WC()->session->get('sib_otp_verified'),
            'messages'   => [
                'invalidOtp' => __('Invalid OTP', 'universal-sms-pro-gateway'),
                'sendFailed' => __('Failed to send OTP.', 'universal-sms-pro-gateway'),
            ],
        ]);
    }

    public function create_menu()
    {
        add_menu_page(
            __('SMS Gateway', 'universal-sms-pro-gateway'),
            __('SMS Gateway', 'universal-sms-pro-gateway'),
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
    }

    public function sanitize_http_method($method)
    {
        return strtoupper($method) === 'GET' ? 'GET' : 'POST';
    }

    public function sanitize_target_products($value)
    {
        $ids = array_filter(array_map('trim', explode(',', sanitize_text_field($value))), static function ($id) {
            return ctype_digit((string) $id);
        });

        return implode(',', $ids);
    }

    private function verify_ajax_nonce()
    {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';

        if (!wp_verify_nonce($nonce, 'sib_otp_nonce')) {
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

    private function log_sms($number, $message, $status, $response)
    {
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
        $api_url     = get_option('sib_gateway_url', 'https://api.smsinbd.com/sms-api/sendsms');
        $api_token   = get_option('sib_api_token', '');
        $raw_senders = get_option('sib_sender_id', '');
        $method      = get_option('sib_http_method', 'POST');

        $p_token  = get_option('sib_param_token', 'api_token');
        $p_sender = get_option('sib_param_sender', 'senderid');
        $p_number = get_option('sib_param_number', 'contact_number');
        $p_msg    = get_option('sib_param_msg', 'message');

        $sender_ids = array_filter(array_map('trim', explode(',', $raw_senders)));

        if (empty($sender_ids)) {
            $this->log_sms($number, $message, 'Failed', 'No Sender ID configured');
            return ['status' => 'error', 'message' => 'No Sender ID configured'];
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

            $normalized_raw  = strtolower(trim((string) $body_raw));
            $status_success  = isset($body['status']) && strtolower((string) $body['status']) === 'success';
            $exact_success   = in_array($normalized_raw, ['success', 'ok', 'sent', '1000'], true);
            $json_success    = (bool) preg_match('/"status"\s*:\s*"success"/i', (string) $body_raw);

            if ($status_success || $exact_success || $json_success) {
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
        $tab = isset($_GET['tab']) ? sanitize_text_field(wp_unslash($_GET['tab'])) : 'settings';
        if (!in_array($tab, ['settings', 'templates', 'logs'], true)) {
            $tab = 'settings';
        }
        $logs = get_option($this->log_option, []);
        ?>
        <div class="wrap usp-wrap">
            <h1><?php esc_html_e('Universal SMS Pro Gateway', 'universal-sms-pro-gateway'); ?></h1>

            <h2 class="nav-tab-wrapper usp-nav-tab-wrapper">
                <a href="?page=sib-pro&tab=settings" class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('API Settings', 'universal-sms-pro-gateway'); ?></a>
                <a href="?page=sib-pro&tab=templates" class="nav-tab <?php echo $tab === 'templates' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('Templates & OTP', 'universal-sms-pro-gateway'); ?></a>
                <a href="?page=sib-pro&tab=logs" class="nav-tab <?php echo $tab === 'logs' ? 'nav-tab-active' : ''; ?>"><?php esc_html_e('History', 'universal-sms-pro-gateway'); ?></a>
            </h2>

            <?php if ($tab === 'settings') : ?>
                <div class="usp-card">
                    <form method="post" action="options.php">
                        <?php settings_fields('sib-pro-settings'); ?>

                        <h3><?php esc_html_e('1. Provider Configuration', 'universal-sms-pro-gateway'); ?></h3>
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

                        <h3><?php esc_html_e('2. API Parameter Mapping', 'universal-sms-pro-gateway'); ?></h3>
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

                        <?php submit_button(__('Save Settings', 'universal-sms-pro-gateway')); ?>
                    </form>
                </div>
            <?php elseif ($tab === 'templates') : ?>
                <div class="usp-card">
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
                                <th><?php esc_html_e('Order Confirmation Template', 'universal-sms-pro-gateway'); ?></th>
                                <td><textarea name="sib_order_template" class="large-text" rows="2"><?php echo esc_textarea(get_option('sib_order_template', 'Order #{order_id} confirmed. Total: {total}')); ?></textarea></td>
                            </tr>
                        </table>
                        <?php submit_button(__('Save Templates', 'universal-sms-pro-gateway')); ?>
                    </form>
                </div>
            <?php else : ?>
                <div class="usp-card">
                    <h2><?php esc_html_e('Recent Activities', 'universal-sms-pro-gateway'); ?></h2>
                    <table class="usp-log-table">
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

    public function ajax_test_sms()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized request.', 'universal-sms-pro-gateway'));
        }

        $this->verify_ajax_nonce();

        $phone = $this->get_phone_from_request();

        if (empty($phone)) {
            wp_send_json_error(__('Phone number is required.', 'universal-sms-pro-gateway'));
        }

        $res = $this->send_sms($phone, 'Test SMS via Universal Gateway.');
        $res['status'] === 'success'
            ? wp_send_json_success(__('Sent!', 'universal-sms-pro-gateway'))
            : wp_send_json_error($res['message']);
    }

    public function ajax_send_otp()
    {
        $this->verify_ajax_nonce();

        if (!function_exists('WC') || !WC()->session) {
            wp_send_json_error(__('WooCommerce session unavailable.', 'universal-sms-pro-gateway'));
        }

        $phone = $this->get_phone_from_request();

        if (empty($phone)) {
            wp_send_json_error(__('Phone number is required.', 'universal-sms-pro-gateway'));
        }

        try {
            $otp = random_int(100000, 999999);
        } catch (Exception $exception) {
            $otp = wp_rand(100000, 999999);
        }

        WC()->session->set('sib_otp', $otp);
        WC()->session->set('sib_otp_verified', false);

        $message = str_replace('{otp}', $otp, get_option('sib_otp_template', 'OTP: {otp}'));
        $res     = $this->send_sms($phone, $message);

        $res['status'] === 'success'
            ? wp_send_json_success(__('OTP sent.', 'universal-sms-pro-gateway'))
            : wp_send_json_error($res['message']);
    }

    public function ajax_verify_otp()
    {
        $this->verify_ajax_nonce();

        if (!function_exists('WC') || !WC()->session) {
            wp_send_json_error(__('WooCommerce session unavailable.', 'universal-sms-pro-gateway'));
        }

        $otp = isset($_POST['otp']) ? sanitize_text_field(wp_unslash($_POST['otp'])) : '';

        if ($otp && $otp === (string) WC()->session->get('sib_otp')) {
            WC()->session->set('sib_otp_verified', true);
            wp_send_json_success();
        }

        wp_send_json_error(__('Invalid OTP', 'universal-sms-pro-gateway'));
    }

    private function is_otp_needed()
    {
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
        if (!function_exists('WC') || !WC()->session) {
            return;
        }

        if ($this->is_otp_needed() && !WC()->session->get('sib_otp_verified')) {
            wc_add_notice(__('Please verify phone via OTP.', 'universal-sms-pro-gateway'), 'error');
        }
    }

    public function send_order_confirmation_sms($order_id)
    {
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
        if (!$order || !is_object($order)) {
            return;
        }

        $phone = $this->normalize_phone($order->get_billing_phone());

        if (empty($phone)) {
            return;
        }

        $this->send_sms($phone, sprintf('Order #%d status: %s', $order_id, strtoupper($to)));
    }

    public function inject_otp_ui()
    {
        if (!function_exists('is_checkout') || !is_checkout() || !$this->is_otp_needed()) {
            return;
        }
        ?>
        <div id="sib-otp-overlay" class="sib-otp-overlay" aria-hidden="true">
            <div class="sib-otp-modal">
                <h3><?php esc_html_e('Phone Verification', 'universal-sms-pro-gateway'); ?></h3>
                <input type="text" id="sib_otp_code" placeholder="<?php esc_attr_e('Enter OTP', 'universal-sms-pro-gateway'); ?>">
                <button type="button" id="sib_verify"><?php esc_html_e('Verify & Complete Order', 'universal-sms-pro-gateway'); ?></button>
                <p id="sib_msg" role="alert"></p>
            </div>
        </div>
        <?php
    }
}
