<?php
/**
 * Admin settings for Bono Leads Connector.
 *
 * @package BonoLeadsConnector
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bono_Settings {
    const OPTION_KEY = 'bono_leads_connector_settings';

    /**
     * Register WordPress hooks.
     *
     * @return void
     */
    public function register_hooks() {
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_post_bono_test_api_connection', array($this, 'handle_test_api_connection'));
        add_action('admin_post_bono_retry_failed_submissions', array($this, 'handle_retry_failed_submissions'));
        add_action('admin_post_bono_process_queue_now', array($this, 'handle_process_queue_now'));
        add_action('admin_post_bono_delete_sent_queue_rows', array($this, 'handle_delete_sent_queue_rows'));
        add_action('admin_post_bono_delete_failed_queue_rows', array($this, 'handle_delete_failed_queue_rows'));
    }

    /**
     * Default settings.
     *
     * @return array
     */
    public static function get_defaults() {
        return array(
            'api_base_url' => '',
            'api_key' => '',
            'site_id' => '',
            'enable_debug_log' => false,
        );
    }

    /**
     * Get merged settings.
     *
     * @return array
     */
    public static function get_settings() {
        $settings = get_option(self::OPTION_KEY, array());

        if (!is_array($settings)) {
            $settings = array();
        }

        return wp_parse_args($settings, self::get_defaults());
    }

    /**
     * Determine whether an API base URL is allowed for outbound requests.
     *
     * @param string $api_base_url API base URL.
     * @return bool
     */
    public static function is_allowed_api_base_url($api_base_url) {
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
     * Add settings page under Settings.
     *
     * @return void
     */
    public function add_settings_page() {
        add_options_page(
            __('Bono Leads Connector', 'bono-leads-connector'),
            __('Bono Leads', 'bono-leads-connector'),
            'manage_options',
            'bono-leads-connector',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Register settings and fields.
     *
     * @return void
     */
    public function register_settings() {
        register_setting(
            'bono_leads_connector',
            self::OPTION_KEY,
            array(
                'type' => 'array',
                'sanitize_callback' => array($this, 'sanitize_settings'),
                'default' => self::get_defaults(),
            )
        );

        add_settings_section(
            'bono_leads_connector_api',
            __('API Settings', 'bono-leads-connector'),
            '__return_false',
            'bono-leads-connector'
        );

        add_settings_field(
            'api_base_url',
            __('API Base URL', 'bono-leads-connector'),
            array($this, 'render_api_base_url_field'),
            'bono-leads-connector',
            'bono_leads_connector_api'
        );

        add_settings_field(
            'api_key',
            __('API Key', 'bono-leads-connector'),
            array($this, 'render_api_key_field'),
            'bono-leads-connector',
            'bono_leads_connector_api'
        );

        add_settings_field(
            'site_id',
            __('Site ID', 'bono-leads-connector'),
            array($this, 'render_site_id_field'),
            'bono-leads-connector',
            'bono_leads_connector_api'
        );

        add_settings_field(
            'enable_debug_log',
            __('Debug Logging', 'bono-leads-connector'),
            array($this, 'render_enable_debug_log_field'),
            'bono-leads-connector',
            'bono_leads_connector_api'
        );
    }

    /**
     * Sanitize settings before storage.
     *
     * @param array $input Submitted settings.
     * @return array
     */
    public function sanitize_settings($input) {
        $input = is_array($input) ? $input : array();
        $existing = self::get_settings();
        $api_base_url = isset($input['api_base_url']) && is_scalar($input['api_base_url'])
            ? esc_url_raw(trim((string) $input['api_base_url']))
            : '';
        $api_key = isset($input['api_key']) && is_scalar($input['api_key'])
            ? sanitize_text_field((string) $input['api_key'])
            : '';
        $site_id = isset($input['site_id']) && is_scalar($input['site_id'])
            ? sanitize_text_field((string) $input['site_id'])
            : '';

        if ('' !== $api_base_url && !self::is_allowed_api_base_url($api_base_url)) {
            if (function_exists('add_settings_error')) {
                add_settings_error(
                    self::OPTION_KEY,
                    'bono_insecure_api_base_url',
                    __('API Base URL must use https://, except http://localhost, http://127.0.0.1, or http://host.docker.internal for local development.', 'bono-leads-connector'),
                    'error'
                );
            }

            $api_base_url = !empty($existing['api_base_url']) && self::is_allowed_api_base_url($existing['api_base_url'])
                ? $existing['api_base_url']
                : '';
        }

        return array(
            'api_base_url' => $api_base_url,
            // Stored as plain text for MVP. Keep this isolated for later encryption or secret storage hardening.
            'api_key' => '' !== $api_key ? $api_key : (isset($existing['api_key']) ? $existing['api_key'] : ''),
            'site_id' => $site_id,
            'enable_debug_log' => !empty($input['enable_debug_log']),
        );
    }

    /**
     * Render API base URL field.
     *
     * @return void
     */
    public function render_api_base_url_field() {
        $settings = self::get_settings();
        ?>
        <input
            type="url"
            class="regular-text"
            name="<?php echo esc_attr(self::OPTION_KEY); ?>[api_base_url]"
            value="<?php echo esc_attr($settings['api_base_url']); ?>"
            placeholder="<?php echo esc_attr__('https://api.example.com', 'bono-leads-connector'); ?>"
        />
        <?php
    }

    /**
     * Render API key field.
     *
     * @return void
     */
    public function render_api_key_field() {
        $settings = self::get_settings();
        $has_api_key = !empty($settings['api_key']);
        ?>
        <input
            type="password"
            class="regular-text"
            name="<?php echo esc_attr(self::OPTION_KEY); ?>[api_key]"
            value=""
            placeholder="<?php echo esc_attr($has_api_key ? '••••••••••••' : ''); ?>"
            autocomplete="off"
        />
        <?php if ($has_api_key) : ?>
            <p class="description"><?php esc_html_e('Leave blank to keep the existing API key.', 'bono-leads-connector'); ?></p>
        <?php endif; ?>
        <?php
    }

    /**
     * Render site ID field.
     *
     * @return void
     */
    public function render_site_id_field() {
        $settings = self::get_settings();
        ?>
        <input
            type="text"
            class="regular-text"
            name="<?php echo esc_attr(self::OPTION_KEY); ?>[site_id]"
            value="<?php echo esc_attr($settings['site_id']); ?>"
        />
        <?php
    }

    /**
     * Render debug logging field.
     *
     * @return void
     */
    public function render_enable_debug_log_field() {
        $settings = self::get_settings();
        ?>
        <label>
            <input
                type="checkbox"
                name="<?php echo esc_attr(self::OPTION_KEY); ?>[enable_debug_log]"
                value="1"
                <?php checked(!empty($settings['enable_debug_log'])); ?>
            />
            <?php esc_html_e('Log failed Bono submission attempts to the PHP error log.', 'bono-leads-connector'); ?>
        </label>
        <?php
    }

    /**
     * Render settings page.
     *
     * @return void
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $queue_counts = $this->get_queue_counts();
        $queue_latest_failed = $this->get_queue_latest_failed();
        $settings_page = BONO_PLUGIN_PATH . 'admin/settings-page.php';

        if (file_exists($settings_page)) {
            require $settings_page;
        }
    }

    /**
     * Handle the Test API Connection admin action.
     *
     * @return void
     */
    public function handle_test_api_connection() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to test this connection.', 'bono-leads-connector'));
        }

        check_admin_referer('bono_test_api_connection');

        if (!class_exists('Bono_API_Client')) {
            $this->redirect_after_test(false, null, __('Bono API client is unavailable.', 'bono-leads-connector'));
        }

        $client = new Bono_API_Client();
        $result = $client->test_connection();
        $success = !empty($result['success']) && 200 === (int) $result['status_code'];
        $message = $success
            ? __('Bono API connection succeeded.', 'bono-leads-connector')
            : $this->get_test_error_message($result);

        $this->redirect_after_test($success, isset($result['status_code']) ? $result['status_code'] : null, $message);
    }

    /**
     * Retry failed queued submissions.
     *
     * @return void
     */
    public function handle_retry_failed_submissions() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to retry failed submissions.', 'bono-leads-connector'));
        }

        check_admin_referer('bono_retry_failed_submissions');

        if (!class_exists('Bono_Submission_Queue') || !class_exists('Bono_API_Client')) {
            $this->redirect_after_queue_action('error', __('Bono queue service is unavailable.', 'bono-leads-connector'));
        }

        $queue = new Bono_Submission_Queue(new Bono_API_Client());
        $updated = $queue->retry_failed();
        $message = sprintf(
            /* translators: %d: number of submissions moved to retrying. */
            __('Marked %d failed submissions for retry.', 'bono-leads-connector'),
            (int) $updated
        );

        $this->redirect_after_queue_action('success', $message);
    }

    /**
     * Process queued submissions once.
     *
     * @return void
     */
    public function handle_process_queue_now() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to process the queue.', 'bono-leads-connector'));
        }

        check_admin_referer('bono_process_queue_now');

        if (!class_exists('Bono_Submission_Queue') || !class_exists('Bono_API_Client')) {
            $this->redirect_after_queue_action('error', __('Bono queue service is unavailable.', 'bono-leads-connector'));
        }

        $queue = new Bono_Submission_Queue(new Bono_API_Client());
        $processed = $queue->process_queue();
        $message = sprintf(
            /* translators: %d: number of queued rows processed. */
            __('Processed %d queued submissions.', 'bono-leads-connector'),
            (int) $processed
        );

        $this->redirect_after_queue_action('success', $message);
    }

    /**
     * Delete sent queue rows.
     *
     * @return void
     */
    public function handle_delete_sent_queue_rows() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to delete sent queue rows.', 'bono-leads-connector'));
        }

        check_admin_referer('bono_delete_sent_queue_rows');

        if (!class_exists('Bono_Submission_Queue') || !class_exists('Bono_API_Client')) {
            $this->redirect_after_queue_action('error', __('Bono queue service is unavailable.', 'bono-leads-connector'));
        }

        $queue = new Bono_Submission_Queue(new Bono_API_Client());
        $deleted = $queue->delete_sent();
        $message = sprintf(
            /* translators: %d: deleted row count. */
            __('Deleted %d sent queue rows.', 'bono-leads-connector'),
            (int) $deleted
        );

        $this->redirect_after_queue_action('success', $message);
    }

    /**
     * Delete failed queue rows.
     *
     * @return void
     */
    public function handle_delete_failed_queue_rows() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to delete failed queue rows.', 'bono-leads-connector'));
        }

        check_admin_referer('bono_delete_failed_queue_rows');

        if (!class_exists('Bono_Submission_Queue') || !class_exists('Bono_API_Client')) {
            $this->redirect_after_queue_action('error', __('Bono queue service is unavailable.', 'bono-leads-connector'));
        }

        $queue = new Bono_Submission_Queue(new Bono_API_Client());
        $deleted = $queue->delete_failed();
        $message = sprintf(
            /* translators: %d: deleted row count. */
            __('Deleted %d failed queue rows.', 'bono-leads-connector'),
            (int) $deleted
        );

        $this->redirect_after_queue_action('success', $message);
    }

    /**
     * Build a user-safe test error message.
     *
     * @param array $result API result.
     * @return string
     */
    private function get_test_error_message(array $result) {
        if (!empty($result['error'])) {
            return sprintf(
                /* translators: %s: API error message. */
                __('Bono API connection failed: %s', 'bono-leads-connector'),
                sanitize_text_field($result['error'])
            );
        }

        return __('Bono API connection failed.', 'bono-leads-connector');
    }

    /**
     * Redirect back to the settings page with a test result notice.
     *
     * @param bool        $success Whether the test succeeded.
     * @param int|null    $status_code HTTP status code.
     * @param string|null $message Notice message.
     * @return void
     */
    private function redirect_after_test($success, $status_code, $message) {
        $redirect_url = add_query_arg(
            array(
                'page' => 'bono-leads-connector',
                'bono_test_status' => $success ? 'success' : 'error',
                'bono_test_code' => is_null($status_code) ? '' : (string) (int) $status_code,
                'bono_test_message' => (string) $message,
            ),
            admin_url('options-general.php')
        );

        wp_safe_redirect($redirect_url);
        exit;
    }

    /**
     * Get queue counters for admin visibility.
     *
     * @return array
     */
    private function get_queue_counts() {
        $defaults = array(
            'pending' => 0,
            'retrying' => 0,
            'sent' => 0,
            'failed' => 0,
            'latest_failed_at' => '',
            'oldest_pending_age' => null,
            'health' => array(
                'state' => 'healthy',
                'label' => __('Healthy', 'bono-leads-connector'),
                'description' => __('No failed submissions and pending queue is under 10.', 'bono-leads-connector'),
            ),
        );

        if (!class_exists('Bono_Submission_Queue') || !class_exists('Bono_API_Client')) {
            return $defaults;
        }

        $queue = new Bono_Submission_Queue(new Bono_API_Client());
        $counts = $queue->get_counts();

        return wp_parse_args(is_array($counts) ? $counts : array(), $defaults);
    }

    /**
     * Get latest failed queue rows for admin visibility.
     *
     * @return array
     */
    private function get_queue_latest_failed() {
        if (!class_exists('Bono_Submission_Queue') || !class_exists('Bono_API_Client')) {
            return array();
        }

        $queue = new Bono_Submission_Queue(new Bono_API_Client());

        return $queue->get_latest_failed(5);
    }

    /**
     * Redirect back to settings page with queue action notice.
     *
     * @param string $status  success or error.
     * @param string $message User-facing message.
     * @return void
     */
    private function redirect_after_queue_action($status, $message) {
        $redirect_url = add_query_arg(
            array(
                'page' => 'bono-leads-connector',
                'bono_queue_status' => 'success' === $status ? 'success' : 'error',
                'bono_queue_message' => (string) $message,
            ),
            admin_url('options-general.php')
        );

        wp_safe_redirect($redirect_url);
        exit;
    }
}
