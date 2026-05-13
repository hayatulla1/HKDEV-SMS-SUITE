jQuery(document).ready(function ($) {

    // ── View Switching ──────────────────────────────────────────────────────────
    window.hkSwitchView = function (view) {
        $('.hkdev-view').removeClass('active');
        $('#hkdev-view-' + view).addClass('active');
        $('.hkdev-nb-btn').removeClass('active');
        $('.hkdev-nb-btn[data-view="' + view + '"]').addClass('active');
    };

    // ── Tab Switching ───────────────────────────────────────────────────────────
    window.hkSwitchTab = function (viewId, tabId) {
        var $view = $('#hkdev-view-' + viewId);
        $view.find('.hkdev-tab-btn').removeClass('active');
        $view.find('.hkdev-tab-btn[data-tab="' + tabId + '"]').addClass('active');
        $view.find('.hkdev-tab-content').removeClass('active');
        $view.find('#' + viewId + '-tab-' + tabId).addClass('active');
    };

    // ── Blocked User Expand / Collapse ──────────────────────────────────────────
    $(document).on('click', '.hkdev-blocked-header', function () {
        $(this).closest('.hkdev-blocked-item').toggleClass('open');
    });

    // ── Check Balance ───────────────────────────────────────────────────────────
    $(document).on('click', '#hkdev-check-balance', function () {
        var $btn = $(this);
        $btn.text('Checking…').prop('disabled', true);

        $.post(hkdevAjax.ajaxUrl, { action: 'hkdev_check_balance', nonce: hkdevAjax.nonce },
            function (res) {
                if (res.success) {
                    var amount = '৳' + res.data.amount;
                    $('#hkdev-balance-display').text(amount);
                    $('#hkdev-balance-value').text(amount);
                    $('#hkdev-balance-time').text('Just now');
                } else {
                    alert('Error: ' + res.data);
                }
            }
        ).always(function () { $btn.text('Check Balance').prop('disabled', false); });
    });

    // ── Test SMS ────────────────────────────────────────────────────────────────
    $(document).on('click', '#hkdev-test-sms-btn', function () {
        var phone = $('#hkdev-test-phone').val().trim();
        var msg   = $('#hkdev-test-message').val().trim() || 'Test SMS from HKDEV SMS Suite';
        if (!phone) { alert('Phone number is required'); return; }

        var $btn = $(this);
        $btn.text('Sending…').prop('disabled', true);

        $.post(hkdevAjax.ajaxUrl, { action: 'hkdev_test_sms', nonce: hkdevAjax.nonce, phone: phone, message: msg },
            function (res) { alert(res.success ? ('✓ ' + res.data) : ('✗ ' + res.data)); }
        ).always(function () { $btn.text('Send Test SMS').prop('disabled', false); });
    });

    // ── Clear SMS Logs ──────────────────────────────────────────────────────────
    $(document).on('click', '#hkdev-clear-sms-logs', function () {
        if (!confirm('Clear all SMS logs?')) return;
        $.post(hkdevAjax.ajaxUrl, { action: 'hkdev_clear_logs', nonce: hkdevAjax.nonce },
            function (res) {
                if (res.success) {
                    $('#hkdev-sms-log-table tbody').html('<tr><td colspan="4" style="text-align:center;color:#94a3b8;padding:32px">No logs</td></tr>');
                    $('#hkdev-log-count').text('0');
                }
            }
        );
    });

    // ── Unblock Individual User ─────────────────────────────────────────────────
    $(document).on('click', '.hkdev-unblock-btn-action', function () {
        var blockId = $(this).data('id');
        var $item   = $(this).closest('.hkdev-blocked-item');
        if (!confirm('Unblock this user?')) return;

        $.post(hkdevAjax.ajaxUrl, { action: 'hkdev_unblock_user', nonce: hkdevAjax.nonce, block_id: blockId },
            function (res) {
                if (res.success) {
                    $item.fadeOut(300, function () { $(this).remove(); });
                    var c = parseInt($('#hkdev-blocked-count').text(), 10) - 1;
                    $('#hkdev-blocked-count').text(c < 0 ? 0 : c);
                } else {
                    alert('Error: ' + res.data);
                }
            }
        );
    });

    // ── Clear All Blocks ────────────────────────────────────────────────────────
    $(document).on('click', '#hkdev-clear-all-blocks', function () {
        if (!confirm('Unblock ALL users? This cannot be undone.')) return;
        $.post(hkdevAjax.ajaxUrl, { action: 'hkdev_clear_all_blocks', nonce: hkdevAjax.nonce },
            function (res) {
                if (res.success) {
                    $('#hkdev-blocked-list').html('<div style="text-align:center;padding:48px;color:#94a3b8;background:#fff;border-radius:12px;border:1px dashed #e2e8f0">No users currently blocked</div>');
                }
            }
        );
    });

    // ── Clear Block Logs ────────────────────────────────────────────────────────
    $(document).on('click', '#hkdev-clear-block-logs', function () {
        if (!confirm('Clear all block activity logs?')) return;
        $.post(hkdevAjax.ajaxUrl, { action: 'hkdev_clear_block_logs', nonce: hkdevAjax.nonce },
            function (res) {
                if (res.success) {
                    $('#hkdev-block-log-table tbody').html('<tr><td colspan="7" style="text-align:center;color:#94a3b8;padding:32px">No activity logs</td></tr>');
                }
            }
        );
    });

    // ── Free Delivery: Product Search ───────────────────────────────────────────
    var productTimer;
    $(document).on('input', '#hkdev-fd-product-search', function () {
        clearTimeout(productTimer);
        var term = $(this).val().trim();
        if (term.length < 2) { $('#hkdev-fd-product-results').hide(); return; }

        productTimer = setTimeout(function () {
            $.post(hkdevAjax.ajaxUrl, { action: 'hkdev_search_products', nonce: hkdevAjax.nonce, term: term },
                function (res) {
                    if (!res.success || !res.data.length) {
                        $('#hkdev-fd-product-results').html('<div class="no-results">No products found</div>').show();
                        return;
                    }
                    var html = '';
                    $.each(res.data, function (i, p) {
                        html += '<div class="hkdev-fd-product-result" data-id="' + p.id + '" data-name="' + $('<div>').text(p.name).html() + '">' + $('<div>').text(p.name).html() + '</div>';
                    });
                    $('#hkdev-fd-product-results').html(html).show();
                }
            );
        }, 300);
    });

    $(document).on('click', '.hkdev-fd-product-result', function () {
        var id   = $(this).data('id');
        var name = $(this).data('name');
        if (!$('.hkdev-fd-product-tag[data-id="' + id + '"]').length) {
            $('#hkdev-fd-product-tags').append(
                '<span class="hkdev-tag hkdev-fd-product-tag" data-id="' + id + '">' +
                $('<div>').text(name).html() +
                '<button type="button" onclick="jQuery(this).closest(\'.hkdev-tag\').remove()">×</button></span>'
            );
        }
        $('#hkdev-fd-product-search').val('');
        $('#hkdev-fd-product-results').hide();
    });

    // ── Free Delivery: Category Search ─────────────────────────────────────────
    var catTimer;
    $(document).on('input', '#hkdev-fd-cat-search', function () {
        clearTimeout(catTimer);
        var term = $(this).val().trim();
        if (term.length < 2) { $('#hkdev-fd-cat-results').hide(); return; }

        catTimer = setTimeout(function () {
            $.get(hkdevAjax.ajaxUrl, { action: 'hkdev_search_categories', security: hkdevAjax.searchNonce, term: term },
                function (data) {
                    if (!data || !data.length) {
                        $('#hkdev-fd-cat-results').html('<div class="no-results">No categories found</div>').show();
                        return;
                    }
                    var html = '';
                    $.each(data, function (i, c) {
                        html += '<div class="hkdev-fd-cat-result" data-id="' + c.id + '" data-name="' + $('<div>').text(c.text).html() + '">' + $('<div>').text(c.text).html() + '</div>';
                    });
                    $('#hkdev-fd-cat-results').html(html).show();
                }
            );
        }, 300);
    });

    $(document).on('click', '.hkdev-fd-cat-result', function () {
        var id   = $(this).data('id');
        var name = $(this).data('name');
        if (!$('.hkdev-fd-cat-tag[data-id="' + id + '"]').length) {
            $('#hkdev-fd-cat-tags').append(
                '<span class="hkdev-tag hkdev-fd-cat-tag" data-id="' + id + '">' +
                $('<div>').text(name).html() +
                '<button type="button" onclick="jQuery(this).closest(\'.hkdev-tag\').remove()">×</button></span>'
            );
        }
        $('#hkdev-fd-cat-search').val('');
        $('#hkdev-fd-cat-results').hide();
    });

    // Close dropdowns on outside click
    $(document).on('click', function (e) {
        if (!$(e.target).closest('.hkdev-search-input-wrap').length) {
            $('#hkdev-fd-product-results, #hkdev-fd-cat-results').hide();
        }
    });

    // ── Free Delivery: Save Settings (AJAX) ────────────────────────────────────
    $(document).on('click', '#hkdev-fd-save-btn', function () {
        var $btn = $(this);
        $btn.text('Saving…').prop('disabled', true);

        var products = [], categories = [];
        $('.hkdev-fd-product-tag').each(function () { products.push($(this).data('id')); });
        $('.hkdev-fd-cat-tag').each(function () { categories.push($(this).data('id')); });

        var data = {
            action:          'hkdev_save_fd_settings',
            nonce:           hkdevAjax.nonce,
            enable_qty:      $('#hkdev-fd-enable-qty').is(':checked')      ? 1 : 0,
            enable_products: $('#hkdev-fd-enable-products').is(':checked') ? 1 : 0,
            enable_cats:     $('#hkdev-fd-enable-cats').is(':checked')     ? 1 : 0,
            enable_anim:     $('#hkdev-fd-enable-anim').is(':checked')     ? 1 : 0,
            qty_threshold:   $('#hkdev-fd-qty-threshold').val(),
            label:           $('#hkdev-fd-label').val(),
        };
        data['products[]']   = products;
        data['categories[]'] = categories;

        $.post(hkdevAjax.ajaxUrl, data, function (res) {
            if (res.success) {
                $btn.text('✓ Saved!');
                $('#hkdev-fd-save-msg').text('Settings saved successfully.').show();
                setTimeout(function () {
                    $btn.text('Save Free Delivery Settings').prop('disabled', false);
                    $('#hkdev-fd-save-msg').fadeOut();
                }, 2500);
            } else {
                alert('Error: ' + res.data);
                $btn.text('Save Free Delivery Settings').prop('disabled', false);
            }
        });
    });

    // ── OTP Preview Modal ───────────────────────────────────────────────────────
    var otpTimer = null;
    var otpPhone = '';

    function showOTPStep(step) {
        $('.hkdev-modal-step').removeClass('active');
        $('#hkdev-otp-step-' + step).addClass('active');
    }

    $(document).on('click', '#hkdev-preview-otp', function () {
        showOTPStep(1);
        $('#hkdev-otp-phone-input').val('');
        $('#hkdev-otp-modal').fadeIn(200);
    });

    $(document).on('click', '#hkdev-otp-modal-close', function () {
        $('#hkdev-otp-modal').fadeOut(200);
        clearInterval(otpTimer);
    });

    $(document).on('click', '#hkdev-otp-modal', function (e) {
        if ($(e.target).is('#hkdev-otp-modal')) {
            $(this).fadeOut(200);
            clearInterval(otpTimer);
        }
    });

    // Step 1: Send OTP (preview — simulated)
    $(document).on('click', '#hkdev-otp-send-btn', function () {
        otpPhone = $('#hkdev-otp-phone-input').val().trim();
        if (!otpPhone) { $('#hkdev-otp-phone-input').focus(); return; }

        var $btn = $(this);
        $btn.text('Sending…').prop('disabled', true);

        // Simulate delay then show step 2
        setTimeout(function () {
            $btn.text('Send OTP').prop('disabled', false);
            $('#hkdev-otp-phone-display').text(otpPhone);
            showOTPStep(2);
            initOTPInputs();
            startCountdown(60);
        }, 800);
    });

    function initOTPInputs() {
        var $digits = $('.hkdev-otp-digit');
        $digits.val('').removeClass('hkdev-otp-error').first().focus();

        $digits.off('input keydown').on('input', function () {
            var $t  = $(this);
            var val = $t.val().replace(/\D/g, '').slice(0, 1);
            $t.val(val);
            if (val) {
                var $next = $t.nextAll('input.hkdev-otp-digit').first();
                if ($next.length) { $next.focus(); }
                else { checkOTPPreview(); }
            }
        }).on('keydown', function (e) {
            if (e.key === 'Backspace' && !$(this).val()) {
                $(this).prevAll('input.hkdev-otp-digit').first().focus();
            }
        });
    }

    function checkOTPPreview() {
        var code = $('.hkdev-otp-digit').map(function () { return $(this).val(); }).get().join('');
        if (code.length !== 6) return;
        // Preview mode — any 6 digits = success
        clearInterval(otpTimer);
        setTimeout(function () { showOTPStep(3); }, 300);
    }

    // Step 2: Verify button (for users who don't auto-advance)
    $(document).on('click', '#hkdev-otp-verify-btn', function () {
        checkOTPPreview();
    });

    function startCountdown(seconds) {
        clearInterval(otpTimer);
        var remaining = seconds;
        updateCountdown(remaining);

        otpTimer = setInterval(function () {
            remaining--;
            updateCountdown(remaining);
            if (remaining <= 0) {
                clearInterval(otpTimer);
                $('#hkdev-resend-btn').prop('disabled', false).text('Resend OTP');
            }
        }, 1000);
    }

    function updateCountdown(s) {
        var m   = Math.floor(s / 60);
        var sec = s % 60;
        $('#hkdev-otp-countdown').text('Expires in ' + m + ':' + (sec < 10 ? '0' : '') + sec);
        if (s > 0) {
            $('#hkdev-resend-btn').prop('disabled', true).text('Resend in ' + s + 's');
        }
    }

    // Resend
    $(document).on('click', '#hkdev-resend-btn', function () {
        if ($(this).prop('disabled')) return;
        initOTPInputs();
        startCountdown(60);
    });

});
