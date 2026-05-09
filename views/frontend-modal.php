<?php if (!defined('ABSPATH')) {
    exit;
} ?>

<div class="hkdev-modal-overlay" id="hkdev-otp-modal">
    <div class="otp-modal">
        <button class="modal-close" id="hkdev-close-modal" aria-label="Close">
            <i class="ph ph-x"></i>
        </button>

        <div class="modal-icon-top">
            <i class="ph-fill ph-device-mobile"></i>
        </div>
        <h3 class="modal-title"><?php _e('Phone Verification Required', HKDEV_TEXT_DOMAIN); ?></h3>
        <p class="modal-desc">
            <?php _e('Verify your phone to complete order', HKDEV_TEXT_DOMAIN); ?>
        </p>

        <div class="modal-error-alert" id="hkdev-modal-error"></div>

        <form id="hkdev-otp-form">
            <input type="hidden" name="phone" id="hkdev-phone-input">
            
            <div class="otp-input-group" id="hkdev-otp-inputs">
                <!-- Inputs generated dynamically by JS based on length setting -->
            </div>

            <button type="submit" class="btn-verify-full" id="hkdev-btn-verify" disabled>
                <i class="ph-bold ph-shield-check"></i>
                <span id="hkdev-btn-verify-text"><?php _e('Verify & Continue Order', HKDEV_TEXT_DOMAIN); ?></span>
            </button>
        </form>

        <div class="resend-text" id="hkdev-timer-wrapper" style="display:none;">
            <?php _e('Resend code in', HKDEV_TEXT_DOMAIN); ?>
            <span class="resend-timer" id="hkdev-countdown"></span>
        </div>
        <button class="resend-link" id="hkdev-btn-resend" type="button">
            <?php _e('Resend OTP', HKDEV_TEXT_DOMAIN); ?>
        </button>
    </div>
</div>
