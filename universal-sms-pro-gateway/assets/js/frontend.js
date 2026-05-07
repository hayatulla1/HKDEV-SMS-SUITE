jQuery(function ($) {
    let isVerified = Boolean(uspSmsData.isVerified);

    $('form.checkout').on('submit', function (e) {
        if (isVerified) {
            return;
        }

        e.preventDefault();
        $('#sib-otp-overlay').css('display', 'flex').attr('aria-hidden', 'false');

        const phone = $('#billing_phone').val();

        $.post(uspSmsData.ajaxUrl, {
            action: 'sib_send_otp',
            phone,
            nonce: uspSmsData.nonce,
        }, function (response) {
            if (!response.success) {
                $('#sib_msg').text(response.data || uspSmsData.messages.sendFailed);
            }
        }).fail(function () {
            $('#sib_msg').text(uspSmsData.messages.sendFailed);
        });
    });

    $('#sib_verify').on('click', function () {
        const otp = $('#sib_otp_code').val();

        $.post(uspSmsData.ajaxUrl, {
            action: 'sib_verify_otp',
            otp,
            nonce: uspSmsData.nonce,
        }, function (response) {
            if (response.success) {
                isVerified = true;
                $('#sib-otp-overlay').hide().attr('aria-hidden', 'true');
                $('form.checkout').trigger('submit');
                return;
            }

            $('#sib_msg').text(response.data || uspSmsData.messages.invalidOtp);
        }).fail(function () {
            $('#sib_msg').text(uspSmsData.messages.invalidOtp);
        });
    });
});
