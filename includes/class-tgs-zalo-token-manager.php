<?php
/**
 * Token Manager - Handles Zalo OAuth token lifecycle
 * 
 * Access tokens expire every ~1 hour. This class auto-refreshes
 * and stores tokens in wp_sitemeta (network-level).
 */

if (!defined('ABSPATH')) exit;

class TGS_Zalo_Token_Manager {

    private static $cache_access_token = null;

    /**
     * Get a valid access token. Auto-refreshes if expired.
     *
     * @return string|WP_Error
     */
    public static function get_access_token() {
        if (self::$cache_access_token !== null) {
            return self::$cache_access_token;
        }

        $access_token = get_site_option('tgs_zalo_access_token', '');
        $expires_at = intval(get_site_option('tgs_zalo_token_expires_at', 0));

        // Token still valid (with 5min buffer)
        if (!empty($access_token) && $expires_at > (time() + 300)) {
            self::$cache_access_token = $access_token;
            return $access_token;
        }

        // Need to refresh
        $refresh_result = self::refresh_token();
        if (is_wp_error($refresh_result)) {
            return $refresh_result;
        }

        self::$cache_access_token = $refresh_result;
        return $refresh_result;
    }

    /**
     * Refresh the access token using the refresh token
     *
     * @return string|WP_Error New access token or error
     */
    public static function refresh_token() {
        $app_id = get_site_option('tgs_zalo_app_id', '');
        $secret_key = get_site_option('tgs_zalo_secret_key', '');
        $refresh_token = get_site_option('tgs_zalo_refresh_token', '');

        if (empty($app_id) || empty($secret_key)) {
            return new WP_Error('missing_config', 'Thiếu App ID hoặc Secret Key Zalo.');
        }

        if (empty($refresh_token)) {
            return new WP_Error('no_refresh_token', 'Chưa có refresh token. Vui lòng thực hiện OAuth lần đầu.');
        }

        $response = wp_remote_post(TGS_ZALO_OAUTH_URL, [
            'timeout' => 30,
            'headers' => [
                'secret_key'   => $secret_key,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => [
                'grant_type'    => 'refresh_token',
                'app_id'        => $app_id,
                'refresh_token' => $refresh_token,
            ],
        ]);

        if (is_wp_error($response)) {
            error_log('[TGS Zalo] Token refresh HTTP error: ' . $response->get_error_message());
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['access_token'])) {
            $error_msg = $body['error_description'] ?? $body['message'] ?? 'Unknown error';
            $error_code = $body['error'] ?? -1;
            error_log("[TGS Zalo] Token refresh failed: [{$error_code}] {$error_msg}");
            return new WP_Error('token_refresh_failed', "Refresh token thất bại: {$error_msg}");
        }

        // Save new tokens
        self::store_tokens($body);

        return $body['access_token'];
    }

    /**
     * Exchange authorization code for tokens (first-time OAuth)
     *
     * @param string $code Authorization code from Zalo callback
     * @param string $code_verifier PKCE code verifier
     * @return array|WP_Error Token data or error
     */
    public static function exchange_code_for_tokens($code, $code_verifier = '') {
        $app_id = get_site_option('tgs_zalo_app_id', '');
        $secret_key = get_site_option('tgs_zalo_secret_key', '');

        if (empty($app_id) || empty($secret_key)) {
            return new WP_Error('missing_config', 'Thiếu App ID hoặc Secret Key.');
        }

        $body = [
            'grant_type' => 'authorization_code',
            'app_id'     => $app_id,
            'code'       => $code,
        ];

        if (!empty($code_verifier)) {
            $body['code_verifier'] = $code_verifier;
        }

        $response = wp_remote_post(TGS_ZALO_OAUTH_URL, [
            'timeout' => 30,
            'headers' => [
                'secret_key'   => $secret_key,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => $body,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($data['access_token'])) {
            $error_msg = $data['error_description'] ?? $data['message'] ?? 'Unknown error';
            return new WP_Error('oauth_failed', "OAuth thất bại: {$error_msg}");
        }

        self::store_tokens($data);

        return $data;
    }

    /**
     * Store tokens in site options
     */
    private static function store_tokens(array $data) {
        $access_token = $data['access_token'];
        $refresh_token = $data['refresh_token'] ?? '';
        $expires_in = intval($data['expires_in'] ?? 3600);

        update_site_option('tgs_zalo_access_token', $access_token);
        update_site_option('tgs_zalo_token_expires_at', time() + $expires_in);

        if (!empty($refresh_token)) {
            update_site_option('tgs_zalo_refresh_token', $refresh_token);
        }

        update_site_option('tgs_zalo_last_token_refresh', current_time('mysql'));

        // Clear cache
        self::$cache_access_token = $access_token;

        error_log('[TGS Zalo] Tokens refreshed successfully. Expires in: ' . $expires_in . 's');
    }

    /**
     * Check if tokens are configured
     */
    public static function has_tokens() {
        return !empty(get_site_option('tgs_zalo_refresh_token', ''));
    }

    /**
     * Get token status info for admin display
     */
    public static function get_status() {
        $expires_at = intval(get_site_option('tgs_zalo_token_expires_at', 0));
        $last_refresh = get_site_option('tgs_zalo_last_token_refresh', '');
        $has_refresh = !empty(get_site_option('tgs_zalo_refresh_token', ''));

        return [
            'has_refresh_token' => $has_refresh,
            'is_expired'        => $expires_at < time(),
            'expires_at'        => $expires_at > 0 ? date('Y-m-d H:i:s', $expires_at) : 'N/A',
            'last_refresh'      => $last_refresh ?: 'Chưa refresh',
            'time_remaining'    => $expires_at > time() ? human_time_diff(time(), $expires_at) : 'Đã hết hạn',
        ];
    }

    /**
     * Generate OAuth URL for first-time authorization
     */
    public static function get_oauth_url($redirect_uri) {
        $app_id = get_site_option('tgs_zalo_app_id', '');

        // Generate PKCE code verifier + challenge
        $code_verifier = bin2hex(random_bytes(32));
        $code_challenge = rtrim(strtr(base64_encode(hash('sha256', $code_verifier, true)), '+/', '-_'), '=');

        // Generate state for CSRF protection
        $state = wp_generate_password(32, false);

        // Store temporarily for callback verification
        update_site_option('tgs_zalo_oauth_code_verifier', $code_verifier);
        update_site_option('tgs_zalo_oauth_state', $state);

        $params = http_build_query([
            'app_id'                => $app_id,
            'redirect_uri'          => $redirect_uri,
            'code_challenge'        => $code_challenge,
            'code_challenge_method' => 'S256',
            'state'                 => $state,
        ]);

        return "https://oauth.zaloapp.com/v4/oa/permission?{$params}";
    }

    /**
     * Revoke all tokens (disconnect)
     */
    public static function revoke() {
        delete_site_option('tgs_zalo_access_token');
        delete_site_option('tgs_zalo_refresh_token');
        delete_site_option('tgs_zalo_token_expires_at');
        delete_site_option('tgs_zalo_last_token_refresh');
        self::$cache_access_token = null;
    }
}
