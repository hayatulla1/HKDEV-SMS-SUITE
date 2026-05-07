jQuery(function ($) {
    const config = window.uspSmsData || {};
    const messages = config.messages || {};
    let isVerified = Boolean(config.isVerified);
    let timerId = null;
    let secondsRemaining = 0;
    const $verifyButton = $('#sib_verify');
    const defaultVerifyText = $verifyButton.text();
    const $timer = $('#sib_otp_timer');

    function clearTimer() {
        if (timerId) {
            window.clearInterval(timerId);
            timerId = null;
        }
    }

    function formatTime(totalSeconds) {
        const safeSeconds = Math.max(0, parseInt(totalSeconds, 10) || 0);
        const minutes = Math.floor(safeSeconds / 60);
        const seconds = safeSeconds % 60;
        return minutes + ':' + String(seconds).padStart(2, '0');
    }

    function renderTimer() {
        if (!$timer.length) {
            return;
        }

        if (secondsRemaining <= 0) {
            $timer.text(messages.otpExpired || 'OTP expired. Please request a new one.');
            return;
        }

        const template = messages.otpExpiresIn || 'Code expires in %s';
        $timer.text(template.replace('%s', formatTime(secondsRemaining)));
    }

    function startTimer(seconds) {
        clearTimer();
        secondsRemaining = parseInt(seconds, 10) || 0;
        renderTimer();

        if (secondsRemaining <= 0) {
            return;
        }

        timerId = window.setInterval(function () {
            secondsRemaining -= 1;
            renderTimer();

            if (secondsRemaining <= 0) {
                clearTimer();
            }
        }, 1000);
    }

    function clearTimerDisplay() {
        $timer.text('');
    }

    function setMessage(message, type) {
        const $message = $('#sib_msg');
        $message.removeClass('is-error is-success is-info');

        if (!message) {
            $message.text('');
            return;
        }

        $message.text(message);
        if (type === 'success') {
            $message.addClass('is-success');
            return;
        }
        if (type === 'info') {
            $message.addClass('is-info');
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
        setVerifyButtonState(true, defaultVerifyText);

        const phone = $('#billing_phone').val();
        const normalizedPhone = String(phone || '').replace(/\D+/g, '');

        if (!normalizedPhone) {
            setMessage(messages.phoneRequired, 'error');
            setVerifyButtonState(false, defaultVerifyText);
            return;
        }

        setMessage(messages.sendingOtp, 'info');
        clearTimerDisplay();

        $.post(config.ajaxUrl, {
            action: 'sib_send_otp',
            phone,
            nonce: config.nonce,
        }, function (response) {
            if (response.success) {
                setMessage(messages.otpSent, 'success');
                startTimer(config.expirySeconds);
                return;
            }

            setMessage(response.data || messages.sendFailed, 'error');
        }).fail(function () {
            setMessage(messages.sendFailed, 'error');
        }).always(function () {
            setVerifyButtonState(false, defaultVerifyText);
        });
    });

    $('#sib_verify').on('click', function () {
        const otp = $('#sib_otp_code').val();
        const normalizedOtp = String(otp || '').replace(/\D+/g, '');

        if (!normalizedOtp) {
            setMessage(messages.invalidOtp, 'error');
            return;
        }

        setVerifyButtonState(true, messages.verifyingOtp || defaultVerifyText);

        $.post(config.ajaxUrl, {
            action: 'sib_verify_otp',
            otp: normalizedOtp,
            nonce: config.nonce,
        }, function (response) {
            if (response.success) {
                isVerified = true;
                clearTimer();
                clearTimerDisplay();
                $('#sib-otp-overlay').hide().attr('aria-hidden', 'true');
                $('form.checkout').trigger('submit');
                return;
            }

            setMessage(response.data || messages.invalidOtp, 'error');
        }).fail(function () {
            setMessage(messages.invalidOtp, 'error');
        }).always(function () {
            if (!isVerified) {
                setVerifyButtonState(false, defaultVerifyText);
            }
        });
    });
});
