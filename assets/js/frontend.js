document.addEventListener('DOMContentLoaded', function() {
    const overlay = document.getElementById('hkdev-otp-modal');
    if (!overlay) return;

    const form = document.getElementById('hkdev-otp-form');
    const inputContainer = document.getElementById('hkdev-otp-inputs');
    const btnVerify = document.getElementById('hkdev-btn-verify');
    const btnText = document.getElementById('hkdev-btn-verify-text');
    const errorBox = document.getElementById('hkdev-modal-error');
    const phoneInput = document.getElementById('hkdev-phone-input');

    // Config from wp_localize_script
    const OTP_LENGTH = window.hkdevFrontendAjax ? parseInt(hkdevFrontendAjax.otpLength) : 6;
    const COOLDOWN = window.hkdevFrontendAjax ? parseInt(hkdevFrontendAjax.cooldown) : 60;
    let timerInterval;
    let otpVerifiedForCurrentPhone = false;
    let pendingCheckoutForm = null;

    // Generate OTP input boxes
    for (let i = 0; i < OTP_LENGTH; i++) {
        const input = document.createElement('input');
        input.type = 'text';
        input.inputMode = 'numeric';
        input.maxLength = 1;
        input.className = 'otp-input-box';
        input.required = true;
        input.dataset.index = i;
        inputContainer.appendChild(input);
    }

    const inputs = document.querySelectorAll('.otp-input-box');

    // Make modal opener globally available
    function getBillingPhone(formElement) {
        const $formPhone = formElement ? jQuery(formElement).find('input[name="billing_phone"]').first() : jQuery();
        if ($formPhone.length && $formPhone.val()) {
            return $formPhone.val();
        }

        const $globalPhone = jQuery('input[name="billing_phone"]').first();
        return $globalPhone.length ? $globalPhone.val() : '';
    }

    window.openHKDEVModal = function(formElement) {
        if (overlay.classList.contains('active')) {
            return;
        }

        const phone = getBillingPhone(formElement);
        if (!phone) {
            alert('Please enter your phone number first.');
            return;
        }

        pendingCheckoutForm = formElement || pendingCheckoutForm;
        phoneInput.value = phone;
        overlay.classList.add('active');
        setTimeout(() => inputs[0].focus(), 100);

        // Send OTP
        jQuery.post(hkdevFrontendAjax.ajaxUrl, {
            action: 'hkdev_send_otp',
            nonce: hkdevFrontendAjax.nonce,
            phone: phone
        }, function(res) {
            if (res.success) {
                startTimer(COOLDOWN);
                errorBox.style.display = 'none';
            } else {
                showError(res.data || 'Failed to send OTP');
            }
        });
    };

    // Close modal
    document.getElementById('hkdev-close-modal').addEventListener('click', function(e) {
        e.preventDefault();
        overlay.classList.remove('active');
        clearInterval(timerInterval);
        resetForm();
        pendingCheckoutForm = null;
    });

    // Input handling
    inputs.forEach((input, index) => {
        input.addEventListener('input', function() {
            this.value = this.value.replace(/[^0-9]/g, '');
            errorBox.style.display = 'none';

            if (this.value !== '' && index < inputs.length - 1) {
                inputs[index + 1].focus();
            }

            checkFormComplete();
        });

        input.addEventListener('keydown', function(e) {
            if (e.key === 'Backspace' && this.value === '' && index > 0) {
                inputs[index - 1].focus();
            }
        });

        input.addEventListener('paste', function(e) {
            e.preventDefault();
            const pastedData = e.clipboardData.getData('text').replace(/[^0-9]/g, '').slice(0, OTP_LENGTH);
            if (!pastedData) return;

            let focusIndex = index;
            for (let i = 0; i < pastedData.length; i++) {
                if (index + i < inputs.length) {
                    inputs[index + i].value = pastedData[i];
                    focusIndex = index + i;
                }
            }

            if (focusIndex < inputs.length - 1) {
                inputs[focusIndex + 1].focus();
            } else {
                inputs[focusIndex].blur();
            }

            checkFormComplete();
        });
    });

    function checkFormComplete() {
        const isComplete = Array.from(inputs).every(input => input.value.length === 1);

        if (isComplete) {
            btnVerify.disabled = false;
            btnVerify.classList.add('active');
        } else {
            btnVerify.disabled = true;
            btnVerify.classList.remove('active');
        }
    }

    function startTimer(seconds) {
        clearInterval(timerInterval);
        const timerDisplay = document.getElementById('hkdev-countdown');
        const timerWrapper = document.getElementById('hkdev-timer-wrapper');
        const resendBtn = document.getElementById('hkdev-btn-resend');

        timerWrapper.style.display = 'block';
        resendBtn.style.display = 'none';

        let timeLeft = seconds;
        timerDisplay.textContent = `00:${timeLeft.toString().padStart(2, '0')}`;

        timerInterval = setInterval(() => {
            timeLeft--;
            timerDisplay.textContent = `00:${timeLeft.toString().padStart(2, '0')}`;

            if (timeLeft <= 0) {
                clearInterval(timerInterval);
                timerWrapper.style.display = 'none';
                resendBtn.style.display = 'block';
            }
        }, 1000);
    }

    document.getElementById('hkdev-btn-resend').addEventListener('click', function(e) {
        e.preventDefault();
        resetForm();

        const phone = phoneInput.value;
        if (!phone) return;

        jQuery.post(hkdevFrontendAjax.ajaxUrl, {
            action: 'hkdev_send_otp',
            nonce: hkdevFrontendAjax.nonce,
            phone: phone
        }, function(res) {
            if (res.success) {
                startTimer(COOLDOWN);
                errorBox.style.display = 'none';
            } else {
                showError(res.data || 'Failed to resend OTP');
            }
        });
    });

    function showError(msg) {
        errorBox.textContent = msg;
        errorBox.style.display = 'block';
    }

    function resetForm() {
        inputs.forEach(input => input.value = '');
        inputs[0].focus();
        checkFormComplete();
    }

    form.addEventListener('submit', function(e) {
        e.preventDefault();

        const otpCode = Array.from(inputs).map(i => i.value).join('');
        const phone = phoneInput.value;

        if (!otpCode || !phone) {
            showError('Please enter OTP and phone number');
            return;
        }

        btnVerify.disabled = true;
        btnText.innerHTML = 'Verifying...';

        jQuery.post(hkdevFrontendAjax.ajaxUrl, {
            action: 'hkdev_verify_otp',
            nonce: hkdevFrontendAjax.nonce,
            otp: otpCode,
            phone: phone
        }, function(res) {
            if (res.success) {
                otpVerifiedForCurrentPhone = true;
                btnText.innerHTML = '<i class="ph-bold ph-check"></i> Verified!';
                btnVerify.classList.remove('active');
                btnVerify.classList.add('success');

                setTimeout(() => {
                    overlay.classList.remove('active');
                    const $targetForm = pendingCheckoutForm ? jQuery(pendingCheckoutForm) : jQuery('form.checkout, form.woocommerce-checkout').first();
                    pendingCheckoutForm = null;

                    if ($targetForm.length) {
                        $targetForm.trigger('submit');
                    }
                }, 1000);
            } else {
                showError(res.data || 'Invalid OTP. Please try again.');
                btnVerify.disabled = false;
                btnText.innerHTML = 'Verify & Complete Order';
                resetForm();
            }
        });
    });

    // Reset local verification state if phone changes
    jQuery(document).on('change', 'input[name="billing_phone"]', function() {
        otpVerifiedForCurrentPhone = false;
    });

    function shouldInterceptCheckoutSubmit(formElement) {
        if (!window.hkdevFrontendAjax || !formElement) {
            return false;
        }

        return jQuery(formElement).find('input[name="billing_phone"]').length > 0;
    }

    // Intercept checkout submit for WooCommerce and funnel checkout forms
    jQuery(document).on('submit', 'form', function(e) {
        if (!shouldInterceptCheckoutSubmit(this)) {
            return;
        }

        if (overlay.classList.contains('active')) {
            e.preventDefault();
            return false;
        }

        if (otpVerifiedForCurrentPhone) {
            return;
        }

        e.preventDefault();
        pendingCheckoutForm = this;
        window.openHKDEVModal(this);
        return false;
    });

    // Intercept common place-order buttons used by checkout/funnel builders
    jQuery(document).on('click', '#place_order, [name="woocommerce_checkout_place_order"], .wcf-submit-checkout, .wcf-next-btn', function(e) {
        const $form = jQuery(this).closest('form');
        if (!$form.length || !shouldInterceptCheckoutSubmit($form[0]) || otpVerifiedForCurrentPhone) {
            return;
        }

        e.preventDefault();
        e.stopImmediatePropagation();
        pendingCheckoutForm = $form[0];
        window.openHKDEVModal($form[0]);
        return false;
    });
});
