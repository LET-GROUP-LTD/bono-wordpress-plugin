<?php
/**
 * Elementor Pro Forms capture integration.
 *
 * @package BonoLeadsConnector
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bono_Elementor_Capture extends Bono_Form_Capture {
    /**
     * Register Elementor Pro Forms hook when Elementor Pro is available.
     *
     * @return void
     */
    public function register_hooks() {
        if (!defined('ELEMENTOR_PRO_VERSION') && !class_exists('\ElementorPro\Plugin')) {
            return;
        }

        add_action('elementor_pro/forms/new_record', array($this, 'handle_new_record'), 10, 2);
    }

    /**
     * Handle Elementor Pro form submission.
     *
     * @param object $record Elementor form record.
     * @param object $handler Elementor form handler.
     * @return void
     */
    public function handle_new_record($record, $handler) {
        if (!is_object($record) || !method_exists($record, 'get')) {
            return;
        }

        $form_id = $this->get_form_setting($record, 'form_id');
        $form_name = $this->get_form_setting($record, 'form_name');

        if ('' === $form_id) {
            $form_id = '' !== $form_name ? $form_name : 'form_unknown';
        }

        $fields = $record->get('fields');
        $fields = is_array($fields) ? $this->extract_elementor_fields($fields) : array();
        $page_id = $this->get_elementor_page_id($record);
        $page_url = $this->get_elementor_page_url($record);

        $payload = $this->build_submission_payload(
            'elementor',
            $form_id,
            $form_name,
            $fields,
            $page_id,
            $page_url
        );

        $this->log_submission_captured(__('Elementor submission captured', 'bono-leads-connector'), $payload);
        $this->send_payload($payload);
    }

    /**
     * Extract Elementor form setting.
     *
     * @param object $record Elementor record.
     * @param string $key Setting key.
     * @return string
     */
    private function get_form_setting($record, $key) {
        if (method_exists($record, 'get_form_settings')) {
            $value = $record->get_form_settings($key);

            if (!empty($value)) {
                return sanitize_text_field((string) $value);
            }
        }

        $settings = $record->get('form_settings');

        if (is_array($settings) && !empty($settings[$key])) {
            return sanitize_text_field((string) $settings[$key]);
        }

        return '';
    }

    /**
     * Convert Elementor field records to simple key/value fields.
     *
     * @param array $fields Elementor fields.
     * @return array
     */
    private function extract_elementor_fields(array $fields) {
        $submitted = array();

        foreach ($fields as $key => $field) {
            $field_key = sanitize_key($key);

            if (is_array($field)) {
                if (!empty($field['id'])) {
                    $field_key = sanitize_key($field['id']);
                } elseif (!empty($field['title'])) {
                    $field_key = sanitize_text_field($field['title']);
                } elseif (!empty($field['label'])) {
                    $field_key = sanitize_text_field($field['label']);
                }

                if (array_key_exists('value', $field)) {
                    $submitted[$field_key] = $field['value'];
                    continue;
                }

                if (array_key_exists('raw_value', $field)) {
                    $submitted[$field_key] = $field['raw_value'];
                    continue;
                }
            }

            $submitted[$field_key] = $field;
        }

        return $submitted;
    }

    /**
     * Get Elementor page ID when available.
     *
     * @param object $record Elementor record.
     * @return string|null
     */
    private function get_elementor_page_id($record) {
        $page_id = $record->get('page_id');

        if (!empty($page_id)) {
            return $page_id;
        }

        $post_id = $record->get('post_id');

        if (!empty($post_id)) {
            return $post_id;
        }

        if (isset($_POST['post_id'])) {
            return sanitize_text_field(wp_unslash($_POST['post_id']));
        }

        return null;
    }

    /**
     * Get Elementor page URL when available.
     *
     * @param object $record Elementor record.
     * @return string|null
     */
    private function get_elementor_page_url($record) {
        $meta = $record->get('meta');

        if (is_array($meta) && !empty($meta['page_url'])) {
            return $meta['page_url'];
        }

        if (is_array($meta) && !empty($meta['referrer_url'])) {
            return $meta['referrer_url'];
        }

        if (isset($_POST['referrer'])) {
            return esc_url_raw(wp_unslash($_POST['referrer']));
        }

        if (isset($_POST['referer'])) {
            return esc_url_raw(wp_unslash($_POST['referer']));
        }

        return null;
    }
}
