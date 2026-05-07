<?php

if (!defined('ABSPATH')) {
    exit;
}

class USP_WC_Order_Delay_Blocker
{
    private const OPTION_DURATION_DAYS = 'usp_wcodb_block_duration_days';
    private const OPTION_DURATION_HOURS = 'usp_wcodb_block_duration_hours';
    private const OPTION_DURATION_MINUTES = 'usp_wcodb_block_duration_minutes';
    private const OPTION_COMBINED_BLOCK = 'usp_wcodb_combined_block_enabled';
    private const OPTION_MANUAL_BLOCKED_LIST = 'usp_wcodb_manual_blocked_list';
    private const OPTION_AUTOMATIC_BLOCK_LOG = 'usp_wcodb_automatic_block_log';
    private const OPTION_SALT_KEY = 'usp_wcodb_salt_key';

    private const DEFAULT_DURATION_MINUTES = 60;
    private const DEFAULT_DURATION_HOURS = 0;
    private const DEFAULT_DURATION_DAYS = 0;
    private const MAX_LOG_ENTRIES = 500;

    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);

        add_action('woocommerce_checkout_process', [$this, 'validate_bd_phone']);
        add_action('woocommerce_checkout_process', [$this, 'maybe_block_checkout']);

        add_action('woocommerce_thankyou', [$this, 'set_block_transient'], 10, 1);
        add_action('woocommerce_order_status_processing', [$this, 'on_order_status_changed'], 10, 1);
        add_action('woocommerce_order_status_completed', [$this, 'on_order_status_changed'], 10, 1);
    }

    public function register_admin_menu()
    {
        add_submenu_page(
            'sib-pro',
            __('Order Delay Blocker', 'universal-sms-pro-gateway'),
            __('Order Delay Blocker', 'universal-sms-pro-gateway'),
            'manage_woocommerce',
            'usp-order-delay-blocker',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings()
    {
        register_setting('usp_wcodb_settings_group', self::OPTION_DURATION_MINUTES, ['type' => 'integer', 'sanitize_callback' => 'absint', 'default' => self::DEFAULT_DURATION_MINUTES]);
        register_setting('usp_wcodb_settings_group', self::OPTION_DURATION_HOURS, ['type' => 'integer', 'sanitize_callback' => 'absint', 'default' => self::DEFAULT_DURATION_HOURS]);
        register_setting('usp_wcodb_settings_group', self::OPTION_DURATION_DAYS, ['type' => 'integer', 'sanitize_callback' => 'absint', 'default' => self::DEFAULT_DURATION_DAYS]);
        register_setting('usp_wcodb_settings_group', self::OPTION_COMBINED_BLOCK, ['type' => 'string', 'sanitize_callback' => [$this, 'sanitize_checkbox'], 'default' => 'no']);

        $this->initialize_salt_key();
    }

    public function sanitize_checkbox($value)
    {
        return $value === 'yes' ? 'yes' : 'no';
    }

    public function validate_bd_phone()
    {
        $raw_phone = isset($_POST['billing_phone']) ? sanitize_text_field(wp_unslash($_POST['billing_phone'])) : '';
        if ($raw_phone === '') {
            return;
        }

        $phone = $this->normalize_phone($raw_phone);
        if (!$this->is_valid_bd_phone($phone)) {
            wc_add_notice(__('Please enter a valid Bangladeshi phone number (e.g., 017XXXXXXXX).', 'universal-sms-pro-gateway'), 'error');
        }
    }

    public function maybe_block_checkout()
    {
        $ip = $this->get_user_ip();
        $raw_phone = isset($_POST['billing_phone']) ? sanitize_text_field(wp_unslash($_POST['billing_phone'])) : '';
        $phone = $this->normalize_phone($raw_phone);

        $manual_block = $this->find_manual_block($ip, $phone);
        if (!empty($manual_block)) {
            wc_add_notice(__('Order blocked for this IP/phone combination by admin.', 'universal-sms-pro-gateway'), 'error');
            return;
        }

        $duration_seconds = $this->get_duration_seconds();
        $combined_enabled = get_option(self::OPTION_COMBINED_BLOCK, 'no') === 'yes';

        if ($combined_enabled && !empty($ip) && !empty($phone)) {
            $comb_key = $this->get_combined_transient_key($ip, $phone);
            $data = get_transient($comb_key);
            if (!empty($data) && !empty($data['expires_at'])) {
                $remaining = max(0, (int) $data['expires_at'] - current_time('timestamp'));
                if ($remaining > 0) {
                    wc_add_notice(sprintf(
                        __('You recently placed an order from this IP and phone. Please wait %s before placing another order.', 'universal-sms-pro-gateway'),
                        $this->human_readable_seconds($remaining)
                    ), 'error');
                }
                return;
            }
        }

        $ip_data = !empty($ip) ? get_transient($this->get_ip_transient_key($ip)) : false;
        if (!empty($ip_data) && !empty($ip_data['expires_at'])) {
            $remaining = max(0, (int) $ip_data['expires_at'] - current_time('timestamp'));
            if ($remaining > 0) {
                wc_add_notice(sprintf(
                    __('Orders from your IP are temporarily blocked for %s.', 'universal-sms-pro-gateway'),
                    $this->human_readable_seconds($remaining)
                ), 'error');
            }
            return;
        }

        if (!empty($phone) && $this->is_valid_bd_phone($phone)) {
            $phone_data = get_transient($this->get_phone_transient_key($phone));
            if (!empty($phone_data) && !empty($phone_data['expires_at'])) {
                $remaining = max(0, (int) $phone_data['expires_at'] - current_time('timestamp'));
                if ($remaining > 0) {
                    wc_add_notice(sprintf(
                        __('This phone number is temporarily blocked for %s.', 'universal-sms-pro-gateway'),
                        $this->human_readable_seconds($remaining)
                    ), 'error');
                }
            }
        }
    }

    public function on_order_status_changed($order_id)
    {
        $this->set_block_transient($order_id);
    }

    public function set_block_transient($order_id)
    {
        $order_id = absint($order_id);
        if (!$order_id || $this->has_log_for_order($order_id) || !function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $duration_seconds = $this->get_duration_seconds();
        $now = current_time('timestamp');
        $expires_at = $now + $duration_seconds;

        $ip = '';
        if (method_exists($order, 'get_customer_ip_address')) {
            $ip = (string) $order->get_customer_ip_address();
        }
        if (empty($ip)) {
            $ip = (string) get_post_meta($order_id, '_customer_ip_address', true);
        }
        $phone = $this->normalize_phone((string) $order->get_billing_phone());
        $order_number = method_exists($order, 'get_order_number') ? $order->get_order_number() : (string) $order_id;

        if (!empty($ip)) {
            set_transient(
                $this->get_ip_transient_key($ip),
                ['expires_at' => $expires_at, 'order_id' => $order_id, 'order_number' => (string) $order_number],
                $duration_seconds
            );
        }

        if (!empty($phone) && $this->is_valid_bd_phone($phone)) {
            set_transient(
                $this->get_phone_transient_key($phone),
                ['expires_at' => $expires_at, 'order_id' => $order_id, 'order_number' => (string) $order_number],
                $duration_seconds
            );
        }

        $combined_enabled = get_option(self::OPTION_COMBINED_BLOCK, 'no') === 'yes';
        if ($combined_enabled && !empty($ip) && !empty($phone)) {
            set_transient(
                $this->get_combined_transient_key($ip, $phone),
                ['expires_at' => $expires_at, 'order_id' => $order_id, 'order_number' => (string) $order_number],
                $duration_seconds
            );
        }

        $log = get_option(self::OPTION_AUTOMATIC_BLOCK_LOG, []);
        array_unshift($log, [
            'order_id' => $order_id,
            'order_number' => (string) $order_number,
            'ip' => $ip,
            'phone' => $phone,
            'time' => current_time('mysql'),
            'expires_at' => $expires_at,
        ]);
        update_option(self::OPTION_AUTOMATIC_BLOCK_LOG, array_slice($log, 0, self::MAX_LOG_ENTRIES));
    }

    public function render_settings_page()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to access this page.', 'universal-sms-pro-gateway'));
        }

        $this->handle_admin_actions();

        $minutes = (int) get_option(self::OPTION_DURATION_MINUTES, self::DEFAULT_DURATION_MINUTES);
        $hours = (int) get_option(self::OPTION_DURATION_HOURS, self::DEFAULT_DURATION_HOURS);
        $days = (int) get_option(self::OPTION_DURATION_DAYS, self::DEFAULT_DURATION_DAYS);
        $combined_enabled = get_option(self::OPTION_COMBINED_BLOCK, 'no');
        $manual_list = get_option(self::OPTION_MANUAL_BLOCKED_LIST, []);
        $log = get_option(self::OPTION_AUTOMATIC_BLOCK_LOG, []);
        $msg = isset($_GET['usp_wcodb_msg']) ? sanitize_text_field(wp_unslash($_GET['usp_wcodb_msg'])) : '';
        ?>
        <div class="wrap usp-wrap">
            <h1><?php esc_html_e('WooCommerce Order Delay Blocker', 'universal-sms-pro-gateway'); ?></h1>
            <?php $this->render_notice($msg); ?>

            <div class="usp-card">
                <form method="post" action="options.php">
                    <?php settings_fields('usp_wcodb_settings_group'); ?>
                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e('Block Duration Days', 'universal-sms-pro-gateway'); ?></th>
                            <td><input type="number" min="0" name="<?php echo esc_attr(self::OPTION_DURATION_DAYS); ?>" value="<?php echo esc_attr($days); ?>" class="small-text"></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Block Duration Hours', 'universal-sms-pro-gateway'); ?></th>
                            <td><input type="number" min="0" name="<?php echo esc_attr(self::OPTION_DURATION_HOURS); ?>" value="<?php echo esc_attr($hours); ?>" class="small-text"></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Block Duration Minutes', 'universal-sms-pro-gateway'); ?></th>
                            <td><input type="number" min="0" name="<?php echo esc_attr(self::OPTION_DURATION_MINUTES); ?>" value="<?php echo esc_attr($minutes); ?>" class="small-text"></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Enable IP + Phone Combined Block', 'universal-sms-pro-gateway'); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="<?php echo esc_attr(self::OPTION_COMBINED_BLOCK); ?>" value="yes" <?php checked($combined_enabled, 'yes'); ?>>
                                    <?php esc_html_e('Only block when both IP and phone match the last order', 'universal-sms-pro-gateway'); ?>
                                </label>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button(__('Save Blocker Settings', 'universal-sms-pro-gateway')); ?>
                </form>
            </div>

            <div class="usp-card">
                <h2><?php esc_html_e('Manual Block', 'universal-sms-pro-gateway'); ?></h2>
                <form method="post">
                    <?php wp_nonce_field('usp_wcodb_manual_block_action'); ?>
                    <input type="hidden" name="usp_wcodb_manual_block" value="1">
                    <table class="form-table">
                        <tr>
                            <th><?php esc_html_e('IP Address', 'universal-sms-pro-gateway'); ?></th>
                            <td><input type="text" name="manual_ip" class="regular-text" placeholder="203.0.113.10"></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Phone Number', 'universal-sms-pro-gateway'); ?></th>
                            <td><input type="text" name="manual_phone" class="regular-text" placeholder="017XXXXXXXX"></td>
                        </tr>
                        <tr>
                            <th><?php esc_html_e('Note', 'universal-sms-pro-gateway'); ?></th>
                            <td><input type="text" name="manual_note" class="large-text"></td>
                        </tr>
                    </table>
                    <?php submit_button(__('Add Manual Block', 'universal-sms-pro-gateway'), 'secondary'); ?>
                </form>
            </div>

            <div class="usp-card">
                <h2><?php esc_html_e('Manual Blocked List', 'universal-sms-pro-gateway'); ?></h2>
                <table class="usp-log-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Added Time', 'universal-sms-pro-gateway'); ?></th>
                            <th><?php esc_html_e('IP', 'universal-sms-pro-gateway'); ?></th>
                            <th><?php esc_html_e('Phone', 'universal-sms-pro-gateway'); ?></th>
                            <th><?php esc_html_e('Note', 'universal-sms-pro-gateway'); ?></th>
                            <th><?php esc_html_e('Action', 'universal-sms-pro-gateway'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($manual_list)) : ?>
                            <tr><td colspan="5"><?php esc_html_e('No manual blocks found.', 'universal-sms-pro-gateway'); ?></td></tr>
                        <?php else : ?>
                            <?php foreach ($manual_list as $key => $entry) : ?>
                                <tr>
                                    <td><?php echo esc_html($entry['time'] ?? ''); ?></td>
                                    <td><?php echo esc_html($entry['ip'] ?? ''); ?></td>
                                    <td><?php echo esc_html($entry['phone'] ?? ''); ?></td>
                                    <td><?php echo esc_html($entry['note'] ?? ''); ?></td>
                                    <td>
                                        <form method="post">
                                            <?php wp_nonce_field('usp_wcodb_unblock_nonce'); ?>
                                            <input type="hidden" name="usp_wcodb_unblock_action_btn" value="1">
                                            <input type="hidden" name="unblock_key" value="<?php echo esc_attr($key); ?>">
                                            <?php submit_button(__('Unblock', 'universal-sms-pro-gateway'), 'delete', '', false); ?>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="usp-card">
                <h2><?php esc_html_e('Automatic Block Log', 'universal-sms-pro-gateway'); ?></h2>
                <table class="usp-log-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Time', 'universal-sms-pro-gateway'); ?></th>
                            <th><?php esc_html_e('Order', 'universal-sms-pro-gateway'); ?></th>
                            <th><?php esc_html_e('IP', 'universal-sms-pro-gateway'); ?></th>
                            <th><?php esc_html_e('Phone', 'universal-sms-pro-gateway'); ?></th>
                            <th><?php esc_html_e('Expires', 'universal-sms-pro-gateway'); ?></th>
                            <th><?php esc_html_e('Action', 'universal-sms-pro-gateway'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($log)) : ?>
                            <tr><td colspan="6"><?php esc_html_e('No automatic block logs found.', 'universal-sms-pro-gateway'); ?></td></tr>
                        <?php else : ?>
                            <?php foreach ($log as $entry) : ?>
                                <tr>
                                    <td><?php echo esc_html($entry['time'] ?? ''); ?></td>
                                    <td><?php echo esc_html('#' . ($entry['order_number'] ?? $entry['order_id'] ?? '')); ?></td>
                                    <td><?php echo esc_html($entry['ip'] ?? ''); ?></td>
                                    <td><?php echo esc_html($entry['phone'] ?? ''); ?></td>
                                    <td><?php echo esc_html($this->format_expiry_datetime((int) ($entry['expires_at'] ?? 0))); ?></td>
                                    <td>
                                        <form method="post">
                                            <?php wp_nonce_field('usp_wcodb_remove_log_nonce'); ?>
                                            <input type="hidden" name="usp_wcodb_remove_log_btn" value="1">
                                            <?php
                                            $remove_token = base64_encode((string) wp_json_encode([
                                                'order_id' => (string) ($entry['order_id'] ?? ''),
                                                'time' => (string) ($entry['time'] ?? ''),
                                            ]));
                                            ?>
                                            <input type="hidden" name="remove_log_index" value="<?php echo esc_attr($remove_token); ?>">
                                            <?php submit_button(__('Remove', 'universal-sms-pro-gateway'), 'delete', '', false); ?>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                <form method="post" style="margin-top:16px;">
                    <?php wp_nonce_field('usp_wcodb_clear_action'); ?>
                    <input type="hidden" name="usp_wcodb_clear_all" value="1">
                    <?php submit_button(__('Clear Logs and Manual Blocks', 'universal-sms-pro-gateway'), 'delete'); ?>
                </form>
            </div>
        </div>
        <?php
    }

    private function handle_admin_actions()
    {
        $request_method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
        if ($request_method !== 'POST' || !current_user_can('manage_woocommerce')) {
            return;
        }

        if (isset($_POST['usp_wcodb_manual_block'])) {
            check_admin_referer('usp_wcodb_manual_block_action');
            $ip = isset($_POST['manual_ip']) ? sanitize_text_field(wp_unslash($_POST['manual_ip'])) : '';
            $raw_phone = isset($_POST['manual_phone']) ? sanitize_text_field(wp_unslash($_POST['manual_phone'])) : '';
            $phone = $this->normalize_phone($raw_phone);
            $note = isset($_POST['manual_note']) ? sanitize_text_field(wp_unslash($_POST['manual_note'])) : '';

            if (!empty($phone) && !$this->is_valid_bd_phone($phone)) {
                $this->redirect_with_message('manual_invalid');
            }

            if (empty($ip) && empty($phone)) {
                $this->redirect_with_message('manual_invalid');
            }

            $list = get_option(self::OPTION_MANUAL_BLOCKED_LIST, []);
            $key = 'usp_wcodb_manual_' . $this->hash_key($ip . '::' . $phone);
            $list[$key] = [
                'ip' => $ip,
                'phone' => $phone,
                'time' => current_time('mysql'),
                'note' => $note,
            ];
            update_option(self::OPTION_MANUAL_BLOCKED_LIST, $list);
            $this->redirect_with_message('manual_added');
        }

        if (isset($_POST['usp_wcodb_unblock_action_btn'])) {
            check_admin_referer('usp_wcodb_unblock_nonce');
            $key = isset($_POST['unblock_key']) ? sanitize_text_field(wp_unslash($_POST['unblock_key'])) : '';

            $manual_list = get_option(self::OPTION_MANUAL_BLOCKED_LIST, []);
            if (isset($manual_list[$key])) {
                unset($manual_list[$key]);
                update_option(self::OPTION_MANUAL_BLOCKED_LIST, $manual_list);
            }

            $this->redirect_with_message('unblocked');
        }

        if (isset($_POST['usp_wcodb_clear_all'])) {
            check_admin_referer('usp_wcodb_clear_action');
            $log = get_option(self::OPTION_AUTOMATIC_BLOCK_LOG, []);
            foreach ($log as $entry) {
                $ip = isset($entry['ip']) ? (string) $entry['ip'] : '';
                $phone = isset($entry['phone']) ? (string) $entry['phone'] : '';
                if (!empty($ip)) {
                    delete_transient($this->get_ip_transient_key($ip));
                }
                if (!empty($phone)) {
                    delete_transient($this->get_phone_transient_key($phone));
                }
                if (!empty($ip) && !empty($phone)) {
                    delete_transient($this->get_combined_transient_key($ip, $phone));
                }
            }
            update_option(self::OPTION_AUTOMATIC_BLOCK_LOG, []);
            update_option(self::OPTION_MANUAL_BLOCKED_LIST, []);
            $this->redirect_with_message('cleared');
        }

        if (isset($_POST['usp_wcodb_remove_log_btn'])) {
            check_admin_referer('usp_wcodb_remove_log_nonce');
            $remove_index = isset($_POST['remove_log_index']) ? sanitize_text_field(wp_unslash($_POST['remove_log_index'])) : '';
            $decoded = json_decode(base64_decode($remove_index, true), true);
            $order_id_part = isset($decoded['order_id']) ? trim((string) $decoded['order_id']) : '';
            $time_part = isset($decoded['time']) ? trim((string) $decoded['time']) : '';

            $log = get_option(self::OPTION_AUTOMATIC_BLOCK_LOG, []);
            $found = false;

            foreach ($log as $idx => $entry) {
                if (
                    $order_id_part !== '' &&
                    isset($entry['order_id']) &&
                    (string) $entry['order_id'] === $order_id_part &&
                    $time_part !== '' &&
                    isset($entry['time']) &&
                    (string) $entry['time'] === $time_part
                ) {
                    $ip = isset($entry['ip']) ? (string) $entry['ip'] : '';
                    $phone = isset($entry['phone']) ? (string) $entry['phone'] : '';
                    if (!empty($ip)) {
                        delete_transient($this->get_ip_transient_key($ip));
                    }
                    if (!empty($phone)) {
                        delete_transient($this->get_phone_transient_key($phone));
                    }
                    if (!empty($ip) && !empty($phone)) {
                        delete_transient($this->get_combined_transient_key($ip, $phone));
                    }

                    unset($log[$idx]);
                    $found = true;
                    break;
                }
            }

            if ($found) {
                update_option(self::OPTION_AUTOMATIC_BLOCK_LOG, array_values($log));
                $this->redirect_with_message('log_removed');
                return;
            }

            $this->redirect_with_message('log_not_found');
        }
    }

    private function render_notice($msg)
    {
        if (empty($msg)) {
            return;
        }

        $messages = [
            'manual_added' => __('Manual block added successfully.', 'universal-sms-pro-gateway'),
            'manual_invalid' => __('Please provide a valid IP or Bangladeshi phone number.', 'universal-sms-pro-gateway'),
            'unblocked' => __('Entry removed successfully.', 'universal-sms-pro-gateway'),
            'cleared' => __('Logs and manual blocks cleared.', 'universal-sms-pro-gateway'),
            'log_removed' => __('Log entry removed.', 'universal-sms-pro-gateway'),
            'log_not_found' => __('Log entry was not found.', 'universal-sms-pro-gateway'),
        ];

        if (!isset($messages[$msg])) {
            return;
        }

        $class = in_array($msg, ['manual_invalid', 'log_not_found'], true) ? 'notice notice-error' : 'notice notice-success';
        printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($messages[$msg]));
    }

    private function redirect_with_message($message)
    {
        wp_safe_redirect(admin_url('admin.php?page=usp-order-delay-blocker&usp_wcodb_msg=' . rawurlencode($message)));
        exit;
    }

    private function normalize_phone($phone)
    {
        if (empty($phone)) {
            return '';
        }

        $digits = preg_replace('/[^0-9]/', '', (string) $phone);
        if (strlen($digits) > 11) {
            $digits = substr($digits, -11);
        }
        return $digits;
    }

    private function is_valid_bd_phone($phone)
    {
        return (bool) preg_match('/^01\d{9}$/', (string) $phone);
    }

    private function get_user_ip()
    {
        $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        foreach ($keys as $key) {
            if (!isset($_SERVER[$key]) || empty($_SERVER[$key])) {
                continue;
            }

            $raw = sanitize_text_field(wp_unslash($_SERVER[$key]));
            $parts = array_map('trim', explode(',', $raw));
            foreach ($parts as $candidate) {
                if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                    return $candidate;
                }
            }
        }
        return '';
    }

    private function get_duration_seconds()
    {
        $minutes = (int) get_option(self::OPTION_DURATION_MINUTES, self::DEFAULT_DURATION_MINUTES);
        $hours = (int) get_option(self::OPTION_DURATION_HOURS, self::DEFAULT_DURATION_HOURS);
        $days = (int) get_option(self::OPTION_DURATION_DAYS, self::DEFAULT_DURATION_DAYS);
        $seconds = ($days * DAY_IN_SECONDS) + ($hours * HOUR_IN_SECONDS) + ($minutes * MINUTE_IN_SECONDS);
        return max(MINUTE_IN_SECONDS, $seconds);
    }

    private function hash_key($input)
    {
        $this->initialize_salt_key();
        $salt = get_option(self::OPTION_SALT_KEY, '');
        return hash('sha256', $salt . '::' . $input);
    }

    private function initialize_salt_key()
    {
        if (!get_option(self::OPTION_SALT_KEY, '')) {
            add_option(self::OPTION_SALT_KEY, wp_generate_password(32, true, true), '', false);
        }
    }

    private function get_ip_transient_key($ip)
    {
        return 'usp_wcodb_ip_' . $this->hash_key((string) $ip);
    }

    private function get_phone_transient_key($phone)
    {
        return 'usp_wcodb_ph_' . $this->hash_key((string) $phone);
    }

    private function get_combined_transient_key($ip, $phone)
    {
        return 'usp_wcodb_comb_' . $this->hash_key((string) $ip . '::' . (string) $phone);
    }

    private function human_readable_seconds($seconds)
    {
        if ($seconds <= 0) {
            return __('Expired', 'universal-sms-pro-gateway');
        }

        $parts = [];
        $days = (int) floor($seconds / DAY_IN_SECONDS);
        if ($days) {
            $parts[] = sprintf(_n('%d day', '%d days', $days, 'universal-sms-pro-gateway'), $days);
            $seconds -= $days * DAY_IN_SECONDS;
        }

        $hours = (int) floor($seconds / HOUR_IN_SECONDS);
        if ($hours) {
            $parts[] = sprintf(_n('%d hour', '%d hours', $hours, 'universal-sms-pro-gateway'), $hours);
            $seconds -= $hours * HOUR_IN_SECONDS;
        }

        $minutes = (int) floor($seconds / MINUTE_IN_SECONDS);
        if ($minutes) {
            $parts[] = sprintf(_n('%d minute', '%d minutes', $minutes, 'universal-sms-pro-gateway'), $minutes);
        }

        if (empty($parts)) {
            return __('Less than a minute', 'universal-sms-pro-gateway');
        }

        return implode(', ', $parts);
    }

    private function format_expiry_datetime($timestamp)
    {
        if (empty($timestamp)) {
            return '';
        }

        return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }

    private function has_log_for_order($order_id)
    {
        $order_id = absint($order_id);
        if (!$order_id) {
            return false;
        }

        $log = get_option(self::OPTION_AUTOMATIC_BLOCK_LOG, []);
        foreach ($log as $entry) {
            if (!empty($entry['order_id']) && absint($entry['order_id']) === $order_id) {
                return true;
            }
        }
        return false;
    }

    private function find_manual_block($ip, $phone)
    {
        $manual_list = get_option(self::OPTION_MANUAL_BLOCKED_LIST, []);
        foreach ($manual_list as $entry) {
            $entry_ip = isset($entry['ip']) ? (string) $entry['ip'] : '';
            $entry_phone = isset($entry['phone']) ? (string) $entry['phone'] : '';

            $ip_match = !empty($entry_ip) && !empty($ip) && $entry_ip === $ip;
            $phone_match = !empty($entry_phone) && !empty($phone) && $entry_phone === $phone;

            if (!empty($entry_ip) && !empty($entry_phone) && $ip_match && $phone_match) {
                return $entry;
            }
            if (!empty($entry_ip) && empty($entry_phone) && $ip_match) {
                return $entry;
            }
            if (empty($entry_ip) && !empty($entry_phone) && $phone_match) {
                return $entry;
            }
        }

        return null;
    }
}
