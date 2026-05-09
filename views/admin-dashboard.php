<?php if (!defined('ABSPATH')) {
    exit;
} ?>

<div class="hkdev-app-wrapper">
    <!-- APP SIDEBAR -->
    <aside class="app-sidebar">
        <div class="sidebar-header">
            <div class="sidebar-logo-icon"><i class="ph-bold ph-lightning"></i></div>
            <div>
                <div class="sidebar-title">HKDEV</div>
                <div class="sidebar-subtitle">SMS SUITE <span class="version-badge">v<?php echo esc_html(HKDEV_PLUGIN_VERSION); ?></span></div>
            </div>
        </div>

        <div class="nav-section-title"><?php _e('Plugin Modules', HKDEV_TEXT_DOMAIN); ?></div>
        <ul class="sidebar-nav">
            <li class="active" id="nav-main">
                <button onclick="switchAppView('main')">
                    <i class="ph-fill ph-chat-circle-text"></i>
                    <div class="nav-item-meta">
                        <span><?php _e('SMS Suite', HKDEV_TEXT_DOMAIN); ?></span>
                        <span class="nav-item-desc"><?php _e('Settings & Logs', HKDEV_TEXT_DOMAIN); ?></span>
                    </div>
                </button>
            </li>
            <li id="nav-blocker">
                <button onclick="switchAppView('blocker')">
                    <i class="ph-fill ph-shield-check"></i>
                    <div class="nav-item-meta">
                        <span><?php _e('Order Blocker', HKDEV_TEXT_DOMAIN); ?></span>
                        <span class="nav-item-desc"><?php _e('Spam Protection', HKDEV_TEXT_DOMAIN); ?></span>
                    </div>
                </button>
            </li>
        </ul>

        <div class="sidebar-footer">
            <div class="nav-section-title" style="margin: 0 0 12px 0;"><?php _e('Quick Info', HKDEV_TEXT_DOMAIN); ?></div>
            <div class="quick-info">
                <div class="info-row">
                    <span><i class="ph ph-wallet"></i> <?php _e('Balance', HKDEV_TEXT_DOMAIN); ?></span>
                    <span class="info-val"><?php echo esc_html($balance_cache['amount'] ?? 'Not Checked'); ?></span>
                </div>
                <div class="info-row">
                    <span><i class="ph ph-check-circle"></i> <?php _e('Total Logs', HKDEV_TEXT_DOMAIN); ?></span>
                    <span class="info-val"><?php echo esc_html(count($logs)); ?></span>
                </div>
            </div>
            <div class="system-status">
                <div class="status-dot"></div>
                <div>
                    <div style="color: var(--success); font-weight: 600; margin-bottom: 2px;"><?php _e('System Operational', HKDEV_TEXT_DOMAIN); ?></div>
                    <div style="font-size: 10px;">WooCommerce &middot; WordPress Admin</div>
                </div>
            </div>
        </div>
    </aside>

    <!-- MAIN APP CONTENT -->
    <div class="app-content-area">

        <!-- VIEW 1: MAIN SETTINGS -->
        <main id="app-view-main" class="app-content view-container active">
            <div class="page-header">
                <div class="page-title-group">
                    <h1><i class="ph-fill ph-chat-circle-text"></i> HKDEV SMS Suite <span class="badge badge-success"><?php _e('Active', HKDEV_TEXT_DOMAIN); ?></span></h1>
                    <p class="page-desc"><?php _e('Professional SMS, OTP & order protection toolkit for WooCommerce.', HKDEV_TEXT_DOMAIN); ?></p>
                </div>
                <button class="btn btn-outline" id="btn-refresh-balance"><i class="ph ph-arrows-clockwise"></i> <?php _e('Refresh Balance', HKDEV_TEXT_DOMAIN); ?></button>
            </div>

            <div class="pill-tabs">
                <button class="pill-tab active" onclick="switchTab(event, 'main', 'features')"><?php _e('General Settings', HKDEV_TEXT_DOMAIN); ?></button>
                <button class="pill-tab" onclick="switchTab(event, 'main', 'settings')"><?php _e('API Credentials', HKDEV_TEXT_DOMAIN); ?></button>
                <button class="pill-tab" onclick="switchTab(event, 'main', 'templates')"><?php _e('SMS Templates', HKDEV_TEXT_DOMAIN); ?></button>
                <button class="pill-tab" onclick="switchTab(event, 'main', 'logs')"><?php _e('SMS Logs', HKDEV_TEXT_DOMAIN); ?></button>
            </div>

            <form method="post" action="options.php" id="hkdev-settings-form">
                <?php settings_fields('hkdev_settings_group'); ?>

                <!-- TAB: GENERAL SETTINGS -->
                <div id="main-tab-features" class="tab-content active">
                    <div class="card">
                        <h3><?php _e('OTP & Gateway Options', HKDEV_TEXT_DOMAIN); ?></h3>
                        <p class="card-desc"><?php _e('Configure core SMS gateway and verification behavior.', HKDEV_TEXT_DOMAIN); ?></p>

                        <div class="settings-list">
                            <div class="settings-row">
                                <div class="settings-meta">
                                    <strong><?php _e('Enable WooCommerce OTP', HKDEV_TEXT_DOMAIN); ?></strong>
                                    <p><?php _e('Require customers to verify their phone number at checkout.', HKDEV_TEXT_DOMAIN); ?></p>
                                </div>
                                <label class="switch switch-dark">
                                    <input type="checkbox" name="hkdev_enable_otp" value="on" <?php checked(get_option('hkdev_enable_otp', 'on'), 'on'); ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-row">
                                <div class="settings-meta">
                                    <strong><?php _e('Enable SMS Gateway', HKDEV_TEXT_DOMAIN); ?></strong>
                                    <p><?php _e('Master toggle — turn off to pause all outgoing messages instantly.', HKDEV_TEXT_DOMAIN); ?></p>
                                </div>
                                <label class="switch switch-dark">
                                    <input type="checkbox" name="hkdev_enable_gateway" value="on" <?php checked(get_option('hkdev_enable_gateway', 'on'), 'on'); ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-row">
                                <div class="settings-meta">
                                    <strong><?php _e('Enable Order Confirmation SMS', HKDEV_TEXT_DOMAIN); ?></strong>
                                    <p><?php _e('Send SMS to customers after successful order placement.', HKDEV_TEXT_DOMAIN); ?></p>
                                </div>
                                <label class="switch switch-dark">
                                    <input type="checkbox" name="hkdev_enable_order_confirmation_sms" value="on" <?php checked(get_option('hkdev_enable_order_confirmation_sms', 'on'), 'on'); ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-row">
                                <div class="settings-meta">
                                    <strong><?php _e('Enable Status Update SMS', HKDEV_TEXT_DOMAIN); ?></strong>
                                    <p><?php _e('Notify customers when their order status changes.', HKDEV_TEXT_DOMAIN); ?></p>
                                </div>
                                <label class="switch switch-dark">
                                    <input type="checkbox" name="hkdev_enable_status_sms" value="on" <?php checked(get_option('hkdev_enable_status_sms', 'on'), 'on'); ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-row">
                                <div class="settings-meta">
                                    <strong><?php _e('Enable Activity Logging', HKDEV_TEXT_DOMAIN); ?></strong>
                                    <p><?php _e('Keep detailed logs of all SMS transactions for audit purposes.', HKDEV_TEXT_DOMAIN); ?></p>
                                </div>
                                <label class="switch switch-dark">
                                    <input type="checkbox" name="hkdev_enable_logs" value="on" <?php checked(get_option('hkdev_enable_logs', 'on'), 'on'); ?>>
                                    <span class="slider"></span>
                                </label>
                            </div>
                            <div class="settings-row">
                                <div class="settings-meta">
                                    <strong><?php _e('OTP Length', HKDEV_TEXT_DOMAIN); ?></strong>
                                    <p><?php _e('Number of digits for the one-time password.', HKDEV_TEXT_DOMAIN); ?></p>
                                </div>
                                <select name="hkdev_otp_length" class="form-control" style="width: 140px;">
                                    <?php $len = get_option('hkdev_otp_length', 6); ?>
                                    <option value="4" <?php selected($len, 4); ?>>4 <?php _e('Digits', HKDEV_TEXT_DOMAIN); ?></option>
                                    <option value="5" <?php selected($len, 5); ?>>5 <?php _e('Digits', HKDEV_TEXT_DOMAIN); ?></option>
                                    <option value="6" <?php selected($len, 6); ?>>6 <?php _e('Digits', HKDEV_TEXT_DOMAIN); ?></option>
                                    <option value="8" <?php selected($len, 8); ?>>8 <?php _e('Digits', HKDEV_TEXT_DOMAIN); ?></option>
                                </select>
                            </div>
                            <div class="settings-row">
                                <div class="settings-meta">
                                    <strong><?php _e('OTP Expiry Time', HKDEV_TEXT_DOMAIN); ?></strong>
                                    <p><?php _e('Minutes until OTP expires (default: 10 minutes).', HKDEV_TEXT_DOMAIN); ?></p>
                                </div>
                                <input type="number" name="hkdev_otp_expiry_minutes" class="form-control" value="<?php echo esc_attr(get_option('hkdev_otp_expiry_minutes', 10)); ?>" style="width: 140px;">
                            </div>
                            <div class="settings-row">
                                <div class="settings-meta">
                                    <strong><?php _e('OTP Cooldown', HKDEV_TEXT_DOMAIN); ?></strong>
                                    <p><?php _e('Seconds to wait before requesting a new OTP.', HKDEV_TEXT_DOMAIN); ?></p>
                                </div>
                                <input type="number" name="hkdev_otp_cooldown_seconds" class="form-control" value="<?php echo esc_attr(get_option('hkdev_otp_cooldown_seconds', 60)); ?>" style="width: 140px;">
                            </div>
                        </div>
                        <div style="margin-top: 32px;">
                            <?php submit_button(__('Save Changes', HKDEV_TEXT_DOMAIN), 'primary btn', 'submit', false); ?>
                        </div>
                    </div>
                </div>

                <!-- TAB: API CREDENTIALS -->
                <div id="main-tab-settings" class="tab-content">
                    <div class="card">
                        <h3><?php _e('SMS Gateway Configuration', HKDEV_TEXT_DOMAIN); ?></h3>
                        <p class="card-desc"><?php _e('Enter your SMS gateway API credentials and endpoint details.', HKDEV_TEXT_DOMAIN); ?></p>

                        <div class="form-group">
                            <label><?php _e('Gateway API URL', HKDEV_TEXT_DOMAIN); ?></label>
                            <input type="url" name="sib_gateway_url" class="form-control" value="<?php echo esc_attr(get_option('sib_gateway_url', '')); ?>" placeholder="https://api.smsprovider.com/send">
                        </div>

                        <div class="form-group">
                            <label><?php _e('API Token / Key', HKDEV_TEXT_DOMAIN); ?></label>
                            <input type="password" name="sib_api_token" class="form-control" value="<?php echo esc_attr(get_option('sib_api_token', '')); ?>" placeholder="Your API token">
                        </div>

                        <div class="form-group">
                            <label><?php _e('Sender ID / Name', HKDEV_TEXT_DOMAIN); ?></label>
                            <input type="text" name="sib_sender_id" class="form-control" value="<?php echo esc_attr(get_option('sib_sender_id', '')); ?>" placeholder="Your business name">
                        </div>

                        <div class="form-group">
                            <label><?php _e('HTTP Method', HKDEV_TEXT_DOMAIN); ?></label>
                            <select name="sib_http_method" class="form-control">
                                <option value="GET" <?php selected(get_option('sib_http_method', 'GET'), 'GET'); ?>>GET</option>
                                <option value="POST" <?php selected(get_option('sib_http_method', 'GET'), 'POST'); ?>>POST</option>
                            </select>
                        </div>

                        <h4><?php _e('Request Parameters', HKDEV_TEXT_DOMAIN); ?></h4>
                        <div class="form-group">
                            <label><?php _e('Token Parameter Name', HKDEV_TEXT_DOMAIN); ?></label>
                            <input type="text" name="sib_param_token" class="form-control" value="<?php echo esc_attr(get_option('sib_param_token', 'token')); ?>" placeholder="token">
                        </div>

                        <div class="form-group">
                            <label><?php _e('Sender Parameter Name', HKDEV_TEXT_DOMAIN); ?></label>
                            <input type="text" name="sib_param_sender" class="form-control" value="<?php echo esc_attr(get_option('sib_param_sender', 'sender')); ?>" placeholder="sender">
                        </div>

                        <div class="form-group">
                            <label><?php _e('Phone Parameter Name', HKDEV_TEXT_DOMAIN); ?></label>
                            <input type="text" name="sib_param_number" class="form-control" value="<?php echo esc_attr(get_option('sib_param_number', 'number')); ?>" placeholder="number">
                        </div>

                        <div class="form-group">
                            <label><?php _e('Message Parameter Name', HKDEV_TEXT_DOMAIN); ?></label>
                            <input type="text" name="sib_param_msg" class="form-control" value="<?php echo esc_attr(get_option('sib_param_msg', 'message')); ?>" placeholder="message">
                        </div>

                        <h4><?php _e('Balance Check API', HKDEV_TEXT_DOMAIN); ?></h4>
                        <div class="form-group">
                            <label><?php _e('Balance API URL', HKDEV_TEXT_DOMAIN); ?></label>
                            <input type="url" name="hkdev_balance_api_url" class="form-control" value="<?php echo esc_attr(get_option('hkdev_balance_api_url', '')); ?>" placeholder="https://api.smsprovider.com/balance">
                        </div>

                        <div class="form-group">
                            <label><?php _e('Balance Response Key', HKDEV_TEXT_DOMAIN); ?></label>
                            <input type="text" name="hkdev_balance_response_key" class="form-control" value="<?php echo esc_attr(get_option('hkdev_balance_response_key', 'balance')); ?>" placeholder="balance">
                        </div>

                        <div style="margin-top: 24px; display: flex; gap: 12px;">
                            <?php submit_button(__('Save Credentials', HKDEV_TEXT_DOMAIN), 'primary btn', 'submit', false); ?>
                            <button type="button" class="btn btn-outline" id="btn-test-sms" onclick="testSMS()"><?php _e('Test SMS', HKDEV_TEXT_DOMAIN); ?></button>
                        </div>
                    </div>
                </div>

                <!-- TAB: SMS TEMPLATES -->
                <div id="main-tab-templates" class="tab-content">
                    <div class="card">
                        <h3><?php _e('Customizable SMS Templates', HKDEV_TEXT_DOMAIN); ?></h3>
                        <p class="card-desc"><?php _e('Create SMS message templates with dynamic placeholders.', HKDEV_TEXT_DOMAIN); ?></p>

                        <div class="form-group">
                            <label><?php _e('OTP Message Template', HKDEV_TEXT_DOMAIN); ?></label>
                            <textarea name="sib_otp_template" class="form-control" rows="4" placeholder="Your OTP is: {OTP}"><?php echo esc_textarea(get_option('sib_otp_template', 'Your OTP is: {OTP}')); ?></textarea>
                            <small><?php _e('Use {OTP} for the generated code', HKDEV_TEXT_DOMAIN); ?></small>
                        </div>

                        <div class="form-group">
                            <label><?php _e('Order Confirmation Template', HKDEV_TEXT_DOMAIN); ?></label>
                            <textarea name="sib_order_template" class="form-control" rows="4" placeholder="Thank you! Order ID: {ORDER_ID}"><?php echo esc_textarea(get_option('sib_order_template', 'Thank you for your order! Order ID: {ORDER_ID}')); ?></textarea>
                            <small><?php _e('Use {ORDER_ID}, {CUSTOMER_NAME}, {ORDER_TOTAL}', HKDEV_TEXT_DOMAIN); ?></small>
                        </div>

                        <div class="form-group">
                            <label><?php _e('Status Update Template', HKDEV_TEXT_DOMAIN); ?></label>
                            <textarea name="sib_status_template" class="form-control" rows="4" placeholder="Your order status: {STATUS}"><?php echo esc_textarea(get_option('sib_status_template', 'Your order status has been updated to: {STATUS}')); ?></textarea>
                            <small><?php _e('Use {STATUS} for order status', HKDEV_TEXT_DOMAIN); ?></small>
                        </div>

                        <div class="form-group">
                            <label><?php _e('Target Products (Optional)', HKDEV_TEXT_DOMAIN); ?></label>
                            <input type="text" name="sib_target_products" class="form-control" value="<?php echo esc_attr(get_option('sib_target_products', '')); ?>" placeholder="123,456,789 (Leave empty for all products)">
                            <small><?php _e('Comma-separated product IDs. Leave empty to apply OTP for all products.', HKDEV_TEXT_DOMAIN); ?></small>
                        </div>

                        <div style="margin-top: 24px;">
                            <?php submit_button(__('Save Templates', HKDEV_TEXT_DOMAIN), 'primary btn', 'submit', false); ?>
                        </div>
                    </div>
                </div>

                <!-- TAB: SMS LOGS -->
                <div id="main-tab-logs" class="tab-content">
                    <div class="card">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                            <div>
                                <h3><?php _e('SMS Activity Log', HKDEV_TEXT_DOMAIN); ?></h3>
                                <p class="card-desc"><?php _e('All sent and failed SMS messages are logged here.', HKDEV_TEXT_DOMAIN); ?></p>
                            </div>
                            <button type="button" class="btn btn-outline" id="btn-clear-logs" onclick="clearLogs()"><?php _e('Clear Logs', HKDEV_TEXT_DOMAIN); ?></button>
                        </div>

                        <div style="overflow-x: auto;">
                            <table class="logs-table">
                                <thead>
                                    <tr>
                                        <th><?php _e('Status', HKDEV_TEXT_DOMAIN); ?></th>
                                        <th><?php _e('Message', HKDEV_TEXT_DOMAIN); ?></th>
                                        <th><?php _e('Timestamp', HKDEV_TEXT_DOMAIN); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($logs)) : ?>
                                        <?php foreach (array_slice($logs, 0, 50) as $log) : ?>
                                            <tr>
                                                <td>
                                                    <span class="status-badge status-<?php echo esc_attr($log['status'] ?? 'unknown'); ?>">
                                                        <?php echo esc_html(ucfirst($log['status'] ?? 'unknown')); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo esc_html($log['message'] ?? ''); ?></td>
                                                <td><?php echo esc_html($log['timestamp'] ?? ''); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else : ?>
                                        <tr>
                                            <td colspan="3" style="text-align: center; padding: 20px; color: var(--text-muted);">
                                                <?php _e('No logs found yet', HKDEV_TEXT_DOMAIN); ?>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </form>
        </main>

        <!-- VIEW 2: ORDER BLOCKER -->
        <main id="app-view-blocker" class="app-content view-container">
            <div class="page-header">
                <div class="page-title-group">
                    <h1>
                        <div class="icon-box" style="background:#ea580c; color:white; width:36px; height:36px; border-radius:10px;">
                            <i class="ph-fill ph-shield-warning"></i>
                        </div>
                        <?php _e('Order Delay Blocker', HKDEV_TEXT_DOMAIN); ?>
                        <span class="badge badge-warning"><?php _e('Anti-Spam', HKDEV_TEXT_DOMAIN); ?></span>
                    </h1>
                    <p class="page-desc"><?php _e('Prevent duplicate orders and spam by temporarily blocking IPs and phone numbers.', HKDEV_TEXT_DOMAIN); ?></p>
                </div>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('hkdev_settings_group'); ?>
                <div class="card">
                    <h3><?php _e('Block Duration Settings', HKDEV_TEXT_DOMAIN); ?></h3>
                    <p class="card-desc"><?php _e('Configure how long an IP or phone should be blocked after an order.', HKDEV_TEXT_DOMAIN); ?></p>

                    <div class="duration-grid">
                        <div class="duration-box">
                            <label><?php _e('Days', HKDEV_TEXT_DOMAIN); ?></label>
                            <input type="number" name="usp_wcodb_block_duration_days" value="<?php echo esc_attr(get_option('usp_wcodb_block_duration_days', 0)); ?>" min="0">
                        </div>
                        <div class="duration-box">
                            <label><?php _e('Hours', HKDEV_TEXT_DOMAIN); ?></label>
                            <input type="number" name="usp_wcodb_block_duration_hours" value="<?php echo esc_attr(get_option('usp_wcodb_block_duration_hours', 0)); ?>" min="0">
                        </div>
                        <div class="duration-box">
                            <label><?php _e('Minutes', HKDEV_TEXT_DOMAIN); ?></label>
                            <input type="number" name="usp_wcodb_block_duration_minutes" value="<?php echo esc_attr(get_option('usp_wcodb_block_duration_minutes', 60)); ?>" min="1">
                        </div>
                    </div>

                    <div class="form-group" style="margin-top: 24px;">
                        <label class="checkbox-label">
                            <input type="checkbox" name="usp_wcodb_combined_block_enabled" value="on" <?php checked(get_option('usp_wcodb_combined_block_enabled', 'off'), 'on'); ?>>
                            <span><?php _e('Enable Combined IP + Phone Blocking', HKDEV_TEXT_DOMAIN); ?></span>
                        </label>
                        <p style="color: var(--text-muted); font-size: 13px; margin-top: 8px;">
                            <?php _e('Block both IP address and phone number for the same duration when one triggers a block.', HKDEV_TEXT_DOMAIN); ?>
                        </p>
                    </div>

                    <div style="margin-top: 32px;">
                        <?php submit_button(__('Save Blocker Settings', HKDEV_TEXT_DOMAIN), 'primary btn btn-warning', 'submit', false); ?>
                    </div>
                </div>
            </form>
        </main>

    </div>
</div>

<style>
    .checkbox-label {
        display: flex;
        align-items: center;
        gap: 8px;
        cursor: pointer;
    }

    .checkbox-label input {
        cursor: pointer;
    }

    .form-group small {
        display: block;
        margin-top: 6px;
        color: var(--text-muted);
        font-size: 12px;
    }

    .logs-table {
        width: 100%;
        border-collapse: collapse;
    }

    .logs-table thead {
        background: #f8fafc;
    }

    .logs-table th {
        padding: 12px;
        text-align: left;
        font-weight: 600;
        border-bottom: 1px solid var(--border-light);
        color: var(--text-main);
    }

    .logs-table td {
        padding: 12px;
        border-bottom: 1px solid var(--border-light);
        color: var(--text-main);
    }

    .status-badge {
        display: inline-block;
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
    }

    .status-success {
        background: var(--success-light);
        color: #065f46;
    }

    .status-error {
        background: var(--danger-light);
        color: #7f1d1d;
    }

    .status-unknown {
        background: #f3f4f6;
        color: #4b5563;
    }

    .duration-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }

    .duration-box {
        display: flex;
        flex-direction: column;
    }

    .duration-box label {
        display: block;
        font-weight: 600;
        color: var(--text-main);
        margin-bottom: 8px;
        font-size: 13px;
    }

    .duration-box input {
        padding: 10px 12px;
        border: 1px solid var(--border-light);
        border-radius: 8px;
        font-size: 14px;
    }
</style>
