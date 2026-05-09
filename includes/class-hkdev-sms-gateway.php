<?php

if (!defined('ABSPATH')) {
    exit;
}

class HKDEV_SMS_Gateway {
    
    private $gateway_url;
    private $api_token;
    private $sender_id;
    private $http_method;
    private $param_token;
    private $param_sender;
    private $param_number;
    private $param_msg;

    public function __construct() {
        $this->gateway_url = get_option('sib_gateway_url', '');
        $this->api_token = get_option('sib_api_token', '');
        $this->sender_id = get_option('sib_sender_id', '');
        $this->http_method = get_option('sib_http_method', 'GET');
        $this->param_token = get_option('sib_param_token', 'token');
        $this->param_sender = get_option('sib_param_sender', 'sender');
        $this->param_number = get_option('sib_param_number', 'number');
        $this->param_msg = get_option('sib_param_msg', 'message');
    }

    public function is_configured() {
        return !empty($this->gateway_url) && !empty($this->api_token);
    }

    public function send_sms($phone_number, $message) {
        if (!$this->is_configured()) {
            return array(
                'success' => false,
                'message' => __('SMS Gateway not configured', HKDEV_TEXT_DOMAIN)
            );
        }

        if (!hkdev_option_is_enabled('hkdev_enable_gateway', 'yes')) {
            return array(
                'success' => false,
                'message' => __('SMS Gateway is disabled', HKDEV_TEXT_DOMAIN)
            );
        }

        // Sanitize phone number
        $phone_number = preg_replace('/[^0-9+]/', '', $phone_number);

        // Build request
        $request_data = array(
            $this->param_token => $this->api_token,
            $this->param_sender => $this->sender_id,
            $this->param_number => $phone_number,
            $this->param_msg => $message
        );

        if ($this->http_method === 'POST') {
            return $this->send_post_request($request_data);
        } else {
            return $this->send_get_request($request_data);
        }
    }

    private function send_get_request($data) {
        $url = add_query_arg($data, $this->gateway_url);
        $sslverify = apply_filters('hkdev_sms_sslverify', true);

        $response = wp_remote_get(
            $url,
            array(
                'timeout' => 10,
                'sslverify' => $sslverify,
                'user-agent' => 'HKDEV-SMS-Suite/' . HKDEV_PLUGIN_VERSION
            )
        );

        return $this->handle_response($response);
    }

    private function send_post_request($data) {
        $sslverify = apply_filters('hkdev_sms_sslverify', true);

        $response = wp_remote_post(
            $this->gateway_url,
            array(
                'method' => 'POST',
                'timeout' => 10,
                'sslverify' => $sslverify,
                'user-agent' => 'HKDEV-SMS-Suite/' . HKDEV_PLUGIN_VERSION,
                'body' => $data
            )
        );

        return $this->handle_response($response);
    }

    private function handle_response($response) {
        if (is_wp_error($response)) {
            $this->log_sms(array(
                'status' => 'error',
                'message' => $response->get_error_message(),
                'timestamp' => current_time('Y-m-d H:i:s')
            ));

            return array(
                'success' => false,
                'message' => $response->get_error_message()
            );
        }

        $body = wp_remote_retrieve_body($response);
        $http_code = wp_remote_retrieve_response_code($response);

        if ($http_code >= 200 && $http_code < 300) {
            $this->log_sms(array(
                'status' => 'success',
                'message' => 'SMS sent successfully',
                'timestamp' => current_time('Y-m-d H:i:s')
            ));

            return array(
                'success' => true,
                'message' => __('SMS sent successfully', HKDEV_TEXT_DOMAIN),
                'response' => $body
            );
        } else {
            $this->log_sms(array(
                'status' => 'error',
                'message' => "HTTP $http_code: $body",
                'timestamp' => current_time('Y-m-d H:i:s')
            ));

            return array(
                'success' => false,
                'message' => __('Failed to send SMS', HKDEV_TEXT_DOMAIN),
                'error' => $body
            );
        }
    }

    public function check_balance() {
        $balance_api_url = get_option('hkdev_balance_api_url', '');
        $response_key    = get_option('hkdev_balance_response_key', 'balance');

        if (empty($balance_api_url)) {
            return array(
                'success' => false,
                'message' => __('Balance API URL not configured', HKDEV_TEXT_DOMAIN)
            );
        }

        $response = wp_remote_get(
            $balance_api_url,
            array(
                'timeout'    => 10,
                'sslverify'  => apply_filters('hkdev_sms_sslverify', true),
                'user-agent' => 'HKDEV-SMS-Suite/' . HKDEV_PLUGIN_VERSION,
            )
        );

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message()
            );
        }

        $raw_body = wp_remote_retrieve_body($response);
        $body     = json_decode($raw_body, true);
        $balance  = $this->resolve_balance_value($raw_body, $body, $response_key);

        if ($balance !== null) {
            update_option('hkdev_balance_cache', array(
                'amount'     => $balance,
                'checked_at' => current_time('Y-m-d H:i:s')
            ));

            return array(
                'success' => true,
                'amount'  => $balance
            );
        }

        return array(
            'success' => false,
            'message' => __('Could not parse balance response. Please check the Balance Response Key setting.', HKDEV_TEXT_DOMAIN)
        );
    }

    private function resolve_balance_value($raw_body, $decoded_body, $response_key) {
        if (is_array($decoded_body)) {
            if (is_string($response_key) && $response_key !== '') {
                $configured_value = $this->get_array_value_by_path($decoded_body, $response_key);
                if ($configured_value !== null) {
                    $normalized_configured = $this->normalize_balance_scalar($configured_value);
                    if ($normalized_configured !== null) {
                        return $normalized_configured;
                    }
                }
            }

            $fallback_keys = array('balance', 'Balance', 'credit', 'Credit', 'remaining', 'amount', 'sms', 'mask');
            foreach ($fallback_keys as $fallback_key) {
                $fallback_value = $this->find_value_by_key_recursive($decoded_body, $fallback_key);
                if ($fallback_value !== null) {
                    $normalized_fallback = $this->normalize_balance_scalar($fallback_value);
                    if ($normalized_fallback !== null) {
                        return $normalized_fallback;
                    }
                }
            }
        }

        return $this->normalize_balance_scalar($raw_body);
    }

    private function get_array_value_by_path($data, $path) {
        if (!is_array($data) || !is_string($path) || $path === '') {
            return null;
        }

        $segments = explode('.', $path);
        $current = $data;
        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    private function find_value_by_key_recursive($data, $target_key) {
        if (!is_array($data)) {
            return null;
        }

        if (array_key_exists($target_key, $data)) {
            return $data[$target_key];
        }

        foreach ($data as $value) {
            if (!is_array($value)) {
                continue;
            }
            $found = $this->find_value_by_key_recursive($value, $target_key);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function normalize_balance_scalar($value) {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('/[-+]?\d+(?:\.\d+)?/', str_replace(',', '', $trimmed), $matches)) {
            return $matches[0];
        }

        return null;
    }

    private function log_sms($log_data) {
        if (!hkdev_option_is_enabled('hkdev_enable_logs', 'yes')) {
            return;
        }

        $logs = get_option('sib_sms_logs', array());
        
        if (!is_array($logs)) {
            $logs = array();
        }

        array_unshift($logs, $log_data);
        
        // Keep only last 1000 logs
        $logs = array_slice($logs, 0, 1000);
        
        update_option('sib_sms_logs', $logs);
    }

    public function get_logs() {
        return get_option('sib_sms_logs', array());
    }

    public function clear_logs() {
        update_option('sib_sms_logs', array());
    }
}
