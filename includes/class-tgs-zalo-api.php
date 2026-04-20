<?php
/**
 * Zalo API wrapper - handles ZNS message sending and status checks
 */

if (!defined('ABSPATH')) exit;

class TGS_Zalo_API {

    /**
     * Send a ZNS message to a phone number
     *
     * @param string $phone Phone number (format: 84xxxxxxxxx)
     * @param string $zalo_template_id Zalo template ID
     * @param array  $template_data Key-value template data
     * @param string $tracking_id Optional tracking ID
     * @return array|WP_Error Zalo response or error
     */
    public static function send_zns_message($phone, $zalo_template_id, $template_data, $tracking_id = '') {
        $access_token = TGS_Zalo_Token_Manager::get_access_token();
        if (is_wp_error($access_token)) {
            return $access_token;
        }

        $payload = [
            'phone'         => $phone,
            'template_id'   => $zalo_template_id,
            'template_data' => $template_data,
        ];

        if (!empty($tracking_id)) {
            $payload['tracking_id'] = $tracking_id;
        }

        // Development mode
        if (get_site_option('tgs_zalo_dev_mode', 0)) {
            $payload['mode'] = 'development';
        }

        $response = wp_remote_post(TGS_ZALO_ZNS_URL, [
            'timeout' => 30,
            'headers' => [
                'access_token'  => $access_token,
                'Content-Type'  => 'application/json',
            ],
            'body' => wp_json_encode($payload),
        ]);

        if (is_wp_error($response)) {
            error_log('[TGS Zalo API] HTTP error: ' . $response->get_error_message());
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $error_code = intval($body['error'] ?? -1);

        // Token expired - try refresh and retry once
        if ($error_code === -216 || $error_code === -214) {
            error_log('[TGS Zalo API] Token expired, attempting refresh...');
            $new_token = TGS_Zalo_Token_Manager::refresh_token();

            if (is_wp_error($new_token)) {
                return $new_token;
            }

            // Retry with new token
            $response = wp_remote_post(TGS_ZALO_ZNS_URL, [
                'timeout' => 30,
                'headers' => [
                    'access_token'  => $new_token,
                    'Content-Type'  => 'application/json',
                ],
                'body' => wp_json_encode($payload),
            ]);

            if (is_wp_error($response)) {
                return $response;
            }

            $body = json_decode(wp_remote_retrieve_body($response), true);
            $error_code = intval($body['error'] ?? -1);
        }

        if ($error_code !== 0) {
            $error_msg = $body['message'] ?? 'Unknown Zalo API error';
            error_log("[TGS Zalo API] Send failed [{$error_code}]: {$error_msg}");
            return new WP_Error('zalo_api_error', $error_msg, [
                'error_code'    => $error_code,
                'zalo_response' => $body,
            ]);
        }

        return $body;
    }

    /**
     * Get ZNS quota information
     *
     * @return array|WP_Error
     */
    public static function get_quota() {
        $access_token = TGS_Zalo_Token_Manager::get_access_token();
        if (is_wp_error($access_token)) {
            return $access_token;
        }

        $response = wp_remote_get(TGS_ZALO_QUOTA_URL, [
            'timeout' => 15,
            'headers' => [
                'access_token' => $access_token,
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (intval($body['error'] ?? -1) !== 0) {
            return new WP_Error('quota_error', $body['message'] ?? 'Không lấy được quota.');
        }

        return $body['data'] ?? $body;
    }

    /**
     * Get message delivery status
     *
     * @param string $msg_id Zalo message ID
     * @return array|WP_Error
     */
    public static function get_message_status($msg_id) {
        $access_token = TGS_Zalo_Token_Manager::get_access_token();
        if (is_wp_error($access_token)) {
            return $access_token;
        }

        $url = TGS_ZALO_MSG_STATUS_URL . '?message_id=' . urlencode($msg_id);

        $response = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => [
                'access_token' => $access_token,
            ],
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }

    /**
     * Format phone number to Zalo format (84xxxxxxxxx)
     *
     * @param string $phone Raw phone number
     * @return string Formatted phone number or empty if invalid
     */
    public static function format_phone($phone) {
        // Remove all non-digit characters
        $phone = preg_replace('/[^0-9]/', '', $phone);

        if (empty($phone)) return '';

        // Vietnamese phone: starts with 0 → replace with 84
        if (strlen($phone) === 10 && $phone[0] === '0') {
            return '84' . substr($phone, 1);
        }

        // Already in 84 format
        if (strlen($phone) === 11 && strpos($phone, '84') === 0) {
            return $phone;
        }

        // 9-digit without prefix
        if (strlen($phone) === 9) {
            return '84' . $phone;
        }

        return '';
    }
}
