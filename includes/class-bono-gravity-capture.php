<?php
/**
 * Gravity Forms capture integration.
 *
 * @package BonoLeadsConnector
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bono_Gravity_Capture extends Bono_Form_Capture {
    /**
     * Register Gravity Forms hooks.
     *
     * The hook only fires when Gravity Forms is active, so registering it
     * unconditionally is safe and avoids brittle plugin-presence guards.
     *
     * @return void
     */
    public function register_hooks() {
        add_action('gform_after_submission', array($this, 'handle_after_submission'), 10, 2);
    }

    /**
     * Handle a completed Gravity Forms submission.
     *
     * @param array $entry Gravity entry (values keyed by field id).
     * @param array $form  Form definition.
     * @return void
     */
    public function handle_after_submission($entry, $form) {
        $entry = is_array($entry) ? $entry : array();
        $form = is_array($form) ? $form : array();

        $form_id = !empty($form['id']) ? sanitize_text_field((string) $form['id']) : 'form_unknown';
        $form_name = !empty($form['title']) ? sanitize_text_field((string) $form['title']) : '';
        $fields = $this->extract_gravity_fields($entry, $form);
        $page_url = !empty($entry['source_url']) ? esc_url_raw((string) $entry['source_url']) : null;

        $payload = $this->build_submission_payload(
            'gravity',
            $form_id,
            $form_name,
            $fields,
            null,
            $page_url
        );

        $this->log_submission_captured(__('Gravity Forms submission captured', 'bono-leads-connector'), $payload);
        $this->send_payload($payload);
    }

    /**
     * Map Gravity entry values to readable field labels.
     *
     * Gravity stores values in the entry keyed by numeric field id (and id.sub
     * for composite inputs). We use the form's field labels as keys when found.
     *
     * @param array $entry Entry values.
     * @param array $form  Form definition.
     * @return array
     */
    private function extract_gravity_fields(array $entry, array $form) {
        $labels = array();

        if (!empty($form['fields']) && is_array($form['fields'])) {
            foreach ($form['fields'] as $field) {
                $id = $this->gravity_field_prop($field, 'id');
                $label = $this->gravity_field_prop($field, 'label');

                if ('' !== $id && '' !== $label) {
                    $labels[$id] = $label;
                }
            }
        }

        $fields = array();

        foreach ($entry as $key => $value) {
            $key = (string) $key;

            // Entry holds field values under numeric keys (e.g. "1", "1.3");
            // skip Gravity's meta keys (id, form_id, date_created, ip, ...).
            if (!is_numeric($key)) {
                continue;
            }

            if (is_string($value) && '' === trim($value)) {
                continue;
            }

            $base_id = strtok($key, '.');
            $label = isset($labels[$base_id]) ? $labels[$base_id] : ('field_' . $key);
            $fields[sanitize_text_field((string) $label)] = $value;
        }

        return $fields;
    }

    /**
     * Read a property from a Gravity field that may be an object or an array.
     *
     * @param mixed  $field Gravity field (GF_Field object or array).
     * @param string $prop  Property name.
     * @return string
     */
    private function gravity_field_prop($field, $prop) {
        if (is_object($field) && isset($field->$prop)) {
            return (string) $field->$prop;
        }

        if (is_array($field) && isset($field[$prop])) {
            return (string) $field[$prop];
        }

        return '';
    }
}
