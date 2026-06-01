<?php
/**
 * Read-only status REST endpoint for remote observability.
 *
 * Lets Bono poll a connected site for delivery health and reconciliation:
 * GET /wp-json/bono/v1/status (authenticated with the site's API key).
 * Exposes plugin/version, connection state, and queue counts/health only —
 * never lead data.
 *
 * @package BonoLeadsConnector
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bono_Status_Endpoint {
    const REST_NAMESPACE = 'bono/v1';
    const REST_ROUTE = '/status';

    /**
     * Submission queue (for counts/health).
     *
     * @var Bono_Submission_Queue|null
     */
    private $submission_queue;

    /**
     * Constructor.
     *
     * @param Bono_Submission_Queue|null $submission_queue Queue service.
     */
    public function __construct($submission_queue = null) {
        $this->submission_queue = (
            class_exists('Bono_Submission_Queue') &&
            $submission_queue instanceof Bono_Submission_Queue
        ) ? $submission_queue : null;
    }

    /**
     * Register runtime hooks.
     *
     * @return void
     */
    public function register_hooks() {
        add_action('rest_api_init', array($this, 'register_rest_route'));
    }

    /**
     * Register the status REST route.
     *
     * @return void
     */
    public function register_rest_route() {
        register_rest_route(
            self::REST_NAMESPACE,
            self::REST_ROUTE,
            array(
                'methods' => 'GET',
                'callback' => array($this, 'handle_status'),
                'permission_callback' => array($this, 'verify_request'),
            )
        );
    }

    /**
     * Permission callback: require the site's Bono API key, presented as the
     * X-Bono-Api-Key header. Only Bono (which issued the key) can read status.
     *
     * @param WP_REST_Request $request Request.
     * @return bool|WP_Error
     */
    public function verify_request($request) {
        $settings = class_exists('Bono_Settings') ? Bono_Settings::get_settings() : array();
        $stored_key = isset($settings['api_key']) ? (string) $settings['api_key'] : '';
        $provided_key = (string) $request->get_header('X-Bono-Api-Key');

        if ('' === $stored_key || '' === $provided_key || !hash_equals($stored_key, $provided_key)) {
            return new WP_Error(
                'bono_status_unauthorized',
                __('Invalid or missing Bono API key.', 'bono-leads-connector'),
                array('status' => 401)
            );
        }

        return true;
    }

    /**
     * Build the status payload (pure; no WordPress calls beyond the injected
     * settings/counts) so it is unit-testable.
     *
     * @param array $settings Plugin settings.
     * @param array $counts   Queue counts (Bono_Submission_Queue::get_counts()).
     * @param bool  $scheduler_available Whether Action Scheduler is loaded.
     * @return array
     */
    public function build_status_payload(array $settings, array $counts, $scheduler_available) {
        $health = isset($counts['health']) && is_array($counts['health']) ? $counts['health'] : array();

        return array(
            'plugin' => 'bono-leads-connector',
            'version' => defined('BONO_PLUGIN_VERSION') ? BONO_PLUGIN_VERSION : '',
            'connected' => !empty($settings['site_id']) && !empty($settings['api_key']),
            'site_id' => isset($settings['site_id']) ? (string) $settings['site_id'] : '',
            'queue' => array(
                'pending' => isset($counts['pending']) ? (int) $counts['pending'] : 0,
                'retrying' => isset($counts['retrying']) ? (int) $counts['retrying'] : 0,
                'sent' => isset($counts['sent']) ? (int) $counts['sent'] : 0,
                'failed' => isset($counts['failed']) ? (int) $counts['failed'] : 0,
                'latest_failed_at' => isset($counts['latest_failed_at']) ? (string) $counts['latest_failed_at'] : '',
                'health' => isset($health['state']) ? (string) $health['state'] : 'unknown',
            ),
            'async_processing' => (bool) $scheduler_available,
        );
    }

    /**
     * Handle a status request.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response
     */
    public function handle_status($request) {
        $settings = class_exists('Bono_Settings') ? Bono_Settings::get_settings() : array();
        $counts = $this->submission_queue instanceof Bono_Submission_Queue
            ? $this->submission_queue->get_counts()
            : array();
        $scheduler_available = function_exists('as_has_scheduled_action');

        $payload = $this->build_status_payload($settings, $counts, $scheduler_available);

        return new WP_REST_Response($payload, 200);
    }
}
