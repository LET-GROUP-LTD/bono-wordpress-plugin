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

            if ('' === $field_key) {
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
        $normalized_fields = $this->normalize_fields($fields);
        $contact = $this->detect_contact_fields($normalized_fields);
        $validation = $this->validate_contact($contact);

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
            'fields' => $normalized_fields,
            'contact' => $contact,
            'validation' => $validation,
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
        $validation = isset($payload['validation']) && is_array($payload['validation']) ? $payload['validation'] : array();

        if (isset($validation['isValid']) && false === $validation['isValid']) {
            $this->debug_log(
                __('Submission skipped: missing required contact fields', 'bono-leads-connector'),
                array(
                    'provider' => isset($payload['provider']) ? $payload['provider'] : '',
                    'sourceKey' => isset($payload['sourceKey']) ? $payload['sourceKey'] : '',
                    'missing' => isset($validation['missing']) ? wp_json_encode($validation['missing']) : '',
                )
            );
            return;
        }

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
     * Detect normalized contact fields from submitted fields.
     *
     * @param array $fields Normalized submitted fields.
     * @return array
     */
    protected function detect_contact_fields(array $fields) {
        return array(
            'name' => $this->detect_name($fields),
            'email' => $this->detect_email($fields),
            'phone' => $this->detect_phone($fields),
        );
    }

    /**
     * Detect contact name.
     *
     * @param array $fields Normalized submitted fields.
     * @return string
     */
    protected function detect_name(array $fields) {
        $first_name = '';
        $last_name = '';
        $best_name = '';
        $best_score = 0;

        foreach ($fields as $key => $value) {
            $value = $this->normalize_scalar_value($value);

            if ('' === $value) {
                continue;
            }

            $lookup_key = $this->normalize_lookup_key($key);

             if ($this->is_utm_lookup_key($lookup_key)) {
                continue;
            }

            if ($this->is_first_name_key($lookup_key)) {
                $first_name = $value;
                continue;
            }

            if ($this->is_last_name_key($lookup_key)) {
                $last_name = $value;
                continue;
            }

            $score = $this->score_name_candidate($lookup_key, $value);

            if ($score > $best_score) {
                $best_score = $score;
                $best_name = $value;
            }
        }

        if ('' !== $first_name || '' !== $last_name) {
            return trim($first_name . ' ' . $last_name);
        }

        return $best_name;
    }

    /**
     * Detect contact email.
     *
     * @param array $fields Normalized submitted fields.
     * @return string
     */
    protected function detect_email(array $fields) {
        $best_email = '';
        $best_score = 0;

        foreach ($fields as $key => $value) {
            $value = $this->normalize_scalar_value($value);

            if ('' === $value || !$this->is_valid_email_value($value)) {
                continue;
            }

            $lookup_key = $this->normalize_lookup_key($key);
            if ($this->is_utm_lookup_key($lookup_key)) {
                continue;
            }
            $score = in_array($lookup_key, $this->email_key_aliases(), true) ? 100 : 60;

            if ($score > $best_score) {
                $best_score = $score;
                $best_email = $value;
            }
        }

        return $best_email;
    }

    /**
     * Detect contact phone.
     *
     * @param array $fields Normalized submitted fields.
     * @return string
     */
    protected function detect_phone(array $fields) {
        $best_phone = '';
        $best_score = 0;

        foreach ($fields as $key => $value) {
            $value = $this->normalize_scalar_value($value);

            if ('' === $value) {
                continue;
            }

            $lookup_key = $this->normalize_lookup_key($key);

            if ($this->is_utm_lookup_key($lookup_key)) {
                continue;
            }

            $normalized_phone = $this->normalize_phone_value($value);

            if ('' === $normalized_phone || !$this->is_phone_like_value($normalized_phone)) {
                continue;
            }

            $score = in_array($lookup_key, $this->phone_key_aliases(), true) ? 100 : 55;

            if ($score > $best_score) {
                $best_score = $score;
                $best_phone = $normalized_phone;
            }
        }

        return $best_phone;
    }

    /**
     * Validate contact fields against Bono requirements.
     *
     * @param array $contact Detected contact values.
     * @return array
     */
    protected function validate_contact(array $contact) {
        $missing = array();
        $name = isset($contact['name']) ? trim((string) $contact['name']) : '';
        $email = isset($contact['email']) ? trim((string) $contact['email']) : '';
        $phone = isset($contact['phone']) ? trim((string) $contact['phone']) : '';

        if ('' === $name) {
            $missing[] = 'name';
        }

        if ('' === $email && '' === $phone) {
            $missing[] = 'phone_or_email';
        }

        return array(
            'isValid' => empty($missing),
            'missing' => $missing,
            'rule' => 'name && (phone || email)',
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
    protected function normalize_field_key($key) {
        $key = sanitize_text_field((string) $key);

        return (string) preg_replace('/[\x00-\x1F\x7F]/', '', $key);
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

    /**
     * Normalize key for matching aliases.
     *
     * @param string $key Raw key.
     * @return string
     */
    private function normalize_lookup_key($key) {
        $key = $this->normalize_field_key($key);
        $key = strtolower($key);

        return (string) preg_replace('/[^a-z0-9א-ת]+/u', '', $key);
    }

    /**
     * Normalize scalar field values.
     *
     * @param mixed $value Raw value.
     * @return string
     */
    private function normalize_scalar_value($value) {
        if (is_array($value)) {
            return '';
        }

        return trim(sanitize_text_field((string) $value));
    }

    /**
     * Normalize phone values by removing formatting.
     *
     * @param string $value Raw value.
     * @return string
     */
    protected function normalize_phone_value($value) {
        $value = trim((string) $value);

        if ('' === $value) {
            return '';
        }

        $has_plus = 0 === strpos($value, '+');
        $value = preg_replace('/[\s\-\(\)\.]/', '', $value);
        $value = preg_replace('/[^0-9+]/', '', (string) $value);

        if ($has_plus) {
            $value = '+' . ltrim((string) $value, '+');
        } else {
            $value = ltrim((string) $value, '+');
        }

        return trim((string) $value);
    }

    /**
     * Determine if a value is a valid email.
     *
     * @param string $value Candidate value.
     * @return bool
     */
    protected function is_valid_email_value($value) {
        $value = trim((string) $value);

        if ('' === $value) {
            return false;
        }

        if (function_exists('is_email')) {
            return false !== is_email($value);
        }

        return false !== filter_var($value, FILTER_VALIDATE_EMAIL);
    }

    /**
     * Determine if a value is phone-like.
     *
     * @param string $value Normalized phone candidate.
     * @return bool
     */
    private function is_phone_like_value($value) {
        $digits_only = preg_replace('/\D+/', '', (string) $value);
        $length = strlen((string) $digits_only);

        return $length >= 7 && $length <= 15;
    }

    /**
     * Score potential name candidates.
     *
     * @param string $lookup_key Normalized lookup key.
     * @param string $value Candidate value.
     * @return int
     */
    private function score_name_candidate($lookup_key, $value) {
        if (in_array($lookup_key, $this->name_key_aliases(), true)) {
            return 100;
        }

        if ($this->is_valid_email_value($value) || $this->is_phone_like_value($this->normalize_phone_value($value))) {
            return 0;
        }

        if (preg_match('/(name|fullname|contactname|customername|שם|מלא)/u', $lookup_key)) {
            return 80;
        }

        if (preg_match('/\s+/', $value) && preg_match('/[a-zA-Zא-ת]/u', $value)) {
            return 45;
        }

        return 0;
    }

    /**
     * Determine if field key is first-name-like.
     *
     * @param string $lookup_key Normalized key.
     * @return bool
     */
    private function is_first_name_key($lookup_key) {
        return in_array(
            $lookup_key,
            array('firstname', 'first', 'givenname', 'שםפרטי'),
            true
        );
    }

    /**
     * Determine if field key is last-name-like.
     *
     * @param string $lookup_key Normalized key.
     * @return bool
     */
    private function is_last_name_key($lookup_key) {
        return in_array(
            $lookup_key,
            array('lastname', 'last', 'surname', 'familyname', 'שםמשפחה'),
            true
        );
    }

    /**
     * Determine if field key belongs to UTM parameters.
     *
     * @param string $lookup_key Normalized key.
     * @return bool
     */
    private function is_utm_lookup_key($lookup_key) {
        return in_array(
            $lookup_key,
            array('utmsource', 'utmmedium', 'utmcampaign', 'utmcontent', 'utmterm'),
            true
        );
    }

    /**
     * Known email field aliases.
     *
     * @return array
     */
    private function email_key_aliases() {
        return array(
            'email',
            'youremail',
            'emailaddress',
            'mail',
            'contactemail',
            'דואל',
            'אימייל',
            'מייל',
        );
    }

    /**
     * Known phone field aliases.
     *
     * @return array
     */
    private function phone_key_aliases() {
        return array(
            'phone',
            'tel',
            'telephone',
            'mobile',
            'cellphone',
            'cell',
            'phonenumber',
            'yourphone',
            'contactphone',
            'מספרטלפון',
            'טלפון',
            'נייד',
            'פלאפון',
        );
    }

    /**
     * Known name field aliases.
     *
     * @return array
     */
    private function name_key_aliases() {
        return array(
            'name',
            'fullname',
            'yourname',
            'contactname',
            'customername',
            'שם',
            'שםמלא',
        );
    }
}
