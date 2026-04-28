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

        return array(
            'api_base_url' => isset($input['api_base_url']) ? esc_url_raw(trim($input['api_base_url'])) : '',
            // Stored as plain text for MVP. Keep this isolated for later encryption or secret storage hardening.
            'api_key' => isset($input['api_key']) ? sanitize_text_field($input['api_key']) : '',
            'site_id' => isset($input['site_id']) ? sanitize_text_field($input['site_id']) : '',
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
        ?>
        <input
            type="password"
            class="regular-text"
            name="<?php echo esc_attr(self::OPTION_KEY); ?>[api_key]"
            value="<?php echo esc_attr($settings['api_key']); ?>"
            autocomplete="off"
        />
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

        $settings_page = BONO_PLUGIN_PATH . 'admin/settings-page.php';

        if (file_exists($settings_page)) {
            require $settings_page;
        }
    }
}
