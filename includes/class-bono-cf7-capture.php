<?php
/**
 * Contact Form 7 capture integration.
 *
 * @package BonoLeadsConnector
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bono_CF7_Capture extends Bono_Form_Capture {
    /**
     * Register CF7 hooks when CF7 is available.
     *
     * @return void
     */
    public function register_hooks() {
        if (!class_exists('WPCF7_ContactForm') && !function_exists('wpcf7')) {
            return;
        }

        add_action('wpcf7_mail_sent', array($this, 'handle_mail_sent'), 10, 1);
    }

    /**
     * Handle successful CF7 mail submission.
     *
     * @param WPCF7_ContactForm $contact_form Contact form instance.
     * @return void
     */
    public function handle_mail_sent($contact_form) {
        if (!is_object($contact_form)) {
            return;
        }

        $submission = class_exists('WPCF7_Submission') ? WPCF7_Submission::get_instance() : null;
        $posted_data = $submission && method_exists($submission, 'get_posted_data')
            ? $submission->get_posted_data()
            : array();

        if (!is_array($posted_data)) {
            $posted_data = array();
        }

        $form_id = method_exists($contact_form, 'id') ? (string) $contact_form->id() : 'form_unknown';
        $form_name = method_exists($contact_form, 'title') ? (string) $contact_form->title() : '';
        $page_id = $this->get_cf7_page_id($submission);
        $page_url = $this->get_cf7_page_url($submission);

        $payload = $this->build_payload(
            'cf7',
            $form_id,
            $form_name,
            $posted_data,
            $page_id,
            $page_url
        );

        $this->send_payload($payload);
    }

    /**
     * Get CF7 container page ID when available.
     *
     * @param WPCF7_Submission|null $submission CF7 submission.
     * @return string|null
     */
    private function get_cf7_page_id($submission) {
        if ($submission && method_exists($submission, 'get_meta')) {
            $container_post_id = $submission->get_meta('container_post_id');

            if (!empty($container_post_id)) {
                return $container_post_id;
            }
        }

        if (isset($_POST['_wpcf7_container_post'])) {
            return sanitize_text_field(wp_unslash($_POST['_wpcf7_container_post']));
        }

        return null;
    }

    /**
     * Get CF7 page URL when available.
     *
     * @param WPCF7_Submission|null $submission CF7 submission.
     * @return string|null
     */
    private function get_cf7_page_url($submission) {
        if ($submission && method_exists($submission, 'get_meta')) {
            $url = $submission->get_meta('url');

            if (!empty($url)) {
                return $url;
            }
        }

        return null;
    }
}
