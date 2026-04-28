<?php
/**
 * Plugin Name: Bono Leads Connector
 * Description: Captures WordPress form submissions and sends them to Bono.
 * Version: 0.1.0
 * Author: Bono
 * Text Domain: bono-leads-connector
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BONO_PLUGIN_VERSION', '0.1.0');
define('BONO_PLUGIN_FILE', __FILE__);
define('BONO_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('BONO_PLUGIN_URL', plugin_dir_url(__FILE__));

$bono_plugin_class_file = BONO_PLUGIN_PATH . 'includes/class-bono-plugin.php';

if (file_exists($bono_plugin_class_file)) {
    require_once $bono_plugin_class_file;

    if (class_exists('Bono_Plugin')) {
        $bono_plugin = new Bono_Plugin();
        $bono_plugin->run();
    }
}
