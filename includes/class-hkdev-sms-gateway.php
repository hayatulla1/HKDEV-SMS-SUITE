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

        // Resolve balance value: try the configured key first, then common fallbacks
        $balance = null;
        if (is_array($body)) {
            if ($response_key !== '' && array_key_exists($response_key, $body)) {
                $balance = $body[$response_key];
            } else {
                // Auto-detect: look for the first numeric value in common balance keys
                // 'mask' is used by several Bangladeshi SMS gateways (e.g. bdbulksms)
                $fallback_keys = array('balance', 'Balance', 'credit', 'Credit', 'remaining', 'amount', 'sms', 'mask');
                foreach ($fallback_keys as $key) {
                    if (array_key_exists($key, $body) && is_numeric($body[$key])) {
                        $balance = $body[$key];
                        break;
                    }
                }
            }
        } elseif (is_numeric(trim($raw_body))) {
            // Plain-text numeric response (e.g. "1234")
            $balance = trim($raw_body);
        }

        if ($balance !== null) {
            // Ensure we store only a scalar (not a nested array)
            $balance = is_scalar($balance) ? $balance : json_encode($balance);
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
