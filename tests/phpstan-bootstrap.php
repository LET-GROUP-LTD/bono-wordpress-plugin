<?php
/**
 * PHPStan bootstrap: declare the plugin constants that the main plugin file
 * defines at runtime, so analysing the class files in isolation resolves them.
 *
 * @package BonoLeadsConnector
 */

define('BONO_PLUGIN_VERSION', '0.0.0');
define('BONO_PLUGIN_FILE', __FILE__);
define('BONO_PLUGIN_PATH', __DIR__ . '/');
define('BONO_PLUGIN_URL', 'https://example.test/wp-content/plugins/bono-leads-connector/');
define('BONO_DEFAULT_API_BASE_URL', 'https://example.test/api');
