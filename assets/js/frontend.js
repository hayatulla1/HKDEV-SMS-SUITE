document.addEventListener('DOMContentLoaded', function() {
    const frontendConfig = window.hkdevFrontendAjax || {};
    const otpLengthValue = frontendConfig.otpLength ?? 6;
    const cooldownValue = frontendConfig.cooldown ?? 60;
    const parsedOtpLength = Number.parseInt(otpLengthValue, 10);
    const parsedCooldown = Number.parseInt(cooldownValue, 10);
    const OTP_LENGTH = Number.isNaN(parsedOtpLength) ? 6 : parsedOtpLength;
    const COOLDOWN = Number.isNaN(parsedCooldown) ? 60 : parsedCooldown;

    const root = document.getElementById('hkdev-otp-react-root');
    if (root && window.wp && wp.element && typeof wp.element.createElement === 'function') {
        const { createElement, createRoot, render } = wp.element;
        const otpInputs = Array.from({ length: OTP_LENGTH }).map((_, index) => (
            createElement('input', {
                key: index,
                type: 'text',
                inputMode: 'numeric',
                maxLength: 1,
                className: 'otp-input-box',
                required: true,
                'data-index': index
            })
        ));

        const modal = createElement('div', { className: 'hkdev-modal-overlay', id: 'hkdev-otp-modal' },
            createElement('div', { className: 'otp-modal' },
                createElement('button', { className: 'modal-close', id: 'hkdev-close-modal', type: 'button', 'aria-label': frontendConfig.closeLabel || 'Close' },
                    createElement('i', { className: 'ph ph-x' })
                ),
                createElement('div', { className: 'modal-icon-top' },
                    createElement('i', { className: 'ph-fill ph-device-mobile' })
                ),
                createElement('h3', { className: 'modal-title' }, frontendConfig.modalTitle || 'Phone Verification Required'),
                createElement('p', { className: 'modal-desc' }, frontendConfig.modalDescription || 'Verify your phone to complete your order'),
                createElement('div', { className: 'modal-error-alert', id: 'hkdev-modal-error' }),
                createElement('form', { id: 'hkdev-otp-form' },
                    createElement('input', { type: 'hidden', name: 'phone', id: 'hkdev-phone-input' }),
                    createElement('div', { className: 'otp-input-group', id: 'hkdev-otp-inputs' }, otpInputs),
                    createElement('button', { type: 'submit', className: 'btn-verify-full', id: 'hkdev-btn-verify', disabled: true },
                        createElement('i', { className: 'ph-bold ph-shield-check' }),
                        createElement('span', { id: 'hkdev-btn-verify-text' }, frontendConfig.verifyButtonText || 'Verify & Continue Order')
                    )
                ),
                createElement('div', { className: 'resend-text', id: 'hkdev-timer-wrapper', style: { display: 'none' } },
                    frontendConfig.resendPrefix || 'Resend code in',
                    createElement('span', { className: 'resend-timer', id: 'hkdev-countdown' })
                ),
                createElement('button', { className: 'resend-link', id: 'hkdev-btn-resend', type: 'button' }, frontendConfig.resendButtonText || 'Resend OTP')
            )
        );

        if (typeof createRoot === 'function') {
            createRoot(root).render(modal);
        } else if (typeof render === 'function') {
            render(modal, root);
        }
    }

    const overlay = document.getElementById('hkdev-otp-modal');
    if (!overlay) return;

    const form = document.getElementById('hkdev-otp-form');
    const inputContainer = document.getElementById('hkdev-otp-inputs');
    const btnVerify = document.getElementById('hkdev-btn-verify');
    const btnText = document.getElementById('hkdev-btn-verify-text');
    const defaultVerifyText = btnText ? btnText.textContent.trim() : '';
    const errorBox = document.getElementById('hkdev-modal-error');
    const phoneInput = document.getElementById('hkdev-phone-input');
    const AUTO_CLOSE_DELAY_MS = 1500;
    const verifyingText = frontendConfig.verifyingText ?? 'Verifying...';
    const verifiedText = frontendConfig.verifiedText ?? 'Verified!';
    let timerInterval;
    let verifiedPhoneNumber = '';
    let allowNextSubmission = false;
    let pendingCheckoutForm = null;

    function closeModal() {
        overlay.classList.remove('active');
        clearInterval(timerInterval);
        resetForm(false);
        errorBox.style.display = 'none';
        pendingCheckoutForm = null;
        btnVerify.classList.remove('success');
        btnText.textContent = defaultVerifyText;
    }

    // Generate OTP input boxes (fallback)
    if (inputContainer && inputContainer.children.length === 0) {
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
    }

    const inputs = document.querySelectorAll('.otp-input-box');

    // Make modal opener globally available
    function normalizePhone(phone) {
        const raw = String(phone || '').trim();
        const hasLeadingPlus = raw.charAt(0) === '+';
        const digits = raw.replace(/\D/g, '');
        if (!digits) {
            return '';
        }
        return hasLeadingPlus ? ('+' + digits) : digits;
    }

    function isVerifiedPhoneMatch(phone) {
        const normalizedPhone = normalizePhone(phone);
        const normalizedVerified = normalizePhone(verifiedPhoneNumber);
        return normalizedPhone !== '' && normalizedVerified !== '' && normalizedPhone === normalizedVerified;
    }

    function getBillingPhone(formElement) {
        const $formPhone = formElement ? jQuery(formElement).find('input[name="billing_phone"]').first() : jQuery();
        if ($formPhone.length && $formPhone.val()) {
            return $formPhone.val();
        }

        const $globalPhone = jQuery('input[name="billing_phone"]').first();
        return $globalPhone.length && $globalPhone.val() ? $globalPhone.val() : '';
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
        resetForm(false);
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
        closeModal();
    });

    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) {
            closeModal();
        }
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && overlay.classList.contains('active')) {
            closeModal();
        }
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
        errorBox.classList.remove('success');
        errorBox.textContent = msg;
        errorBox.style.display = 'block';
    }

    function showSuccess(msg) {
        errorBox.classList.add('success');
        errorBox.textContent = msg;
        errorBox.style.display = 'block';
    }

    function resetForm(shouldFocus = true) {
        inputs.forEach(input => input.value = '');
        if (shouldFocus) {
            inputs[0].focus();
        }
        errorBox.classList.remove('success');
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
        btnText.textContent = verifyingText;

        jQuery.post(hkdevFrontendAjax.ajaxUrl, {
            action: 'hkdev_verify_otp',
            nonce: hkdevFrontendAjax.nonce,
            otp: otpCode,
            phone: phone
        }, function(res) {
            if (res.success) {
                const latestPhone = getBillingPhone(pendingCheckoutForm);
                if (normalizePhone(latestPhone) !== normalizePhone(phone)) {
                    showError('Phone number changed. Please verify again.');
                    btnVerify.disabled = false;
                    btnText.textContent = defaultVerifyText;
                    resetForm();
                    return;
                }

                verifiedPhoneNumber = phone;
                allowNextSubmission = true;
                showSuccess('Phone verified successfully.');
                btnText.textContent = verifiedText;
                btnVerify.classList.remove('active');
                btnVerify.classList.add('success');

                setTimeout(() => {
                    overlay.classList.remove('active');
                    const $targetForm = pendingCheckoutForm ? jQuery(pendingCheckoutForm) : jQuery('form.checkout, form.woocommerce-checkout').first();
                    pendingCheckoutForm = null;

                    if ($targetForm.length) {
                        $targetForm.trigger('submit');
                    }
                }, AUTO_CLOSE_DELAY_MS);
            } else {
                showError(res.data || 'Invalid OTP. Please try again.');
                btnVerify.disabled = false;
                btnText.textContent = defaultVerifyText;
                resetForm();
            }
        });
    });

    // Reset local verification state if phone changes
    jQuery(document).on('change', 'input[name="billing_phone"]', function() {
        if (normalizePhone(jQuery(this).val()) !== normalizePhone(verifiedPhoneNumber)) {
            verifiedPhoneNumber = '';
            allowNextSubmission = false;
        }
    });

    function shouldInterceptCheckoutSubmit(formElement) {
        if (!window.hkdevFrontendAjax || !formElement) {
            return false;
        }

        return jQuery(formElement).find('input[name="billing_phone"]').length > 0;
    }

    function interceptCheckoutSubmission(formElement, event) {
        if (!shouldInterceptCheckoutSubmit(formElement)) {
            return false;
        }

        const e = event || null;
        if (overlay.classList.contains('active')) {
            if (e) {
                e.preventDefault();
            }
            return true;
        }

        if (allowNextSubmission && isVerifiedPhoneMatch(getBillingPhone(formElement))) {
            allowNextSubmission = false;
            return false;
        }

        if (e) {
            e.preventDefault();
            if (typeof e.stopPropagation === 'function') {
                e.stopPropagation();
            }
        }

        pendingCheckoutForm = formElement;
        window.openHKDEVModal(formElement);
        return true;
    }

    // Intercept checkout submit for WooCommerce and funnel checkout forms
    jQuery(document).on('submit', 'form.checkout, form.woocommerce-checkout, form[name="checkout"], form.wcf-checkout-form', function(e) {
        if (interceptCheckoutSubmission(this, e)) {
            return false;
        }
    });

    // Intercept common place-order buttons used by checkout/funnel builders
    jQuery(document).on('click', '#place_order, [name="woocommerce_checkout_place_order"], .wcf-submit-checkout, .wcf-next-btn', function(e) {
        const $form = jQuery(this).closest('form');
        if (!$form.length) {
            return;
        }

        if (interceptCheckoutSubmission($form[0], e)) {
            return false;
        }
    });
});
