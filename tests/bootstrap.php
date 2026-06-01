<?php
/**
 * PHPUnit bootstrap: load WP stubs, then the plugin classes under test.
 *
 * @package BonoLeadsConnector
 */

require __DIR__ . '/wp-stubs.php';

if (!defined('BONO_PLUGIN_VERSION')) {
    define('BONO_PLUGIN_VERSION', 'test');
}

$bono_includes = dirname(__DIR__) . '/includes';

require $bono_includes . '/class-bono-field-mapping.php';
require $bono_includes . '/class-bono-api-client.php';
require $bono_includes . '/class-bono-form-capture.php';
require $bono_includes . '/class-bono-gravity-capture.php';
require $bono_includes . '/class-bono-fluent-capture.php';
require $bono_includes . '/class-bono-forminator-capture.php';
require $bono_includes . '/class-bono-settings.php';
