<?php
/**
 * Main plugin bootstrap.
 *
 * @package BonoLeadsConnector
 */

if (!defined('ABSPATH')) {
    exit;
}

class Bono_Plugin {
    /**
     * Settings handler.
     *
     * @var Bono_Settings|null
     */
    private $settings = null;

    /**
     * API client.
     *
     * @var Bono_API_Client|null
     */
    private $api_client = null;

    /**
     * Load dependencies and register hooks.
     *
     * @return void
     */
    public function run() {
        $this->load_dependencies();

        if (class_exists('Bono_Settings')) {
            $this->settings = new Bono_Settings();
            $this->settings->register_hooks();
        }

        if (class_exists('Bono_API_Client')) {
            $this->api_client = new Bono_API_Client();
        }

        add_action('plugins_loaded', array($this, 'initialize_integrations'));
    }

    /**
     * Load required class files if they exist.
     *
     * @return void
     */
    private function load_dependencies() {
        $core_files = array(
            'includes/class-bono-settings.php',
            'includes/class-bono-api-client.php',
            'includes/class-bono-form-capture.php',
        );

        foreach ($core_files as $file) {
            $path = BONO_PLUGIN_PATH . $file;

            if (file_exists($path)) {
                require_once $path;
            }
        }

        if (!class_exists('Bono_Form_Capture')) {
            return;
        }

        $integration_files = array(
            'includes/class-bono-cf7-capture.php',
            'includes/class-bono-elementor-capture.php',
            'includes/class-bono-wpforms-capture.php',
        );

        foreach ($integration_files as $file) {
            $path = BONO_PLUGIN_PATH . $file;

            if (file_exists($path)) {
                require_once $path;
            }
        }
    }

    /**
     * Initialize optional form integrations after other plugins load.
     *
     * @return void
     */
    public function initialize_integrations() {
        if (!$this->api_client instanceof Bono_API_Client) {
            return;
        }

        if (class_exists('Bono_CF7_Capture')) {
            $cf7_capture = new Bono_CF7_Capture($this->api_client);
            $cf7_capture->register_hooks();
        }

        if (class_exists('Bono_Elementor_Capture')) {
            $elementor_capture = new Bono_Elementor_Capture($this->api_client);
            $elementor_capture->register_hooks();
        }

        if (class_exists('Bono_WPForms_Capture')) {
            $wpforms_capture = new Bono_WPForms_Capture($this->api_client);
            $wpforms_capture->register_hooks();
        }
    }
}
