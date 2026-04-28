<?php
/**
 * Bono API client.
 *
 * @package BonoLeadsConnector
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bono_API_Client {
    /**
     * Send a normalized submission payload to Bono.
     *
     * @param array $payload Normalized submission payload.
     * @return array
     */
    public function send_submission(array $payload) {
        $settings = $this->get_settings();

        if (empty($settings['api_base_url']) || empty($settings['api_key']) || empty($settings['site_id'])) {
            return $this->result(false, null, null, __('Missing Bono API settings.', 'bono-leads-connector'));
        }

        if (!$this->is_allowed_api_base_url($settings['api_base_url'])) {
            return $this->result(false, null, null, __('Insecure API Base URL is not allowed.', 'bono-leads-connector'));
        }

        $endpoint = $this->build_endpoint($settings['api_base_url'], '/wordpress/submissions');

        if (empty($endpoint)) {
            return $this->result(false, null, null, __('Invalid Bono API base URL.', 'bono-leads-connector'));
        }

        return $this->post_json($endpoint, $payload, $settings, 'submission', false);
    }

    /**
     * Send a test request to Bono.
     *
     * @return array
     */
    public function test_connection() {
        $settings = $this->get_settings();

        if (empty($settings['api_base_url']) || empty($settings['api_key']) || empty($settings['site_id'])) {
            return $this->result(false, null, null, __('Missing Bono API settings.', 'bono-leads-connector'));
        }

        if (!$this->is_allowed_api_base_url($settings['api_base_url'])) {
            return $this->result(false, null, null, __('Insecure API Base URL is not allowed.', 'bono-leads-connector'));
        }

        $endpoint = $this->build_endpoint($settings['api_base_url'], '/wordpress/test');

        if (empty($endpoint)) {
            return $this->result(false, null, null, __('Invalid Bono API base URL.', 'bono-leads-connector'));
        }

        return $this->post_json(
            $endpoint,
            array(
                'test' => true,
                'timestamp' => current_time('c'),
                'plugin_version' => defined('BONO_PLUGIN_VERSION') ? BONO_PLUGIN_VERSION : '',
            ),
            $settings,
            'test',
            true
        );
    }

    /**
     * Get settings from the settings class when available.
     *
     * @return array
     */
    private function get_settings() {
        if (class_exists('Bono_Settings')) {
            return Bono_Settings::get_settings();
        }

        $settings = get_option('bono_leads_connector_settings', array());

        if (!is_array($settings)) {
            $settings = array();
        }

        return wp_parse_args(
            $settings,
            array(
                'api_base_url' => '',
                'api_key' => '',
                'site_id' => '',
                'enable_debug_log' => false,
            )
        );
    }

    /**
     * Build an API endpoint.
     *
     * @param string $api_base_url API base URL.
     * @param string $path Endpoint path.
     * @return string
     */
    private function build_endpoint($api_base_url, $path) {
        $api_base_url = esc_url_raw(trim($api_base_url));

        if (empty($api_base_url) || !$this->is_allowed_api_base_url($api_base_url)) {
            return '';
        }

        return untrailingslashit($api_base_url) . $path;
    }

    /**
     * Determine whether an API base URL is allowed for outbound requests.
     *
     * @param string $api_base_url API base URL.
     * @return bool
     */
    private function is_allowed_api_base_url($api_base_url) {
        if (class_exists('Bono_Settings') && method_exists('Bono_Settings', 'is_allowed_api_base_url')) {
            return Bono_Settings::is_allowed_api_base_url($api_base_url);
        }

        $api_base_url = esc_url_raw(trim((string) $api_base_url));

        if ('' === $api_base_url) {
            return false;
        }

        $parts = wp_parse_url($api_base_url);

        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);

        if ('https' === $scheme) {
            return true;
        }

        if ('http' !== $scheme) {
            return false;
        }

        return in_array(
            $host,
            array('localhost', '127.0.0.1', 'host.docker.internal'),
            true
        );
    }

    /**
     * Determine whether an API base URL is an allowed local development URL.
     *
     * @param string $api_base_url API base URL.
     * @return bool
     */
    private function is_allowed_local_development_api_base_url($api_base_url) {
        $api_base_url = esc_url_raw(trim((string) $api_base_url));

        if ('' === $api_base_url) {
            return false;
        }

        $parts = wp_parse_url($api_base_url);

        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }

        if ('http' !== strtolower((string) $parts['scheme'])) {
            return false;
        }

        return in_array(
            strtolower((string) $parts['host']),
            array('localhost', '127.0.0.1', 'host.docker.internal'),
            true
        );
    }

    /**
     * Send a JSON POST request to Bono.
     *
     * @param string $endpoint Endpoint URL.
     * @param array  $payload Request payload.
     * @param array  $settings Bono settings.
     * @param string $request_type Request type for logs.
     * @param bool   $require_http_200 Whether only HTTP 200 is considered successful.
     * @return array
     */
    private function post_json($endpoint, array $payload, array $settings, $request_type, $require_http_200) {
        $this->debug_log(
            __('Sending Bono API request.', 'bono-leads-connector'),
            array(
                'request_type' => $request_type,
                'idempotencyKey' => isset($payload['idempotencyKey']) ? $payload['idempotencyKey'] : '',
            )
        );

        $headers = array(
            'Content-Type' => 'application/json',
            'X-Bono-Api-Key' => $settings['api_key'],
            'X-Bono-Site-Id' => $settings['site_id'],
            'X-Bono-Plugin-Version' => defined('BONO_PLUGIN_VERSION') ? BONO_PLUGIN_VERSION : '',
        );

        if (!empty($payload['idempotencyKey'])) {
            $headers['X-Bono-Idempotency-Key'] = $payload['idempotencyKey'];
        }

        $reject_unsafe_urls = !$this->is_allowed_local_development_api_base_url(
            isset($settings['api_base_url']) ? $settings['api_base_url'] : ''
        );

        $response = wp_remote_post(
            $endpoint,
            array(
                'timeout' => 10,
                'redirection' => 0,
                'blocking' => true,
                'reject_unsafe_urls' => $reject_unsafe_urls,
                'headers' => $headers,
                'body' => wp_json_encode($payload),
            )
        );

        if (is_wp_error($response)) {
            $error = $response->get_error_message();

            $this->debug_log(
                __('Bono API request failed.', 'bono-leads-connector'),
                array(
                    'request_type' => $request_type,
                    'error' => $error,
                    'idempotencyKey' => isset($payload['idempotencyKey']) ? $payload['idempotencyKey'] : '',
                )
            );

            return $this->result(false, null, null, $error);
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $success = $require_http_200 ? 200 === $status_code : $status_code >= 200 && $status_code < 300;

        $this->debug_log(
            __('Received Bono API response.', 'bono-leads-connector'),
            array(
                'request_type' => $request_type,
                'status_code' => $status_code,
                'success' => $success ? 'true' : 'false',
                'idempotencyKey' => isset($payload['idempotencyKey']) ? $payload['idempotencyKey'] : '',
            )
        );

        return $this->result(
            $success,
            $status_code,
            is_string($body) ? $body : null,
            $success ? null : __('Bono API returned a non-success response.', 'bono-leads-connector')
        );
    }

    /**
     * Log debug messages only when explicitly enabled.
     *
     * @param string $message Message.
     * @param array  $context Non-sensitive context.
     * @return void
     */
    private function debug_log($message, array $context = array()) {
        $settings = $this->get_settings();

        if (empty($settings['enable_debug_log'])) {
            return;
        }

        $line = '[Bono Leads Connector] ' . sanitize_text_field($message);

        if (!empty($context)) {
            $line .= ' ' . wp_json_encode($this->normalize_log_context($context));
        }

        error_log($line);
    }

    /**
     * Normalize debug log context.
     *
     * @param array $context Context.
     * @return array
     */
    private function normalize_log_context(array $context) {
        $normalized = array();
        $allowed_keys = array(
            'provider',
            'sourceKey',
            'formId',
            'pageId',
            'status_code',
            'success',
            'error',
            'idempotencyKey',
        );

        foreach ($context as $key => $value) {
            if (!in_array($key, $allowed_keys, true)) {
                continue;
            }

            if ('idempotencyKey' === $key) {
                $normalized[sanitize_key($key)] = substr(sanitize_text_field((string) $value), 0, 8);
                continue;
            }

            $normalized[sanitize_key($key)] = is_scalar($value) || is_null($value)
                ? sanitize_text_field((string) $value)
                : '[non_scalar]';
        }

        return $normalized;
    }

    /**
     * Build a structured result.
     *
     * @param bool        $success Whether the request succeeded.
     * @param int|null    $status_code HTTP status code.
     * @param string|null $body Response body.
     * @param string|null $error Error message.
     * @return array
     */
    private function result($success, $status_code, $body, $error) {
        return array(
            'success' => (bool) $success,
            'status_code' => $status_code,
            'body' => $body,
            'error' => $error,
        );
    }
}
