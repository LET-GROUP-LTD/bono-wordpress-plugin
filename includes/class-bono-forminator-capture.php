<?php
/**
 * Forminator capture integration.
 *
 * @package BonoLeadsConnector
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bono_Forminator_Capture extends Bono_Form_Capture {
    /**
     * Register Forminator hooks.
     *
     * The hook only fires when Forminator is active, so registering it
     * unconditionally is safe.
     *
     * @return void
     */
    public function register_hooks() {
        add_action('forminator_custom_form_submit_before_set_fields', array($this, 'handle_before_set_fields'), 10, 3);
    }

    /**
     * Handle a Forminator submission before fields are persisted.
     *
     * @param mixed      $entry            Forminator entry model (unused).
     * @param int|string $form_id          Form (module) id.
     * @param array      $field_data_array List of ['name' => ..., 'value' => ...].
     * @return void
     */
    public function handle_before_set_fields($entry, $form_id, $field_data_array) {
        $form_id = '' !== (string) $form_id ? sanitize_text_field((string) $form_id) : 'form_unknown';
        $fields = $this->extract_forminator_fields(is_array($field_data_array) ? $field_data_array : array());

        $payload = $this->build_submission_payload(
            'forminator',
            $form_id,
            '',
            $fields,
            null,
            null
        );

        $this->log_submission_captured(__('Forminator submission captured', 'bono-leads-connector'), $payload);
        $this->send_payload($payload);
    }

    /**
     * Flatten Forminator's field-data list into key/value pairs.
     *
     * @param array $field_data_array List of ['name' => ..., 'value' => ...].
     * @return array
     */
    private function extract_forminator_fields(array $field_data_array) {
        $fields = array();

        foreach ($field_data_array as $item) {
            if (!is_array($item) || !isset($item['name'])) {
                continue;
            }

            $name = sanitize_text_field((string) $item['name']);

            if ('' === $name) {
                continue;
            }

            $fields[$name] = array_key_exists('value', $item) ? $item['value'] : '';
        }

        return $fields;
    }
}
