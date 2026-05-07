jQuery(function ($) {
    const config = window.hkdevAdminData || {};
    const messages = config.messages || {};
    const ajaxUrl = config.ajaxUrl || '';
    const nonce = config.nonce || '';

    function setStatus($el, text, isError) {
        if (!$el || !$el.length) {
            return;
        }

        $el.text(text || '');
        $el.css('color', isError ? '#b91c1c' : '#047857');
    }

    $('#hkdev-refresh-balance').on('click', function () {
        const $button = $(this);
        const $status = $('#hkdev-balance-status');
        const $value = $('#hkdev-balance-value');
        const $time = $('#hkdev-balance-time');

        if (!ajaxUrl || !nonce) {
            setStatus($status, messages.balanceError || 'Unable to fetch balance.', true);
            return;
        }

        $button.prop('disabled', true);
        setStatus($status, messages.balanceLoading || 'Checking balance...', false);

        $.post(ajaxUrl, {
            action: 'hkdev_check_balance',
            nonce: nonce,
        }).done(function (response) {
            if (!response || !response.success || !response.data) {
                setStatus($status, (response && response.data) || messages.balanceError || 'Unable to fetch balance.', true);
                return;
            }

            $value.text(response.data.amount || '');
            if (response.data.checked_at) {
                $time.text(response.data.checked_at);
            }
            setStatus($status, messages.balanceSuccess || 'Balance updated.', false);
        }).fail(function () {
            setStatus($status, messages.balanceError || 'Unable to fetch balance.', true);
        }).always(function () {
            $button.prop('disabled', false);
        });
    });

    $('#hkdev-test-sms').on('click', function () {
        const $button = $(this);
        const $status = $('#hkdev-test-status');
        const $phone = $('#hkdev-test-phone');

        if (!ajaxUrl || !nonce) {
            setStatus($status, messages.balanceError || 'Unable to process request.', true);
            return;
        }

        $button.prop('disabled', true);
        setStatus($status, messages.testSending || 'Sending test SMS...', false);

        $.post(ajaxUrl, {
            action: 'hkdev_test_sms',
            phone: $phone.val(),
            nonce: nonce,
        }).done(function (response) {
            if (!response || !response.success) {
                setStatus($status, (response && response.data) || messages.balanceError || 'Failed to send test SMS.', true);
                return;
            }

            setStatus($status, response.data || messages.testSent || 'Test SMS sent.', false);
        }).fail(function () {
            setStatus($status, messages.balanceError || 'Failed to send test SMS.', true);
        }).always(function () {
            $button.prop('disabled', false);
        });
    });
});
