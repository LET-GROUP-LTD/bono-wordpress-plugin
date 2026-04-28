<?php
/**
 * Shared form capture helpers.
 *
 * @package BonoLeadsConnector
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bono_Form_Capture {
    /**
     * Bono API client.
     *
     * @var Bono_API_Client
     */
    protected $api_client;

    /**
     * Constructor.
     *
     * @param Bono_API_Client $api_client Bono API client.
     */
    public function __construct(Bono_API_Client $api_client) {
        $this->api_client = $api_client;
    }

    /**
     * Detect the current page ID.
     *
     * @param int|string|null $candidate Optional known page ID.
     * @return string
     */
    protected function get_page_id($candidate = null) {
        if (!empty($candidate)) {
            return $this->format_page_id($candidate);
        }

        $queried_id = get_queried_object_id();

        if (!empty($queried_id)) {
            return $this->format_page_id($queried_id);
        }

        $referer = wp_get_referer();

        if (!empty($referer)) {
            $post_id = url_to_postid($referer);

            if (!empty($post_id)) {
                return $this->format_page_id($post_id);
            }
        }

        return 'page_unknown';
    }

    /**
     * Detect current page URL.
     *
     * @param string|null $candidate Optional known URL.
     * @return string
     */
    protected function get_page_url($candidate = null) {
        if (!empty($candidate)) {
            return esc_url_raw($candidate);
        }

        $referer = wp_get_referer();

        if (!empty($referer)) {
            return esc_url_raw($referer);
        }

        if (!empty($_SERVER['HTTP_HOST']) && !empty($_SERVER['REQUEST_URI'])) {
            $scheme = is_ssl() ? 'https://' : 'http://';
            $host = sanitize_text_field(wp_unslash($_SERVER['HTTP_HOST']));
            $request_uri = sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI']));

            return esc_url_raw($scheme . $host . $request_uri);
        }

        return '';
    }

    /**
     * Extract UTM parameters from the current request.
     *
     * @return array
     */
    protected function get_utm_params() {
        $utm = array();
        $keys = array('utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term');

        foreach ($keys as $key) {
            if (isset($_REQUEST[$key])) {
                $utm[$key] = sanitize_text_field(wp_unslash($_REQUEST[$key]));
            }
        }

        return empty($utm) ? new stdClass() : $utm;
    }

    /**
     * Normalize submitted field values.
     *
     * @param array $fields Raw fields.
     * @return array
     */
    protected function normalize_fields(array $fields) {
        $normalized = array();

        foreach ($fields as $key => $value) {
            $field_key = $this->normalize_field_key($key);

            if ('' === $field_key || 0 === strpos($field_key, '_')) {
                continue;
            }

            $normalized[$field_key] = $this->normalize_value($value);
        }

        return $normalized;
    }

    /**
     * Create Bono source key.
     *
     * @param string $provider Provider slug.
     * @param string $form_id Form ID.
     * @param string $page_id Page ID.
     * @return string
     */
    protected function create_source_key($provider, $form_id, $page_id) {
        $provider = sanitize_key($provider);
        $form_id = sanitize_key((string) $form_id);
        $page_id = sanitize_key((string) $page_id);

        if ('' === $form_id) {
            $form_id = 'form_unknown';
        }

        if ('' === $page_id) {
            $page_id = 'page_unknown';
        }

        return $provider . ':' . $form_id . ':' . $page_id;
    }

    /**
     * Build the normalized payload shared by all providers.
     *
     * @param string      $provider Provider slug.
     * @param string      $form_id Form ID.
     * @param string      $form_name Form display name.
     * @param array       $fields Submitted fields.
     * @param string|null $page_id Page ID.
     * @param string|null $page_url Page URL.
     * @return array
     */
    protected function build_payload($provider, $form_id, $form_name, array $fields, $page_id = null, $page_url = null) {
        return $this->build_submission_payload($provider, $form_id, $form_name, $fields, $page_id, $page_url);
    }

    /**
     * Build the normalized submission payload shared by all providers.
     *
     * @param string      $provider Provider slug.
     * @param string      $form_id Form ID.
     * @param string      $form_name Form display name.
     * @param array       $fields Submitted fields.
     * @param string|null $page_id Page ID.
     * @param string|null $page_url Page URL.
     * @return array
     */
    protected function build_submission_payload($provider, $form_id, $form_name, array $fields, $page_id = null, $page_url = null) {
        $page_id = $this->get_page_id($page_id);
        $page_url = $this->get_page_url($page_url);
        $form_id = sanitize_text_field((string) $form_id);

        if ('' === $form_id) {
            $form_id = 'form_unknown';
        }

        return array(
            'provider' => sanitize_key($provider),
            'sourceKey' => $this->create_source_key($provider, $form_id, $page_id),
            'formId' => $form_id,
            'formName' => sanitize_text_field((string) $form_name),
            'pageId' => $page_id,
            'pageUrl' => $page_url,
            'submittedAt' => gmdate('c'),
            'fields' => $this->normalize_fields($fields),
            'utm' => $this->get_utm_params(),
            'site' => array(
                'siteUrl' => esc_url_raw(home_url()),
                'pluginVersion' => defined('BONO_PLUGIN_VERSION') ? BONO_PLUGIN_VERSION : '',
            ),
        );
    }

    /**
     * Log debug messages only when explicitly enabled.
     *
     * @param string $message Message.
     * @param array  $context Non-sensitive context.
     * @return void
     */
    protected function debug_log($message, array $context = array()) {
        $settings = class_exists('Bono_Settings') ? Bono_Settings::get_settings() : array();

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
     * Send payload and log failures without interrupting form submission.
     *
     * @param array $payload Normalized payload.
     * @return void
     */
    protected function send_payload(array $payload) {
        $result = $this->api_client->send_submission($payload);

        if (empty($result['success'])) {
            $this->debug_log(
                __('Bono submission failed.', 'bono-leads-connector'),
                array(
                    'provider' => isset($payload['provider']) ? $payload['provider'] : '',
                    'sourceKey' => isset($payload['sourceKey']) ? $payload['sourceKey'] : '',
                    'status_code' => isset($result['status_code']) ? $result['status_code'] : null,
                    'error' => isset($result['error']) ? $result['error'] : null,
                )
            );
        }
    }

    /**
     * Log provider capture metadata without field values.
     *
     * @param string $message Capture message.
     * @param array  $payload Normalized payload.
     * @return void
     */
    protected function log_submission_captured($message, array $payload) {
        $this->debug_log(
            $message,
            array(
                'sourceKey' => isset($payload['sourceKey']) ? $payload['sourceKey'] : '',
                'formId' => isset($payload['formId']) ? $payload['formId'] : '',
                'pageId' => isset($payload['pageId']) ? $payload['pageId'] : '',
            )
        );
    }

    /**
     * Normalize page ID to the source key format.
     *
     * @param int|string $page_id Page ID.
     * @return string
     */
    private function format_page_id($page_id) {
        $page_id = sanitize_text_field((string) $page_id);

        if ('' === $page_id) {
            return 'page_unknown';
        }

        if (0 === strpos($page_id, 'page_')) {
            return sanitize_key($page_id);
        }

        return 'page_' . sanitize_key($page_id);
    }

    /**
     * Normalize a submitted value.
     *
     * @param mixed $value Raw value.
     * @return mixed
     */
    private function normalize_value($value) {
        if (is_array($value)) {
            $normalized = array();

            foreach ($value as $key => $item) {
                $normalized[$this->normalize_field_key($key)] = $this->normalize_value($item);
            }

            return $normalized;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return sanitize_text_field((string) $value);
        }

        return sanitize_textarea_field((string) $value);
    }

    /**
     * Normalize field keys while preserving custom field names where possible.
     *
     * @param int|string $key Raw field key.
     * @return string
     */
    private function normalize_field_key($key) {
        $key = sanitize_text_field((string) $key);

        return preg_replace('/[\x00-\x1F\x7F]/', '', $key);
    }

    /**
     * Keep logs small and free of request payload details.
     *
     * @param array $context Context.
     * @return array
     */
    private function normalize_log_context(array $context) {
        $normalized = array();

        foreach ($context as $key => $value) {
            $normalized[sanitize_key($key)] = is_scalar($value) || is_null($value)
                ? sanitize_text_field((string) $value)
                : '[non_scalar]';
        }

        return $normalized;
    }
}
