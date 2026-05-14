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

    function hkdevEscapeHtml(value) {
        var str = String(value);
        return str.replace(/[&<>"']/g, function (s) {
            return ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            })[s];
        });
    }

    function hkdevResolveMessage(payload) {
        return payload && payload.message ? payload.message : payload;
    }

    function hkdevNormalizeAjaxResponse(response) {
        if (typeof response === 'string') {
            try {
                return JSON.parse(response);
            } catch (err) {
                return null;
            }
        }
        return response;
    }

    function hkdevAddOtpProductTag(id, name) {
        var numericId = parseInt(id, 10);
        var safeName = typeof name === 'string' ? name.trim() : String(name || '').trim();
        if (Number.isNaN(numericId) || !safeName) {
            if (window.console && console.warn) {
                console.warn('HKDEV SMS Suite: Invalid OTP product data', { id: id, name: name });
            }
            return;
        }
        var exists = $('#hkdev-otp-product-tags .hkdev-otp-product-tag').filter(function () {
            return parseInt($(this).data('id'), 10) === numericId;
        }).length;
        if (exists) {
            return;
        }
        var $tag = $('<span class="hkdev-tag hkdev-otp-product-tag" data-id="' + numericId + '"></span>');
        $tag.append(document.createTextNode(safeName));
        $tag.append('<input type="hidden" name="products[]" value="' + numericId + '" class="hkdev-otp-product-input">');
        $tag.append('<button type="button" class="hkdev-tag-remove">×</button>');
        $('#hkdev-otp-product-tags').append($tag);
    }

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
                    $('#hkdev-balance-time').text(' — checked Just now');
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
                    $('#hkdev-sms-log-badge').text('0');
                    $('#hkdev-sms-log-total').text('0');
                    $('#hkdev-sms-log-sent').text('0');
                    $('#hkdev-sms-log-failed').text('0');
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
                    c = c < 0 ? 0 : c;
                    $('#hkdev-blocked-count').text(c);
                    $('#hkdev-blocked-total').text(c);
                    if (c === 0) {
                        $('#hkdev-blocked-list').html('<div style="text-align:center;padding:48px;color:#94a3b8;background:#fff;border-radius:12px;border:1px dashed #e2e8f0">No users currently blocked</div>');
                        $('#hkdev-clear-all-blocks').hide();
                    }
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
                    $('#hkdev-blocked-count').text('0');
                    $('#hkdev-blocked-total').text('0');
                    $('#hkdev-clear-all-blocks').hide();
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
                    $('#hkdev-block-log-badge').text('0');
                    $('#hkdev-block-log-total').text('0');
                    $('#hkdev-block-log-blocked').text('0');
                    $('#hkdev-block-log-unblocked').text('0');
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
                    var $results = $('#hkdev-fd-product-results').empty();
                    $.each(res.data, function (i, p) {
                        var $item = $('<div class="hkdev-fd-product-result"></div>');
                        $item.text(p.name);
                        $item.attr('data-id', p.id);
                        $item.data('name', p.name);
                        $results.append($item);
                    });
                    $results.show();
                }
            );
        }, 300);
    });

    $(document).on('keydown', '#hkdev-fd-product-search', function (e) {
        if (e.key !== 'Enter') {
            return;
        }
        var $first = $('#hkdev-fd-product-results .hkdev-fd-product-result').first();
        if ($first.length) {
            e.preventDefault();
            $first.trigger('click');
        }
    });

    $(document).on('click', '.hkdev-fd-product-result', function () {
        var id   = $(this).data('id');
        var name = $(this).data('name');
        if (!$('.hkdev-fd-product-tag[data-id="' + id + '"]').length) {
            $('#hkdev-fd-product-tags').append(
                '<span class="hkdev-tag hkdev-fd-product-tag" data-id="' + id + '">' +
                hkdevEscapeHtml(name) +
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
                    var $results = $('#hkdev-fd-cat-results').empty();
                    $.each(data, function (i, c) {
                        var $item = $('<div class="hkdev-fd-cat-result"></div>');
                        $item.text(c.text);
                        $item.attr('data-id', c.id);
                        $item.data('name', c.text);
                        $results.append($item);
                    });
                    $results.show();
                }
            );
        }, 300);
    });

    $(document).on('keydown', '#hkdev-fd-cat-search', function (e) {
        if (e.key !== 'Enter') {
            return;
        }
        var $first = $('#hkdev-fd-cat-results .hkdev-fd-cat-result').first();
        if ($first.length) {
            e.preventDefault();
            $first.trigger('click');
        }
    });

    $(document).on('click', '.hkdev-fd-cat-result', function () {
        var id   = $(this).data('id');
        var name = $(this).data('name');
        if (!$('.hkdev-fd-cat-tag[data-id="' + id + '"]').length) {
            $('#hkdev-fd-cat-tags').append(
                '<span class="hkdev-tag hkdev-fd-cat-tag" data-id="' + id + '">' +
                hkdevEscapeHtml(name) +
                '<button type="button" onclick="jQuery(this).closest(\'.hkdev-tag\').remove()">×</button></span>'
            );
        }
        $('#hkdev-fd-cat-search').val('');
        $('#hkdev-fd-cat-results').hide();
    });

    // ── SMS Templates: Product Search ───────────────────────────────────────────
    var otpProductTimer;
    $(document).on('input', '#hkdev-otp-product-search', function () {
        clearTimeout(otpProductTimer);
        var term = $(this).val().trim();
        if (term.length < 2) { $('#hkdev-otp-product-results').hide(); return; }

        otpProductTimer = setTimeout(function () {
            $.post(hkdevAjax.ajaxUrl, { action: 'hkdev_search_products', nonce: hkdevAjax.nonce, term: term })
                .done(function (res) {
                    res = hkdevNormalizeAjaxResponse(res);
                    if (!res || !res.success || !Array.isArray(res.data) || !res.data.length) {
                        $('#hkdev-otp-product-results').html('<div class="no-results">No products found</div>').show();
                        return;
                    }
                    var $results = $('#hkdev-otp-product-results').empty();
                    $.each(res.data, function (i, p) {
                        var $item = $('<div class="hkdev-otp-product-result"></div>');
                        $item.text(p.name);
                        $item.attr('data-id', p.id);
                        $item.data('name', p.name);
                        $results.append($item);
                    });
                    $results.show();
                })
                .fail(function () {
                    $('#hkdev-otp-product-results').html('<div class="no-results">Search failed. Please refresh.</div>').show();
                });
        }, 300);
    });

    $(document).on('keydown', '#hkdev-otp-product-search', function (e) {
        if (e.key !== 'Enter') {
            return;
        }
        var $first = $('#hkdev-otp-product-results .hkdev-otp-product-result').first();
        if ($first.length) {
            e.preventDefault();
            $first.trigger('click');
        }
    });

    $(document).on('click', '.hkdev-otp-product-result', function () {
        var id   = $(this).data('id');
        var name = $(this).data('name');
        hkdevAddOtpProductTag(id, name);
        $('#hkdev-otp-product-search').val('');
        $('#hkdev-otp-product-results').hide();
    });

    // Close dropdowns on outside click
    $(document).on('click', function (e) {
        if (!$(e.target).closest('.hkdev-search-input-wrap').length) {
            $('#hkdev-fd-product-results, #hkdev-fd-cat-results, #hkdev-otp-product-results').hide();
        }
    });

    // ── Free Delivery: Save Settings (AJAX) ────────────────────────────────────
    $(document).on('click', '#hkdev-fd-save-btn', function () {
        var $btn = $(this);
        var $msg = $('#hkdev-fd-save-msg');
        $btn.text('Saving…').prop('disabled', true);
        $msg.removeClass('hkdev-save-msg--error').hide();

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
                $msg.removeClass('hkdev-save-msg--error').text('Settings saved successfully.').show();
                setTimeout(function () {
                    $btn.text('Save Free Delivery Settings').prop('disabled', false);
                    $msg.fadeOut();
                }, 2500);
            } else {
                var errorText = hkdevResolveMessage(res.data);
                $msg.addClass('hkdev-save-msg--error').text(errorText ? ('Error: ' + errorText) : 'Save failed').show();
                $btn.text('Save Free Delivery Settings').prop('disabled', false);
            }
        });
    });

    // ── SMS Templates: Prebuilt OTP Template ───────────────────────────────────
    $(document).on('click', '#hkdev-use-prebuilt-otp', function () {
        var template = $(this).data('template') || '';
        if (template) {
            $('textarea[name="sib_otp_template"]').val(template);
        }
    });

    // ── Tag Remove ─────────────────────────────────────────────────────────────
    $(document).on('click', '.hkdev-tag-remove', function () {
        $(this).closest('.hkdev-tag').remove();
    });

    // ── Admin Settings: AJAX Save Helpers ──────────────────────────────────────
    function hkdevHandleSave($btn, $msg, data, successFallback) {
        var original = $btn.text();
        $btn.text('Saving…').prop('disabled', true);
        $msg.removeClass('hkdev-save-msg--error').hide();

        $.post(hkdevAjax.ajaxUrl, data, function (res) {
            if (!res || typeof res.success === 'undefined') {
                $msg.addClass('hkdev-save-msg--error').text('Unexpected server response').show();
                $btn.text(original).prop('disabled', false);
                return;
            }
            if (res.success) {
                $btn.text('✓ Saved!');
                var successMessage = hkdevResolveMessage(res.data);
                $msg.removeClass('hkdev-save-msg--error').text(successMessage || successFallback || 'Saved successfully.').show();
                setTimeout(function () {
                    $btn.text(original).prop('disabled', false);
                    $msg.fadeOut();
                }, 2500);
            } else {
                var errorMessage = hkdevResolveMessage(res.data);
                $msg.addClass('hkdev-save-msg--error').text(errorMessage || 'Save failed').show();
                $btn.text(original).prop('disabled', false);
            }
        }).fail(function () {
            $msg.addClass('hkdev-save-msg--error').text('Save failed').show();
            $btn.text(original).prop('disabled', false);
        });
    }

    // ── General Settings Save ──────────────────────────────────────────────────
    $(document).on('click', '#hkdev-save-general', function () {
        var data = {
            action: 'hkdev_save_general_settings',
            nonce: hkdevAjax.nonce,
            hkdev_enable_gateway: $('input[name="hkdev_enable_gateway"]').is(':checked') ? 1 : 0,
            hkdev_enable_otp: $('input[name="hkdev_enable_otp"]').is(':checked') ? 1 : 0,
            hkdev_enable_order_confirmation_sms: $('input[name="hkdev_enable_order_confirmation_sms"]').is(':checked') ? 1 : 0,
            hkdev_enable_status_sms: $('input[name="hkdev_enable_status_sms"]').is(':checked') ? 1 : 0,
            hkdev_enable_logs: $('input[name="hkdev_enable_logs"]').is(':checked') ? 1 : 0,
            hkdev_enable_order_blocker: $('input[name="hkdev_enable_order_blocker"]').is(':checked') ? 1 : 0,
            hkdev_otp_length: $('input[name="hkdev_otp_length"]').val(),
            hkdev_otp_expiry_minutes: $('input[name="hkdev_otp_expiry_minutes"]').val(),
            hkdev_otp_cooldown_seconds: $('input[name="hkdev_otp_cooldown_seconds"]').val()
        };

        hkdevHandleSave($(this), $('#hkdev-save-general-msg'), data, 'Settings saved successfully.');
    });

    // ── API Settings Save ──────────────────────────────────────────────────────
    $(document).on('click', '#hkdev-save-api', function () {
        var data = {
            action: 'hkdev_save_api_settings',
            nonce: hkdevAjax.nonce,
            sib_gateway_url: $('input[name="sib_gateway_url"]').val(),
            sib_api_token: $('input[name="sib_api_token"]').val(),
            sib_sender_id: $('input[name="sib_sender_id"]').val(),
            sib_http_method: $('select[name="sib_http_method"]').val(),
            sib_param_token: $('input[name="sib_param_token"]').val(),
            sib_param_sender: $('input[name="sib_param_sender"]').val(),
            sib_param_number: $('input[name="sib_param_number"]').val(),
            sib_param_msg: $('input[name="sib_param_msg"]').val(),
            hkdev_balance_api_url: $('input[name="hkdev_balance_api_url"]').val(),
            hkdev_balance_response_key: $('input[name="hkdev_balance_response_key"]').val()
        };

        hkdevHandleSave($(this), $('#hkdev-save-api-msg'), data, 'Settings saved successfully.');
    });

    // ── Templates Save ─────────────────────────────────────────────────────────
    $(document).on('click', '#hkdev-save-templates', function () {
        var products = [];
        var seen = new Set();
        $('.hkdev-otp-product-input').each(function () {
            var id = parseInt($(this).val(), 10);
            if (!Number.isNaN(id) && !seen.has(id)) {
                seen.add(id);
                products.push(id);
            }
        });
        $('.hkdev-otp-product-tag').each(function () {
            var id = parseInt($(this).data('id'), 10);
            if (!Number.isNaN(id) && !seen.has(id)) {
                seen.add(id);
                products.push(id);
            }
        });

        var data = {
            action: 'hkdev_save_template_settings',
            nonce: hkdevAjax.nonce,
            sib_otp_template: $('textarea[name="sib_otp_template"]').val(),
            sib_order_template: $('textarea[name="sib_order_template"]').val(),
            sib_status_template: $('textarea[name="sib_status_template"]').val()
        };
        data.products = products;

        hkdevHandleSave($(this), $('#hkdev-save-templates-msg'), data, 'Templates saved successfully.');
    });

    // ── Blocker Settings Save ──────────────────────────────────────────────────
    $(document).on('click', '#hkdev-save-blocker', function () {
        var data = {
            action: 'hkdev_save_blocker_settings',
            nonce: hkdevAjax.nonce,
            usp_wcodb_block_duration_days: $('input[name="usp_wcodb_block_duration_days"]').val(),
            usp_wcodb_block_duration_hours: $('input[name="usp_wcodb_block_duration_hours"]').val(),
            usp_wcodb_block_duration_minutes: $('input[name="usp_wcodb_block_duration_minutes"]').val(),
            usp_wcodb_combined_block_enabled: $('input[name="usp_wcodb_combined_block_enabled"]').is(':checked') ? 1 : 0
        };

        hkdevHandleSave($(this), $('#hkdev-save-blocker-msg'), data, 'Settings saved successfully.');
    });

    // ── OTP Preview Modal ───────────────────────────────────────────────────────
    var otpTimer = null;
    var otpPhone = '';
    var otpRequestInFlight = false;
    var otpVerifyInFlight = false;
    var otpLength = parseInt(hkdevAjax && hkdevAjax.otpLength ? hkdevAjax.otpLength : '', 10);
    if (Number.isNaN(otpLength) || otpLength <= 0) {
        var digitCount = $('.hkdev-otp-digit').length;
        otpLength = digitCount > 0 ? digitCount : 6;
    }
    var otpCooldown = parseInt(hkdevAjax && hkdevAjax.otpCooldown ? hkdevAjax.otpCooldown : '', 10);
    if (Number.isNaN(otpCooldown) || otpCooldown <= 0) {
        otpCooldown = 60;
    }

    function showOTPStep(step) {
        $('.hkdev-modal-step').removeClass('active');
        $('#hkdev-otp-step-' + step).addClass('active');
    }

    $(document).on('click', '#hkdev-preview-otp', function () {
        showOTPStep(1);
        $('#hkdev-otp-phone-input').val('');
        otpPhone = '';
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

    function sendOtpRequest(phone, $button) {
        if (!phone || otpRequestInFlight) {
            return;
        }

        otpRequestInFlight = true;
        var $btn = $button && $button.length ? $button : $('#hkdev-otp-send-btn');
        var originalText = $btn.text();
        $btn.text('Sending…').prop('disabled', true);

        $.post(hkdevAjax.ajaxUrl, {
            action: 'hkdev_send_otp',
            nonce: hkdevAjax.otpNonce,
            phone: phone
        }).done(function (res) {
            res = hkdevNormalizeAjaxResponse(res);
            if (!res || !res.success) {
                var errorMessage = hkdevResolveMessage(res ? res.data : null) || 'Failed to send OTP';
                alert(errorMessage);
                return;
            }
            otpPhone = phone;
            $('#hkdev-otp-phone-display').text(otpPhone);
            showOTPStep(2);
            initOTPInputs();
            startCountdown(otpCooldown);
        }).fail(function () {
            alert('Failed to send OTP');
        }).always(function () {
            otpRequestInFlight = false;
            $btn.text(originalText).prop('disabled', false);
        });
    }

    // Step 1: Send OTP
    $(document).on('click', '#hkdev-otp-send-btn', function () {
        otpPhone = $('#hkdev-otp-phone-input').val().trim();
        if (!otpPhone) { $('#hkdev-otp-phone-input').focus(); return; }

        sendOtpRequest(otpPhone, $(this));
    });

    function initOTPInputs() {
        var $digits = $('.hkdev-otp-digit');
        $digits.val('').removeClass('hkdev-otp-error').first().focus();

        $digits.off('input keydown').on('input', function () {
            var $t  = $(this);
            $t.removeClass('hkdev-otp-error');
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

    function getOtpCode() {
        return $('.hkdev-otp-digit').map(function () { return $(this).val(); }).get().join('');
    }

    function checkOTPPreview() {
        var code = getOtpCode();
        if (code.length !== otpLength) {
            $('.hkdev-otp-digit').addClass('hkdev-otp-error');
            alert('Please enter the full OTP code.');
            return;
        }
        verifyOtp(code);
    }

    function verifyOtp(code) {
        if (!otpPhone || otpVerifyInFlight) {
            return;
        }

        otpVerifyInFlight = true;
        var $btn = $('#hkdev-otp-verify-btn');
        var originalText = $btn.text();
        $btn.text('Verifying…').prop('disabled', true);

        $.post(hkdevAjax.ajaxUrl, {
            action: 'hkdev_verify_otp',
            nonce: hkdevAjax.otpNonce,
            phone: otpPhone,
            otp: code
        }).done(function (res) {
            res = hkdevNormalizeAjaxResponse(res);
            if (res && res.success) {
                clearInterval(otpTimer);
                showOTPStep(3);
                return;
            }
            var errorMessage = hkdevResolveMessage(res ? res.data : null) || 'Invalid OTP. Please try again.';
            $('.hkdev-otp-digit').addClass('hkdev-otp-error');
            alert(errorMessage);
        }).fail(function () {
            $('.hkdev-otp-digit').addClass('hkdev-otp-error');
            alert('Failed to verify OTP');
        }).always(function () {
            otpVerifyInFlight = false;
            $btn.text(originalText).prop('disabled', false);
        });
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
        sendOtpRequest(otpPhone, $(this));
    });

});
