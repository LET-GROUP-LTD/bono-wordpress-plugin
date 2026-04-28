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

        $endpoint = $this->build_endpoint($settings['api_base_url']);

        if (empty($endpoint)) {
            return $this->result(false, null, null, __('Invalid Bono API base URL.', 'bono-leads-connector'));
        }

        $response = wp_remote_post(
            $endpoint,
            array(
                'timeout' => 10,
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'X-Bono-Api-Key' => $settings['api_key'],
                    'X-Bono-Site-Id' => $settings['site_id'],
                ),
                'body' => wp_json_encode($payload),
            )
        );

        if (is_wp_error($response)) {
            return $this->result(false, null, null, $response->get_error_message());
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $success = $status_code >= 200 && $status_code < 300;

        return $this->result(
            $success,
            $status_code,
            is_string($body) ? $body : null,
            $success ? null : __('Bono API returned a non-success response.', 'bono-leads-connector')
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
     * Build the MVP submissions endpoint.
     *
     * @param string $api_base_url API base URL.
     * @return string
     */
    private function build_endpoint($api_base_url) {
        $api_base_url = esc_url_raw(trim($api_base_url));

        if (empty($api_base_url)) {
            return '';
        }

        return untrailingslashit($api_base_url) . '/wordpress/submissions';
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
