<?php

if (!defined('ABSPATH')) {
    exit;
}

class HKDEV_OTP_Handler {
    
    private $otp_length;
    private $expiry_minutes;
    private $otp_transient_key = 'hkdev_otp_';
    private $otp_attempts_key = 'hkdev_otp_attempts_';
    private $otp_cooldown_seconds;

    public function __construct() {
        $this->otp_length = intval(get_option('hkdev_otp_length', 6));
        $this->expiry_minutes = intval(get_option('hkdev_otp_expiry_minutes', 10));
        $this->otp_cooldown_seconds = intval(get_option('hkdev_otp_cooldown_seconds', 60));
    }

    public function generate_otp($phone_number) {
        // Sanitize phone number
        $phone_hash = md5($phone_number);
        
        // Check cooldown
        $last_attempt = get_transient($this->otp_cooldown_seconds . '_' . $phone_hash);
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
        
        // Set cooldown
        set_transient($this->otp_cooldown_seconds . '_' . $phone_hash, true, $this->otp_cooldown_seconds);

        return array(
            'success' => true,
            'otp' => $otp,
            'expiry_minutes' => $this->expiry_minutes,
            'message' => __('OTP generated successfully', HKDEV_TEXT_DOMAIN)
        );
    }

    public function verify_otp($phone_number, $otp_code) {
        $phone_hash = md5($phone_number);
        $transient_key = $this->otp_transient_key . $phone_hash;
        
        $stored_otp = get_transient($transient_key);

        if (!$stored_otp) {
            return array(
                'success' => false,
                'message' => __('OTP has expired. Please request a new one.', HKDEV_TEXT_DOMAIN)
            );
        }

        if ($stored_otp !== $otp_code) {
            return array(
                'success' => false,
                'message' => __('Invalid OTP. Please try again.', HKDEV_TEXT_DOMAIN)
            );
        }

        // OTP verified, delete it
        delete_transient($transient_key);
        
        // Store verified phone in session/option
        set_transient('hkdev_verified_phone_' . md5(wp_get_current_user()->ID), $phone_number, HOUR_IN_SECONDS);

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
        $user_id = wp_get_current_user()->ID;
        if (!$user_id) {
            return false;
        }

        $verified = get_transient('hkdev_verified_phone_' . md5($user_id));
        return $verified === $phone_number;
    }

    public function get_otp_config() {
        return array(
            'length' => $this->otp_length,
            'expiry_minutes' => $this->expiry_minutes,
            'cooldown_seconds' => $this->otp_cooldown_seconds
        );
    }
}
