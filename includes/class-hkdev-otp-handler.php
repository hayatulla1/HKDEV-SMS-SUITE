<?php

if (!defined('ABSPATH')) {
    exit;
}

class HKDEV_OTP_Handler {
    
    private const OTP_MAX_ATTEMPTS_DEFAULT = 5;

    private $otp_length;
    private $expiry_minutes;
    private $otp_transient_key = 'hkdev_otp_';
    private $otp_attempts_key = 'hkdev_otp_attempts_';
    private $otp_cooldown_key = 'hkdev_otp_cooldown_';
    private $otp_cooldown_seconds;
    private $otp_max_attempts;

    public function __construct() {
        $this->otp_length = intval(get_option('hkdev_otp_length', 6));
        $this->expiry_minutes = intval(get_option('hkdev_otp_expiry_minutes', 10));
        $this->otp_cooldown_seconds = intval(get_option('hkdev_otp_cooldown_seconds', 60));
        $this->otp_max_attempts = max(1, intval(apply_filters('hkdev_otp_max_attempts', self::OTP_MAX_ATTEMPTS_DEFAULT)));
    }

    public function generate_otp($phone_number) {
        $phone_number = hkdev_normalize_phone($phone_number);
        if (empty($phone_number)) {
            return array(
                'success' => false,
                'message' => __('Valid phone number is required', HKDEV_TEXT_DOMAIN)
            );
        }

        $phone_hash = md5($phone_number);
        
        // Check cooldown
        $transient_cooldown_key = $this->otp_cooldown_key . $phone_hash;
        $last_attempt = get_transient($transient_cooldown_key);
        if ($last_attempt) {
            return array(
                'success' => false,
                'message' => sprintf(
                    __('Please wait %d seconds before requesting a new OTP', HKDEV_TEXT_DOMAIN),
                    $this->otp_cooldown_seconds
                )
            );
        }

        // Generate OTP
        $otp = $this->generate_random_otp();
        
        // Store OTP with expiry
        $transient_key = $this->otp_transient_key . $phone_hash;
        set_transient($transient_key, $otp, $this->expiry_minutes * MINUTE_IN_SECONDS);
        delete_transient($this->otp_attempts_key . $phone_hash);
        
        // Set cooldown
        set_transient($transient_cooldown_key, true, $this->otp_cooldown_seconds);

        return array(
            'success' => true,
            'otp' => $otp,
            'expiry_minutes' => $this->expiry_minutes,
            'message' => __('OTP generated successfully', HKDEV_TEXT_DOMAIN)
        );
    }

    public function verify_otp($phone_number, $otp_code) {
        $phone_number = hkdev_normalize_phone($phone_number);
        if (empty($phone_number)) {
            return array(
                'success' => false,
                'message' => __('Valid phone number is required', HKDEV_TEXT_DOMAIN)
            );
        }

        $otp_code = preg_replace('/\D/', '', (string) $otp_code);
        if (empty($otp_code) || strlen($otp_code) !== $this->otp_length) {
            return array(
                'success' => false,
                'message' => __('Invalid OTP. Please try again.', HKDEV_TEXT_DOMAIN)
            );
        }
        $phone_hash = md5($phone_number);
        $transient_key = $this->otp_transient_key . $phone_hash;
        $attempt_key = $this->otp_attempts_key . $phone_hash;
        $attempts = (int) get_transient($attempt_key);

        if ($attempts >= $this->otp_max_attempts) {
            return array(
                'success' => false,
                'message' => __('Too many incorrect attempts. Please request a new OTP.', HKDEV_TEXT_DOMAIN)
            );
        }
        
        $stored_otp = get_transient($transient_key);

        if (!$stored_otp) {
            return array(
                'success' => false,
                'message' => __('OTP has expired. Please request a new one.', HKDEV_TEXT_DOMAIN)
            );
        }

        if (!hash_equals((string) $stored_otp, (string) $otp_code)) {
            set_transient($attempt_key, $attempts + 1, $this->expiry_minutes * MINUTE_IN_SECONDS);
            return array(
                'success' => false,
                'message' => __('Invalid OTP. Please try again.', HKDEV_TEXT_DOMAIN)
            );
        }

        // OTP verified, delete it
        delete_transient($transient_key);
        delete_transient($attempt_key);
        
        // Store verified phone in session/option
        $user_id = get_current_user_id();
        if ($user_id) {
            set_transient('hkdev_verified_phone_' . md5($user_id), $phone_number, HOUR_IN_SECONDS);
        } elseif (function_exists('WC') && WC()->session) {
            WC()->session->set('hkdev_verified_phone', $phone_number);
        }

        return array(
            'success' => true,
            'message' => __('OTP verified successfully', HKDEV_TEXT_DOMAIN)
        );
    }

    private function generate_random_otp() {
        $min = pow(10, $this->otp_length - 1);
        $max = pow(10, $this->otp_length) - 1;
        return str_pad(random_int($min, $max), $this->otp_length, '0', STR_PAD_LEFT);
    }

    public function is_phone_verified($phone_number) {
        $phone_number = hkdev_normalize_phone($phone_number);
        if (empty($phone_number)) {
            return false;
        }

        $user_id = get_current_user_id();
        if ($user_id) {
            $verified = get_transient('hkdev_verified_phone_' . md5($user_id));
            return $verified === $phone_number;
        }

        if (function_exists('WC') && WC()->session) {
            $verified = WC()->session->get('hkdev_verified_phone');
            return $verified === $phone_number;
        }

        return false;
    }

    public function get_otp_config() {
        return array(
            'length' => $this->otp_length,
            'expiry_minutes' => $this->expiry_minutes,
            'cooldown_seconds' => $this->otp_cooldown_seconds
        );
    }

}
