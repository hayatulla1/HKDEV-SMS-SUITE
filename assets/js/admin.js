document.addEventListener('DOMContentLoaded', function() {

    // ── Toast Notification System ──────────────────────────────────────────────
    (function() {
        const container = document.createElement('div');
        container.id = 'hkdev-toast-container';
        document.body.appendChild(container);
    })();

    window.hkdevToast = function(message, type) {
        type = type || 'success'; // 'success' | 'error' | 'info'
        const container = document.getElementById('hkdev-toast-container');
        const toast = document.createElement('div');
        toast.className = 'hkdev-toast hkdev-toast-' + type;

        const icons = { success: 'ph-check-circle', error: 'ph-warning-circle', info: 'ph-info' };
        toast.innerHTML = '<i class="ph-fill ' + (icons[type] || icons.info) + '"></i><span>' + message + '</span>';
        container.appendChild(toast);

        // Trigger show
        requestAnimationFrame(function() { toast.classList.add('show'); });

        setTimeout(function() {
            toast.classList.remove('show');
            setTimeout(function() { toast.remove(); }, 400);
        }, 3500);
    };

    // ── Navigation ─────────────────────────────────────────────────────────────
    window.switchAppView = function(viewId) {
        document.querySelectorAll('.view-container').forEach(function(el) { el.classList.remove('active'); });
        document.querySelectorAll('.sidebar-nav li').forEach(function(el) { el.classList.remove('active'); });

        var viewEl = document.getElementById('app-view-' + viewId);
        var navEl  = document.getElementById('nav-' + viewId);

        if (viewEl) viewEl.classList.add('active');
        if (navEl)  navEl.classList.add('active');
    };

    window.switchTab = function(evt, group, tabId) {
        evt.preventDefault();
        var viewContainer = document.getElementById('app-view-' + group);
        if (!viewContainer) return;

        viewContainer.querySelectorAll('.pill-tab').forEach(function(t) { t.classList.remove('active'); });
        viewContainer.querySelectorAll('.tab-content').forEach(function(c) { c.classList.remove('active'); });

        evt.currentTarget.classList.add('active');
        var tabEl = document.getElementById(group + '-tab-' + tabId);
        if (tabEl) tabEl.classList.add('active');
    };

    // ── Refresh Balance ────────────────────────────────────────────────────────
    var btnRefresh = document.getElementById('btn-refresh-balance');
    if (btnRefresh) {
        btnRefresh.addEventListener('click', function(e) {
            e.preventDefault();
            var btn = this;
            var originalHTML = btn.innerHTML;
            btn.innerHTML = '<i class="ph-bold ph-spinner-gap hkdev-spin"></i> Checking...';
            btn.disabled = true;

            jQuery.post(hkdevAjax.ajaxUrl, {
                action: 'hkdev_check_balance',
                nonce: hkdevAjax.nonce
            }, function(response) {
                btn.innerHTML = originalHTML;
                btn.disabled = false;

                if (response.success) {
                    var amount = response.data.amount;

                    // Update sidebar balance in place
                    var balanceEl = document.getElementById('hkdev-sidebar-balance');
                    if (balanceEl) balanceEl.textContent = amount;

                    hkdevToast('Balance updated: ' + amount, 'success');
                } else {
                    hkdevToast((response.data || 'Failed to fetch balance'), 'error');
                }
            }).fail(function() {
                btn.innerHTML = originalHTML;
                btn.disabled = false;
                hkdevToast('Request failed. Please try again.', 'error');
            });
        });
    }

    // ── Test SMS (inline panel) ────────────────────────────────────────────────
    window.testSMS = function() {
        var panel = document.getElementById('hkdev-test-sms-panel');
        if (!panel) return;
        var isHidden = (window.getComputedStyle(panel).display === 'none');
        panel.style.display = isHidden ? 'block' : 'none';
    };

    var btnSendTest = document.getElementById('btn-send-test-sms');
    if (btnSendTest) {
        btnSendTest.addEventListener('click', function() {
            var phone   = document.getElementById('test-sms-phone').value.trim();
            var message = document.getElementById('test-sms-message').value.trim();

            if (!phone) { hkdevToast('Please enter a phone number.', 'error'); return; }
            if (!message) { hkdevToast('Please enter a message.', 'error'); return; }

            var btn = this;
            var originalHTML = btn.innerHTML;
            btn.innerHTML = '<i class="ph-bold ph-spinner-gap hkdev-spin"></i> Sending...';
            btn.disabled = true;

            jQuery.post(hkdevAjax.ajaxUrl, {
                action: 'hkdev_test_sms',
                nonce: hkdevAjax.nonce,
                phone: phone,
                message: message
            }, function(response) {
                btn.innerHTML = originalHTML;
                btn.disabled = false;

                if (response.success) {
                    hkdevToast('SMS sent successfully!', 'success');
                } else {
                    hkdevToast((response.data || 'Failed to send SMS'), 'error');
                }
            }).fail(function() {
                btn.innerHTML = originalHTML;
                btn.disabled = false;
                hkdevToast('Request failed. Please try again.', 'error');
            });
        });
    }

    // ── Clear Logs ─────────────────────────────────────────────────────────────
    window.clearLogs = function() {
        if (!confirm('Are you sure you want to clear all SMS logs? This cannot be undone.')) return;

        var btnClear = document.getElementById('btn-clear-logs');
        var originalHTML = btnClear.innerHTML;
        btnClear.innerHTML = '<i class="ph-bold ph-spinner-gap hkdev-spin"></i> Clearing...';
        btnClear.disabled = true;

        jQuery.post(hkdevAjax.ajaxUrl, {
            action: 'hkdev_clear_logs',
            nonce: hkdevAjax.nonce
        }, function(response) {
            if (response.success) {
                hkdevToast('Logs cleared successfully.', 'success');
                // Small delay so the toast is visible before the page refreshes
                setTimeout(function() { location.reload(); }, 1000);
            } else {
                hkdevToast((response.data || 'Failed to clear logs'), 'error');
                btnClear.innerHTML = originalHTML;
                btnClear.disabled = false;
            }
        }).fail(function() {
            btnClear.innerHTML = originalHTML;
            btnClear.disabled = false;
            hkdevToast('Request failed. Please try again.', 'error');
        });
    };

    // ── Spin animation ─────────────────────────────────────────────────────────
    // (keyframe + class defined in admin.css)
});
