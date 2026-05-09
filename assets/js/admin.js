document.addEventListener('DOMContentLoaded', function() {

    // --- Navigation ---
    window.switchAppView = function(viewId) {
        document.querySelectorAll('.view-container').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.sidebar-nav li').forEach(el => el.classList.remove('active'));

        const viewEl = document.getElementById(`app-view-${viewId}`);
        const navEl = document.getElementById(`nav-${viewId}`);

        if (viewEl) viewEl.classList.add('active');
        if (navEl) navEl.classList.add('active');
    };

    window.switchTab = function(evt, group, tabId) {
        evt.preventDefault();
        const viewContainer = document.getElementById(`app-view-${group}`);

        if (!viewContainer) return;

        viewContainer.querySelectorAll('.pill-tab').forEach(tab => tab.classList.remove('active'));
        viewContainer.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));

        evt.currentTarget.classList.add('active');
        const tabEl = document.getElementById(`${group}-tab-${tabId}`);
        if (tabEl) {
            tabEl.classList.add('active');
        }
    };

    // --- Refresh Balance ---
    const btnRefresh = document.getElementById('btn-refresh-balance');
    if (btnRefresh) {
        btnRefresh.addEventListener('click', function(e) {
            e.preventDefault();
            const originalText = this.innerHTML;
            this.innerHTML = '<i class="ph-bold ph-spinner-gap" style="animation: spin 1s linear infinite;"></i> Refreshing...';
            this.disabled = true;

            jQuery.post(hkdevAjax.ajaxUrl, {
                action: 'hkdev_check_balance',
                nonce: hkdevAjax.nonce
            }, function(response) {
                if (response.success) {
                    alert('Balance Updated: ' + response.data.amount);
                    location.reload();
                } else {
                    alert('Error: ' + response.data);
                    btnRefresh.innerHTML = originalText;
                    btnRefresh.disabled = false;
                }
            });
        });
    }

    // --- Test SMS ---
    window.testSMS = function() {
        const phone = prompt('Enter phone number to test:');
        if (!phone) return;

        const message = prompt('Enter test message:', 'Test SMS from HKDEV SMS Suite');
        if (!message) return;

        const btnTest = document.getElementById('btn-test-sms');
        const originalText = btnTest.innerHTML;
        btnTest.innerHTML = '<i class="ph-bold ph-spinner-gap" style="animation: spin 1s linear infinite;"></i> Sending...';
        btnTest.disabled = true;

        jQuery.post(hkdevAjax.ajaxUrl, {
            action: 'hkdev_test_sms',
            nonce: hkdevAjax.nonce,
            phone: phone,
            message: message
        }, function(response) {
            if (response.success) {
                alert('Success: ' + response.data);
            } else {
                alert('Error: ' + response.data);
            }
            btnTest.innerHTML = originalText;
            btnTest.disabled = false;
        });
    };

    // --- Clear Logs ---
    window.clearLogs = function() {
        if (!confirm('Are you sure you want to clear all SMS logs? This action cannot be undone.')) {
            return;
        }

        const btnClear = document.getElementById('btn-clear-logs');
        const originalText = btnClear.innerHTML;
        btnClear.innerHTML = '<i class="ph-bold ph-spinner-gap" style="animation: spin 1s linear infinite;"></i> Clearing...';
        btnClear.disabled = true;

        jQuery.post(hkdevAjax.ajaxUrl, {
            action: 'hkdev_clear_logs',
            nonce: hkdevAjax.nonce
        }, function(response) {
            if (response.success) {
                alert('Success: ' + response.data);
                location.reload();
            } else {
                alert('Error: ' + response.data);
                btnClear.innerHTML = originalText;
                btnClear.disabled = false;
            }
        });
    };

    // --- Form Submission ---
    const form = document.getElementById('hkdev-settings-form');
    if (form) {
        form.addEventListener('submit', function(e) {
            // Allow default form submission (handled by WordPress)
        });
    }

    // Add spin animation
    const style = document.createElement('style');
    style.textContent = `
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
    `;
    document.head.appendChild(style);
});
