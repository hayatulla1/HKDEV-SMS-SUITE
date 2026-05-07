jQuery(function ($) {
    let isVerified = Boolean(uspSmsData.isVerified);
    const $verifyButton = $('#sib_verify');
    const defaultVerifyText = $verifyButton.text();

    function setMessage(message, type) {
        const $message = $('#sib_msg');
        $message.removeClass('is-error is-success');

        if (!message) {
            $message.text('');
            return;
        }

        $message.text(message);
        if (type === 'success') {
            $message.addClass('is-success');
            return;
        }

        $message.addClass('is-error');
    }

    function setVerifyButtonState(isBusy, text) {
        $verifyButton
            .prop('disabled', isBusy)
            .attr('aria-busy', isBusy ? 'true' : 'false')
            .text(text);
    }

    $('form.checkout').on('submit', function (e) {
        if (isVerified) {
            return;
        }

        e.preventDefault();
        $('#sib-otp-overlay').css('display', 'flex').attr('aria-hidden', 'false');
        setMessage('');
        setVerifyButtonState(true, uspSmsData.messages.sendingOtp || defaultVerifyText);

        const phone = $('#billing_phone').val();
        const normalizedPhone = String(phone || '').replace(/\D+/g, '');

        if (!normalizedPhone) {
            setMessage(uspSmsData.messages.phoneRequired, 'error');
            setVerifyButtonState(false, defaultVerifyText);
            return;
        }

        $.post(uspSmsData.ajaxUrl, {
            action: 'sib_send_otp',
            phone,
            nonce: uspSmsData.nonce,
        }, function (response) {
            if (response.success) {
                setMessage(uspSmsData.messages.otpSent, 'success');
                return;
            }

            setMessage(response.data || uspSmsData.messages.sendFailed, 'error');
        }).fail(function () {
            setMessage(uspSmsData.messages.sendFailed, 'error');
        }).always(function () {
            setVerifyButtonState(false, defaultVerifyText);
        });
    });

    $('#sib_verify').on('click', function () {
        const otp = $('#sib_otp_code').val();
        const normalizedOtp = String(otp || '').replace(/\D+/g, '');

        if (!normalizedOtp) {
            setMessage(uspSmsData.messages.invalidOtp, 'error');
            return;
        }

        setVerifyButtonState(true, uspSmsData.messages.verifyingOtp || defaultVerifyText);

        $.post(uspSmsData.ajaxUrl, {
            action: 'sib_verify_otp',
            otp: normalizedOtp,
            nonce: uspSmsData.nonce,
        }, function (response) {
            if (response.success) {
                isVerified = true;
                $('#sib-otp-overlay').hide().attr('aria-hidden', 'true');
                $('form.checkout').trigger('submit');
                return;
            }

            setMessage(response.data || uspSmsData.messages.invalidOtp, 'error');
        }).fail(function () {
            setMessage(uspSmsData.messages.invalidOtp, 'error');
        }).always(function () {
            if (!isVerified) {
                setVerifyButtonState(false, defaultVerifyText);
            }
        });
    });
});
