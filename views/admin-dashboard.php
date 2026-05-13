<?php
if (!defined('ABSPATH')) exit;

// Load additional data
$active_blocks = class_exists('HKDEV_WC_Order_Delay_Blocker')
    ? HKDEV_WC_Order_Delay_Blocker::get_active_blocks_static()
    : array();
$block_logs  = get_option('hkdev_block_logs', array());
$fd_settings = get_option('hkdev_fd_settings', array());
$bal_amount  = $balance_cache['amount'] ?? 'N/A';
$bal_display = is_numeric($bal_amount) ? '৳' . $bal_amount : $bal_amount;

// Preload existing FD product/category names
$fd_products    = array();
$fd_categories  = array();
if (!empty($fd_settings['products'])) {
    foreach ((array) $fd_settings['products'] as $pid) {
        $p = wc_get_product(absint($pid));
        if ($p) $fd_products[] = array('id' => absint($pid), 'name' => $p->get_name());
    }
}
if (!empty($fd_settings['categories'])) {
    foreach ((array) $fd_settings['categories'] as $cid) {
        $t = get_term(absint($cid), 'product_cat');
        if ($t && !is_wp_error($t)) $fd_categories[] = array('id' => absint($cid), 'name' => $t->name);
    }
}

$blocked_total = count($active_blocks);
$log_blocked   = count(array_filter($block_logs, fn($l) => ($l['event'] ?? '') === 'blocked'));
$log_unblocked = count(array_filter($block_logs, fn($l) => ($l['event'] ?? '') === 'unblocked'));
?>
<div class="hkdev-suite-wrap">

<!-- ====== NAVBAR ====== -->
<header class="hkdev-nb">
  <div class="hkdev-nb-inner">

    <div class="hkdev-nb-logo">
      <div class="hkdev-nb-logo-icon">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
      </div>
      <div>
        <span class="hkdev-nb-brand">HKDEV</span>
        <span class="hkdev-nb-suite">SUITE</span>
        <span class="hkdev-nb-ver">v3.0</span>
      </div>
    </div>

    <nav class="hkdev-nb-nav">
      <button class="hkdev-nb-btn hkdev-nb-btn--indigo active" data-view="sms" onclick="hkSwitchView('sms')">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>
        SMS Suite
      </button>
      <button class="hkdev-nb-btn hkdev-nb-btn--orange" data-view="blocker" onclick="hkSwitchView('blocker')">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        Order Blocker
      </button>
      <button class="hkdev-nb-btn hkdev-nb-btn--emerald" data-view="delivery" onclick="hkSwitchView('delivery')">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><path d="M16 8h4l3 3v5h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
        Free Delivery
      </button>
    </nav>

    <div class="hkdev-nb-right">
      <div class="hkdev-chip">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#34d399" stroke-width="2"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 7V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v2"/></svg>
        <span style="color:#94a3b8">Balance:</span>
        <span id="hkdev-balance-display" style="color:#34d399;font-weight:600"><?php echo esc_html($bal_display); ?></span>
      </div>
      <div class="hkdev-chip" style="border-color:rgba(99,102,241,.25);background:rgba(99,102,241,.08)">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#818cf8" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        <span style="color:#94a3b8">Logs:</span>
      <span id="hkdev-log-count" style="color:#818cf8;font-weight:600"><?php echo count($logs); ?></span>
      </div>
      <div class="hkdev-nb-divider"></div>
      <div class="hkdev-chip" style="background:rgba(52,211,153,.08);border-color:rgba(52,211,153,.2)">
        <span class="hkdev-dot"></span>
        <span style="color:#34d399;font-weight:500">Operational</span>
      </div>
      <button class="hkdev-btn-otp" id="hkdev-preview-otp">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
        Preview OTP
      </button>
    </div>

  </div>
</header>

<!-- ====== CONTENT ====== -->
<div class="hkdev-content">

  <!-- ==================== SMS SUITE VIEW ==================== -->
  <div id="hkdev-view-sms" class="hkdev-view active">
    <div class="hkdev-tabs">
      <button class="hkdev-tab-btn active" data-tab="general" onclick="hkSwitchTab('sms','general')">General Settings</button>
      <button class="hkdev-tab-btn" data-tab="api" onclick="hkSwitchTab('sms','api')">API Credentials</button>
      <button class="hkdev-tab-btn" data-tab="templates" onclick="hkSwitchTab('sms','templates')">SMS Templates</button>
      <button class="hkdev-tab-btn" data-tab="logs" onclick="hkSwitchTab('sms','logs')">SMS Logs <span id="hkdev-sms-log-badge" class="hkdev-tab-badge"><?php echo count($logs); ?></span></button>
    </div>

    <!-- General Settings Tab -->
    <div id="sms-tab-general" class="hkdev-tab-content active">
      <form method="post" action="options.php">
        <?php settings_fields('hkdev_settings_group'); ?>
        <div class="hkdev-grid-2">
          <div class="hkdev-card">
            <div class="hkdev-card-header">
              <h3>Feature Toggles</h3>
              <p>Enable or disable each module</p>
            </div>
            <?php
            $toggles = array(
              array('name'=>'hkdev_enable_gateway','label'=>'SMS Gateway','desc'=>'Send SMS via your configured gateway'),
              array('name'=>'hkdev_enable_otp','label'=>'OTP Verification','desc'=>'Require phone OTP before checkout'),
              array('name'=>'hkdev_enable_order_confirmation_sms','label'=>'Order Confirmation SMS','desc'=>'Send SMS when order is placed'),
              array('name'=>'hkdev_enable_status_sms','label'=>'Status Update SMS','desc'=>'Notify customer on order status change'),
              array('name'=>'hkdev_enable_logs','label'=>'SMS Logging','desc'=>'Keep a log of all sent messages'),
              array('name'=>'hkdev_enable_order_blocker','label'=>'Order Blocker','desc'=>'Block repeated orders from same phone/IP'),
            );
            foreach ($toggles as $t): $checked = hkdev_option_is_enabled($t['name'], 'yes'); ?>
            <div class="hkdev-toggle-row">
              <div class="hkdev-toggle-info">
                <strong><?php echo esc_html($t['label']); ?></strong>
                <span><?php echo esc_html($t['desc']); ?></span>
              </div>
              <label class="hkdev-toggle">
                <input type="checkbox" name="<?php echo esc_attr($t['name']); ?>" value="yes" <?php checked($checked, true); ?>>
                <span class="slider"></span>
              </label>
            </div>
            <?php endforeach; ?>
          </div>
          <div class="hkdev-card">
            <div class="hkdev-card-header">
              <h3>OTP Configuration</h3>
              <p>Adjust OTP behaviour</p>
            </div>
            <div class="hkdev-field">
              <label>OTP Length (digits)</label>
              <input type="number" name="hkdev_otp_length" value="<?php echo esc_attr(get_option('hkdev_otp_length',6)); ?>" min="4" max="8">
            </div>
            <div class="hkdev-field">
              <label>OTP Expiry (minutes)</label>
              <input type="number" name="hkdev_otp_expiry_minutes" value="<?php echo esc_attr(get_option('hkdev_otp_expiry_minutes',10)); ?>" min="1">
            </div>
            <div class="hkdev-field">
              <label>Resend Cooldown (seconds)</label>
              <input type="number" name="hkdev_otp_cooldown_seconds" value="<?php echo esc_attr(get_option('hkdev_otp_cooldown_seconds',60)); ?>" min="10">
            </div>
          </div>
        </div>
        <div class="submit-btn-row">
          <?php submit_button('Save Settings', 'primary', 'submit', false, array('class'=>'hkdev-btn hkdev-btn--primary')); ?>
        </div>
      </form>
    </div>

    <!-- API Credentials Tab -->
    <div id="sms-tab-api" class="hkdev-tab-content">
      <form method="post" action="options.php">
        <?php settings_fields('hkdev_settings_group'); ?>
        <div class="hkdev-grid-2">
          <div class="hkdev-card">
            <div class="hkdev-card-header"><h3>Gateway Settings</h3><p>Configure your SMS provider</p></div>
            <div class="hkdev-field">
              <label>Gateway URL</label>
              <input type="url" name="sib_gateway_url" value="<?php echo esc_attr(get_option('sib_gateway_url','')); ?>" placeholder="https://sms.example.com/api/send">
            </div>
            <div class="hkdev-field">
              <label>API Token</label>
              <input type="text" name="sib_api_token" value="<?php echo esc_attr(get_option('sib_api_token','')); ?>" placeholder="Your API token">
            </div>
            <div class="hkdev-field">
              <label>Sender ID</label>
              <input type="text" name="sib_sender_id" value="<?php echo esc_attr(get_option('sib_sender_id','')); ?>" placeholder="e.g. HKDEV">
            </div>
            <div class="hkdev-field">
              <label>HTTP Method</label>
              <select name="sib_http_method">
                <option value="GET"  <?php selected(get_option('sib_http_method','GET'),'GET'); ?>>GET</option>
                <option value="POST" <?php selected(get_option('sib_http_method','GET'),'POST'); ?>>POST</option>
              </select>
            </div>
          </div>
          <div class="hkdev-card">
            <div class="hkdev-card-header"><h3>Parameter Names</h3><p>Map your gateway's parameter keys</p></div>
            <?php
            $params = array(
              array('name'=>'sib_param_token', 'label'=>'Token Parameter', 'default'=>'token'),
              array('name'=>'sib_param_sender','label'=>'Sender Parameter','default'=>'sender'),
              array('name'=>'sib_param_number','label'=>'Number Parameter','default'=>'number'),
              array('name'=>'sib_param_msg',   'label'=>'Message Parameter','default'=>'message'),
            );
            foreach ($params as $p): ?>
            <div class="hkdev-field">
              <label><?php echo esc_html($p['label']); ?></label>
              <input type="text" name="<?php echo esc_attr($p['name']); ?>" value="<?php echo esc_attr(get_option($p['name'],$p['default'])); ?>">
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="hkdev-card">
          <div class="hkdev-card-header"><h3>Balance API</h3><p>Check your SMS credit balance</p></div>
          <div class="hkdev-grid-2">
            <div class="hkdev-field">
              <label>Balance API URL</label>
              <input type="url" name="hkdev_balance_api_url" value="<?php echo esc_attr(get_option('hkdev_balance_api_url','')); ?>" placeholder="https://sms.example.com/api/balance">
            </div>
            <div class="hkdev-field">
              <label>Balance Response Key</label>
              <input type="text" name="hkdev_balance_response_key" value="<?php echo esc_attr(get_option('hkdev_balance_response_key','balance')); ?>" placeholder="e.g. balance">
            </div>
          </div>
          <div class="hkdev-balance-check-row">
            <div>
              <span class="hkdev-balance-label">Current Balance:</span>
              <span id="hkdev-balance-value" class="hkdev-balance-value"><?php echo esc_html($bal_display); ?></span>
              <span id="hkdev-balance-time" class="hkdev-balance-time">
                <?php echo !empty($balance_cache['checked_at']) ? ' — checked ' . esc_html($balance_cache['checked_at']) : ''; ?>
              </span>
            </div>
            <button type="button" id="hkdev-check-balance" class="hkdev-btn hkdev-btn--ghost">Check Balance</button>
          </div>
        </div>
        <div class="hkdev-card">
          <div class="hkdev-card-header"><h3>Test SMS</h3><p>Send a test message to verify your setup</p></div>
          <div class="hkdev-grid-2">
            <div class="hkdev-field">
              <label>Phone Number</label>
              <input type="text" id="hkdev-test-phone" placeholder="01XXXXXXXXX">
            </div>
            <div class="hkdev-field">
              <label>Message</label>
              <input type="text" id="hkdev-test-message" value="Test SMS from HKDEV SMS Suite v3.0" placeholder="Your test message">
            </div>
          </div>
          <button type="button" id="hkdev-test-sms-btn" class="hkdev-btn hkdev-btn--primary">Send Test SMS</button>
        </div>
        <div class="submit-btn-row">
          <?php submit_button('Save Settings', 'primary', 'submit', false, array('class'=>'hkdev-btn hkdev-btn--primary')); ?>
        </div>
      </form>
    </div>

    <!-- SMS Templates Tab -->
    <div id="sms-tab-templates" class="hkdev-tab-content">
      <form method="post" action="options.php">
        <?php settings_fields('hkdev_settings_group'); ?>
        <div class="hkdev-card">
          <div class="hkdev-card-header">
            <h3>Target Products for OTP</h3>
            <p>Comma-separated product IDs. Leave empty to require OTP for all products.</p>
          </div>
          <div class="hkdev-field">
            <label>Product IDs</label>
            <input type="text" name="sib_target_products" value="<?php echo esc_attr(get_option('sib_target_products','')); ?>" placeholder="e.g. 12, 45, 89">
          </div>
        </div>
        <div class="hkdev-grid-2">
          <div class="hkdev-card">
            <div class="hkdev-card-header"><h3>OTP Template</h3><p>Variables: <code>{OTP}</code></p></div>
            <div class="hkdev-field">
              <textarea name="sib_otp_template" rows="4"><?php echo esc_textarea(get_option('sib_otp_template','Your OTP is: {OTP}. Valid for 10 minutes.')); ?></textarea>
            </div>
          </div>
          <div class="hkdev-card">
            <div class="hkdev-card-header"><h3>Order Confirmation Template</h3><p>Variables: <code>{ORDER_ID}</code> <code>{CUSTOMER_NAME}</code> <code>{ORDER_TOTAL}</code></p></div>
            <div class="hkdev-field">
              <textarea name="sib_order_template" rows="4"><?php echo esc_textarea(get_option('sib_order_template','Thank you {CUSTOMER_NAME}! Order #{ORDER_ID} confirmed. Total: {ORDER_TOTAL}')); ?></textarea>
            </div>
          </div>
          <div class="hkdev-card">
            <div class="hkdev-card-header"><h3>Status Update Template</h3><p>Variables: <code>{STATUS}</code></p></div>
            <div class="hkdev-field">
              <textarea name="sib_status_template" rows="4"><?php echo esc_textarea(get_option('sib_status_template','Your order status has been updated to: {STATUS}')); ?></textarea>
            </div>
          </div>
        </div>
        <div class="submit-btn-row">
          <?php submit_button('Save Templates', 'primary', 'submit', false, array('class'=>'hkdev-btn hkdev-btn--primary')); ?>
        </div>
      </form>
    </div>

    <!-- SMS Logs Tab -->
    <div id="sms-tab-logs" class="hkdev-tab-content">
      <?php
      $total_sent = count(array_filter($logs, fn($l) => ($l['status'] ?? '') === 'success'));
      $total_fail = count(array_filter($logs, fn($l) => ($l['status'] ?? '') === 'error'));
      ?>
      <div class="hkdev-stats-row">
        <div class="hkdev-stat-card">
          <div class="hkdev-stat-label">Total Logs</div>
          <div id="hkdev-sms-log-total" class="hkdev-stat-value"><?php echo count($logs); ?></div>
        </div>
        <div class="hkdev-stat-card">
          <div class="hkdev-stat-label">Sent Successfully</div>
          <div id="hkdev-sms-log-sent" class="hkdev-stat-value" style="color:#10b981"><?php echo $total_sent; ?></div>
        </div>
        <div class="hkdev-stat-card">
          <div class="hkdev-stat-label">Failed</div>
          <div id="hkdev-sms-log-failed" class="hkdev-stat-value" style="color:#ef4444"><?php echo $total_fail; ?></div>
        </div>
      </div>
      <div class="hkdev-card">
        <div class="hkdev-card-header" style="display:flex;justify-content:space-between;align-items:center">
          <div><h3>Log History</h3><p>Last 1000 SMS events</p></div>
          <button type="button" id="hkdev-clear-sms-logs" class="hkdev-btn hkdev-btn--danger hkdev-btn--sm">Clear All Logs</button>
        </div>
        <div class="hkdev-table-wrap">
          <table class="hkdev-table" id="hkdev-sms-log-table">
            <thead><tr><th>#</th><th>Status</th><th>Message</th><th>Timestamp</th></tr></thead>
            <tbody>
            <?php if (empty($logs)): ?>
              <tr><td colspan="4" style="text-align:center;color:#94a3b8;padding:32px">No logs found</td></tr>
            <?php else: foreach (array_slice($logs, 0, 200) as $i => $log): ?>
              <tr>
                <td><?php echo $i+1; ?></td>
                <td><span class="hkdev-badge hkdev-badge--<?php echo ($log['status'] ?? '') === 'success' ? 'success' : 'danger'; ?>"><?php echo esc_html($log['status'] ?? 'unknown'); ?></span></td>
                <td><?php echo esc_html($log['message'] ?? ''); ?></td>
                <td><?php echo esc_html($log['timestamp'] ?? ''); ?></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div><!-- /sms view -->


  <!-- ==================== ORDER BLOCKER VIEW ==================== -->
  <div id="hkdev-view-blocker" class="hkdev-view">
    <div class="hkdev-tabs">
      <button class="hkdev-tab-btn active" data-tab="settings" onclick="hkSwitchTab('blocker','settings')">Settings</button>
      <button class="hkdev-tab-btn" data-tab="blocked" onclick="hkSwitchTab('blocker','blocked')">Blocked Users <span id="hkdev-blocked-count" class="hkdev-tab-badge"><?php echo $blocked_total; ?></span></button>
      <button class="hkdev-tab-btn" data-tab="bloglog" onclick="hkSwitchTab('blocker','bloglog')">Activity Log <span id="hkdev-block-log-badge" class="hkdev-tab-badge"><?php echo count($block_logs); ?></span></button>
    </div>

    <!-- Settings Tab -->
    <div id="blocker-tab-settings" class="hkdev-tab-content active">
      <form method="post" action="options.php">
        <?php settings_fields('hkdev_settings_group'); ?>
        <div class="hkdev-card">
          <div class="hkdev-card-header"><h3>Block Duration</h3><p>How long to block after a completed order</p></div>
          <div class="hkdev-grid-3">
            <div class="hkdev-field">
              <label>Days</label>
              <input type="number" name="usp_wcodb_block_duration_days" value="<?php echo esc_attr(get_option('usp_wcodb_block_duration_days',0)); ?>" min="0">
            </div>
            <div class="hkdev-field">
              <label>Hours</label>
              <input type="number" name="usp_wcodb_block_duration_hours" value="<?php echo esc_attr(get_option('usp_wcodb_block_duration_hours',0)); ?>" min="0" max="23">
            </div>
            <div class="hkdev-field">
              <label>Minutes</label>
              <input type="number" name="usp_wcodb_block_duration_minutes" value="<?php echo esc_attr(get_option('usp_wcodb_block_duration_minutes',60)); ?>" min="0" max="59">
            </div>
          </div>
        </div>
        <div class="hkdev-card">
          <div class="hkdev-card-header"><h3>Block Options</h3></div>
          <div class="hkdev-toggle-row">
            <div class="hkdev-toggle-info">
              <strong>Combined Block (Phone + IP)</strong>
              <span>Block both the phone number and IP address when an order is completed</span>
            </div>
            <label class="hkdev-toggle hkdev-toggle--orange">
              <input type="checkbox" name="usp_wcodb_combined_block_enabled" value="yes" <?php checked(hkdev_option_is_enabled('usp_wcodb_combined_block_enabled','off'), true); ?>>
              <span class="slider"></span>
            </label>
          </div>
        </div>
        <div class="submit-btn-row">
          <?php submit_button('Save Settings', 'primary', 'submit', false, array('class'=>'hkdev-btn hkdev-btn--primary')); ?>
        </div>
      </form>
    </div>

    <!-- Blocked Users Tab -->
    <div id="blocker-tab-blocked" class="hkdev-tab-content">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
        <div>
          <h3 style="margin:0;font-size:16px;color:#1e293b">Active Blocks</h3>
          <p style="margin:4px 0 0;font-size:13px;color:#64748b"><span id="hkdev-blocked-total"><?php echo $blocked_total; ?></span> user(s) currently blocked</p>
        </div>
        <?php if ($blocked_total > 0): ?>
        <button id="hkdev-clear-all-blocks" class="hkdev-btn hkdev-btn--danger hkdev-btn--sm">Unblock All</button>
        <?php endif; ?>
      </div>

      <div id="hkdev-blocked-list">
        <?php if (empty($active_blocks)): ?>
          <div style="text-align:center;padding:48px;color:#94a3b8;background:#fff;border-radius:12px;border:1px dashed #e2e8f0">
            <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="#cbd5e1" stroke-width="1.5" style="margin-bottom:12px"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            <p style="margin:0;font-size:14px">No users currently blocked</p>
          </div>
        <?php else: foreach ($active_blocks as $block):
          $initials = strtoupper(substr($block['name'] ?? 'U', 0, 1) . (strpos($block['name'] ?? '', ' ') !== false ? substr(strrchr($block['name'], ' '), 1, 1) : ''));
          $device   = $block['device'] ?? 'desktop';
        ?>
        <div class="hkdev-blocked-item" id="block-<?php echo esc_attr($block['id']); ?>">
          <div class="hkdev-blocked-header">
            <div class="hkdev-blocked-avatar"><?php echo esc_html($initials ?: 'U'); ?></div>
            <div>
              <div class="hkdev-blocked-name"><?php echo esc_html($block['name'] ?: 'Unknown'); ?></div>
              <div class="hkdev-blocked-phone"><?php echo esc_html($block['phone'] ?? ''); ?></div>
            </div>
            <div class="hkdev-blocked-badge">
              <span class="hkdev-device-badge hkdev-device-badge--<?php echo esc_attr($device); ?>"><?php echo esc_html(ucfirst($device)); ?></span>
              <span class="hkdev-badge hkdev-badge--danger" style="font-size:11px">Blocked</span>
              <svg class="hkdev-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
            </div>
          </div>
          <div class="hkdev-blocked-details">
            <div class="hkdev-details-grid">
              <div class="hkdev-detail-item"><label>IP Address</label><span><?php echo esc_html($block['ip'] ?? 'N/A'); ?></span></div>
              <div class="hkdev-detail-item"><label>Browser</label><span><?php echo esc_html($block['browser'] ?? 'Unknown'); ?></span></div>
              <div class="hkdev-detail-item"><label>OS</label><span><?php echo esc_html($block['os'] ?? 'Unknown'); ?></span></div>
              <div class="hkdev-detail-item"><label>Blocked At</label><span><?php echo esc_html($block['blocked_at'] ?? ''); ?></span></div>
              <div class="hkdev-detail-item"><label>Expires At</label><span><?php echo esc_html($block['expires_at'] ?? ''); ?></span></div>
              <div class="hkdev-detail-item"><label>Order ID</label><span>#<?php echo esc_html($block['order_id'] ?? 'N/A'); ?></span></div>
            </div>
            <div class="hkdev-detail-item" style="margin-top:10px"><label>User Agent</label><span style="font-size:11px;color:#64748b"><?php echo esc_html(substr($block['user_agent'] ?? '', 0, 120)); ?></span></div>
            <div class="hkdev-unblock-btn">
              <button class="hkdev-btn hkdev-btn--emerald hkdev-btn--sm hkdev-unblock-btn-action" data-id="<?php echo esc_attr($block['id']); ?>">
                Unblock This User
              </button>
            </div>
          </div>
        </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <!-- Activity Log Tab -->
    <div id="blocker-tab-bloglog" class="hkdev-tab-content">
      <div class="hkdev-stats-row">
        <div class="hkdev-stat-card">
          <div class="hkdev-stat-label">Total Events</div>
          <div id="hkdev-block-log-total" class="hkdev-stat-value"><?php echo count($block_logs); ?></div>
        </div>
        <div class="hkdev-stat-card">
          <div class="hkdev-stat-label">Blocks</div>
          <div id="hkdev-block-log-blocked" class="hkdev-stat-value" style="color:#ef4444"><?php echo $log_blocked; ?></div>
        </div>
        <div class="hkdev-stat-card">
          <div class="hkdev-stat-label">Unblocks</div>
          <div id="hkdev-block-log-unblocked" class="hkdev-stat-value" style="color:#10b981"><?php echo $log_unblocked; ?></div>
        </div>
      </div>
      <div class="hkdev-card">
        <div class="hkdev-card-header" style="display:flex;justify-content:space-between;align-items:center">
          <div><h3>Block Activity Log</h3><p>All block / unblock events</p></div>
          <button id="hkdev-clear-block-logs" class="hkdev-btn hkdev-btn--danger hkdev-btn--sm">Clear All Logs</button>
        </div>
        <div class="hkdev-table-wrap">
          <table class="hkdev-table" id="hkdev-block-log-table">
            <thead><tr><th>Name</th><th>Phone</th><th>IP</th><th>Device</th><th>Event</th><th>Reason</th><th>Time</th></tr></thead>
            <tbody>
            <?php if (empty($block_logs)): ?>
              <tr><td colspan="7" style="text-align:center;color:#94a3b8;padding:32px">No activity logs</td></tr>
            <?php else: foreach (array_slice($block_logs, 0, 200) as $log): ?>
              <tr>
                <td><?php echo esc_html($log['name'] ?? '—'); ?></td>
                <td><?php echo esc_html($log['phone'] ?? '—'); ?></td>
                <td><?php echo esc_html($log['ip'] ?? '—'); ?></td>
                <td><?php echo esc_html(ucfirst($log['device'] ?? '—')); ?></td>
                <td>
                  <?php $ev = $log['event'] ?? ''; ?>
                  <span class="hkdev-badge hkdev-badge--<?php echo $ev === 'blocked' ? 'danger' : ($ev === 'unblocked' ? 'success' : 'warning'); ?>">
                    <?php echo esc_html(ucfirst($ev)); ?>
                  </span>
                </td>
                <td><?php echo esc_html($log['reason'] ?? '—'); ?></td>
                <td style="white-space:nowrap"><?php echo esc_html($log['timestamp'] ?? '—'); ?></td>
              </tr>
            <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div><!-- /blocker view -->


  <!-- ==================== FREE DELIVERY VIEW ==================== -->
  <div id="hkdev-view-delivery" class="hkdev-view">
    <div class="hkdev-tabs">
      <button class="hkdev-tab-btn active" data-tab="config" onclick="hkSwitchTab('delivery','config')">Configuration</button>
      <button class="hkdev-tab-btn" data-tab="support" onclick="hkSwitchTab('delivery','support')">Support</button>
    </div>

    <!-- Configuration Tab -->
    <div id="delivery-tab-config" class="hkdev-tab-content active">
      <div class="hkdev-grid-2">
        <div class="hkdev-card">
          <div class="hkdev-card-header"><h3>Delivery Triggers</h3><p>Select which methods will unlock free delivery</p></div>
          <?php
          $fd_triggers = array(
            array('id'=>'hkdev-fd-enable-qty',     'name'=>'enable_qty',      'label'=>'Quantity Based',  'desc'=>'Unlock by total item count in cart'),
            array('id'=>'hkdev-fd-enable-products','name'=>'enable_products', 'label'=>'Product Based',   'desc'=>'Specific products or per-product setting'),
            array('id'=>'hkdev-fd-enable-cats',    'name'=>'enable_cats',     'label'=>'Category Based',  'desc'=>'Unlock if cart has items from specific categories'),
          );
          foreach ($fd_triggers as $tr): $on = !empty($fd_settings[$tr['name']]); ?>
          <div class="hkdev-toggle-row">
            <div class="hkdev-toggle-info">
              <strong><?php echo esc_html($tr['label']); ?></strong>
              <span><?php echo esc_html($tr['desc']); ?></span>
            </div>
            <label class="hkdev-toggle hkdev-toggle--emerald">
              <input type="checkbox" id="<?php echo esc_attr($tr['id']); ?>" <?php checked($on, true); ?>>
              <span class="slider"></span>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="hkdev-card">
          <div class="hkdev-card-header"><h3>Configuration</h3><p>Fine-tune delivery settings</p></div>
          <div class="hkdev-field">
            <label>Free Delivery Label</label>
            <input type="text" id="hkdev-fd-label" value="<?php echo esc_attr($fd_settings['label'] ?? 'Free Delivery'); ?>" placeholder="e.g. Standard Free Delivery">
          </div>
          <div class="hkdev-field">
            <label>Quantity Threshold</label>
            <input type="number" id="hkdev-fd-qty-threshold" value="<?php echo esc_attr($fd_settings['qty_threshold'] ?? 2); ?>" min="1">
          </div>
          <div class="hkdev-toggle-row" style="border:none;padding-bottom:0">
            <div class="hkdev-toggle-info">
              <strong>Enable Pulse Animation</strong>
              <span>Show animated badge on cart/checkout page</span>
            </div>
            <label class="hkdev-toggle hkdev-toggle--emerald">
              <input type="checkbox" id="hkdev-fd-enable-anim" <?php checked(!empty($fd_settings['enable_anim']), true); ?>>
              <span class="slider"></span>
            </label>
          </div>
        </div>
      </div>

      <div class="hkdev-card">
        <div class="hkdev-card-header"><h3>Product &amp; Category Selection</h3><p>Global triggers for specific products and categories</p></div>
        <div class="hkdev-grid-2">
          <div>
            <label class="hkdev-field-label">Allowed Products</label>
            <div class="hkdev-search-input-wrap">
              <input type="text" id="hkdev-fd-product-search" placeholder="Search products..." class="hkdev-search-input" autocomplete="off">
              <div id="hkdev-fd-product-results" class="hkdev-search-results" style="display:none"></div>
            </div>
            <div id="hkdev-fd-product-tags" class="hkdev-tag-list">
              <?php foreach ($fd_products as $p): ?>
              <span class="hkdev-tag hkdev-fd-product-tag" data-id="<?php echo esc_attr($p['id']); ?>">
                <?php echo esc_html($p['name']); ?>
                <button type="button" onclick="jQuery(this).closest('.hkdev-tag').remove()">×</button>
              </span>
              <?php endforeach; ?>
            </div>
          </div>
          <div>
            <label class="hkdev-field-label">Allowed Categories</label>
            <div class="hkdev-search-input-wrap">
              <input type="text" id="hkdev-fd-cat-search" placeholder="Search categories..." class="hkdev-search-input" autocomplete="off">
              <div id="hkdev-fd-cat-results" class="hkdev-search-results" style="display:none"></div>
            </div>
            <div id="hkdev-fd-cat-tags" class="hkdev-tag-list">
              <?php foreach ($fd_categories as $c): ?>
              <span class="hkdev-tag hkdev-fd-cat-tag" data-id="<?php echo esc_attr($c['id']); ?>">
                <?php echo esc_html($c['name']); ?>
                <button type="button" onclick="jQuery(this).closest('.hkdev-tag').remove()">×</button>
              </span>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="submit-btn-row" style="display:flex;gap:12px;align-items:center">
        <button type="button" id="hkdev-fd-save-btn" class="hkdev-btn hkdev-btn--emerald">Save Free Delivery Settings</button>
        <span id="hkdev-fd-save-msg" style="font-size:13px;color:#10b981;display:none"></span>
      </div>
    </div>

    <!-- Support Tab -->
    <div id="delivery-tab-support" class="hkdev-tab-content">
      <div class="hkdev-card">
        <div class="hkdev-author-card">
          <div class="hkdev-author-avatar">HK</div>
          <div>
            <h2 class="hkdev-author-name">HKDEV — Professional Solutions</h2>
            <p class="hkdev-author-desc">We build high-performance WordPress &amp; WooCommerce extensions focused on security and conversion optimization.</p>
            <div class="hkdev-author-links">
              <a href="https://wa.me/8801781115586" target="_blank">Contact WhatsApp</a>
              <a href="https://facebook.com/hayatulla.oop" target="_blank">Facebook</a>
            </div>
          </div>
        </div>
        <div class="hkdev-plugin-info">
          <div class="hkdev-plugin-info-row"><span class="key">Plugin</span><span class="val">HKDEV SMS Suite</span></div>
          <div class="hkdev-plugin-info-row"><span class="key">Version</span><span class="val">3.0.0</span></div>
          <div class="hkdev-plugin-info-row"><span class="key">Free Delivery Module</span><span class="val">v2.0 (integrated)</span></div>
          <div class="hkdev-plugin-info-row"><span class="key">Author</span><span class="val">HKDEV</span></div>
          <div class="hkdev-plugin-info-row"><span class="key">License</span><span class="val">GPL v3</span></div>
        </div>
      </div>
    </div>
  </div><!-- /delivery view -->

</div><!-- /hkdev-content -->


<!-- ====== OTP PREVIEW MODAL ====== -->
<div id="hkdev-otp-modal" class="hkdev-modal-overlay" style="display:none">
  <div class="hkdev-modal-box">
    <button class="hkdev-modal-close" id="hkdev-otp-modal-close">×</button>

    <!-- Step 1: Phone Entry -->
    <div class="hkdev-modal-step active" id="hkdev-otp-step-1">
      <div style="width:56px;height:56px;background:linear-gradient(135deg,#6366f1,#8b5cf6);border-radius:16px;display:flex;align-items:center;justify-content:center;margin:0 auto 16px">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.07 9.81 19.79 19.79 0 01.01 1.18 2 2 0 012 .01h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L6.09 7.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 14.9v2.02z"/></svg>
      </div>
      <h2 style="margin:0 0 6px;font-size:20px;color:#1e293b">Verify Phone Number</h2>
      <p style="margin:0 0 24px;color:#64748b;font-size:13px">Enter your phone number to receive an OTP</p>
      <input type="tel" id="hkdev-otp-phone-input" placeholder="01XXXXXXXXX" style="width:100%;padding:10px 14px;border:2px solid #e2e8f0;border-radius:10px;font-size:15px;text-align:center;box-sizing:border-box;outline:none;transition:.2s" onfocus="this.style.borderColor='#6366f1'" onblur="this.style.borderColor='#e2e8f0'">
      <button id="hkdev-otp-send-btn" class="hkdev-btn hkdev-btn--primary" style="width:100%;margin-top:12px;padding:12px">Send OTP</button>
    </div>

    <!-- Step 2: OTP Entry -->
    <div class="hkdev-modal-step" id="hkdev-otp-step-2">
      <div style="width:56px;height:56px;background:#dbeafe;border-radius:16px;display:flex;align-items:center;justify-content:center;margin:0 auto 16px">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0110 0v4"/></svg>
      </div>
      <h2 style="margin:0 0 6px;font-size:20px;color:#1e293b">Enter OTP Code</h2>
      <p style="margin:0 0 4px;color:#64748b;font-size:13px">Code sent to <strong id="hkdev-otp-phone-display"></strong></p>
      <div class="hkdev-otp-inputs">
        <input type="text" class="hkdev-otp-digit" maxlength="1" inputmode="numeric">
        <input type="text" class="hkdev-otp-digit" maxlength="1" inputmode="numeric">
        <input type="text" class="hkdev-otp-digit" maxlength="1" inputmode="numeric">
        <input type="text" class="hkdev-otp-digit" maxlength="1" inputmode="numeric">
        <input type="text" class="hkdev-otp-digit" maxlength="1" inputmode="numeric">
        <input type="text" class="hkdev-otp-digit" maxlength="1" inputmode="numeric">
      </div>
      <p id="hkdev-otp-countdown" class="hkdev-countdown">Expires in 1:00</p>
      <button id="hkdev-otp-verify-btn" class="hkdev-btn hkdev-btn--primary" style="width:100%;padding:12px;margin-top:8px">Verify OTP</button>
      <button id="hkdev-resend-btn" class="hkdev-btn hkdev-btn--ghost" style="width:100%;margin-top:8px;padding:10px" disabled>Resend in 60s</button>
    </div>

    <!-- Step 3: Success -->
    <div class="hkdev-modal-step" id="hkdev-otp-step-3">
      <div style="width:56px;height:56px;background:#d1fae5;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 16px">
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
      </div>
      <h2 style="margin:0 0 6px;font-size:20px;color:#1e293b">Verified!</h2>
      <p style="margin:0 0 24px;color:#64748b;font-size:13px">Your phone number has been verified successfully.</p>
      <button onclick="document.getElementById('hkdev-otp-modal').style.display='none'" class="hkdev-btn hkdev-btn--primary" style="width:100%;padding:12px">Continue to Checkout</button>
    </div>
  </div>
</div>

</div><!-- /hkdev-suite-wrap -->
