<?php
/**
 * Minimal WordPress function/constant stubs for unit testing the plugin's pure
 * logic without a full WordPress runtime. These approximate the real behaviour
 * closely enough for the logic under test (sanitization shape, option storage).
 *
 * @package BonoLeadsConnector
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', sys_get_temp_dir() . '/');
}
if (!defined('MINUTE_IN_SECONDS')) {
    define('MINUTE_IN_SECONDS', 60);
}

/**
 * In-memory option + transient stores, reset between tests.
 */
$GLOBALS['__bono_options'] = array();
$GLOBALS['__bono_transients'] = array();

function bono_test_reset_store() {
    $GLOBALS['__bono_options'] = array();
    $GLOBALS['__bono_transients'] = array();
    $_REQUEST = array();
    $_POST = array();
}

if (!function_exists('get_option')) {
    function get_option($key, $default = false) {
        return array_key_exists($key, $GLOBALS['__bono_options']) ? $GLOBALS['__bono_options'][$key] : $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($key, $value, $autoload = null) {
        $GLOBALS['__bono_options'][$key] = $value;
        return true;
    }
}
if (!function_exists('delete_option')) {
    function delete_option($key) {
        unset($GLOBALS['__bono_options'][$key]);
        return true;
    }
}
if (!function_exists('get_transient')) {
    function get_transient($key) {
        return array_key_exists($key, $GLOBALS['__bono_transients']) ? $GLOBALS['__bono_transients'][$key] : false;
    }
}
if (!function_exists('set_transient')) {
    function set_transient($key, $value, $ttl = 0) {
        $GLOBALS['__bono_transients'][$key] = $value;
        return true;
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        $str = (string) $str;
        $str = strip_tags($str);
        $str = preg_replace('/[\r\n\t ]+/', ' ', $str);
        return trim($str);
    }
}
if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field($str) {
        $str = (string) $str;
        $str = strip_tags($str);
        return trim($str);
    }
}
if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        $key = strtolower((string) $key);
        return preg_replace('/[^a-z0-9_\-]/', '', $key);
    }
}
if (!function_exists('sanitize_email')) {
    function sanitize_email($email) {
        $email = trim((string) $email);
        return (string) filter_var($email, FILTER_SANITIZE_EMAIL);
    }
}
if (!function_exists('is_email')) {
    function is_email($email) {
        return filter_var((string) $email, FILTER_VALIDATE_EMAIL);
    }
}
if (!function_exists('esc_url_raw')) {
    function esc_url_raw($url) {
        return trim((string) $url);
    }
}
if (!function_exists('esc_attr')) {
    function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (!function_exists('esc_html')) {
    function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
}
if (!function_exists('__')) {
    function __($text, $domain = null) { return $text; }
}
if (!function_exists('esc_html__')) {
    function esc_html__($text, $domain = null) { return $text; }
}
if (!function_exists('esc_attr__')) {
    function esc_attr__($text, $domain = null) { return $text; }
}
if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = array()) {
        return array_merge($defaults, (array) $args);
    }
}
if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1) {
        return parse_url($url, $component);
    }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, $options = 0, $depth = 512) {
        return json_encode($data, $options, $depth);
    }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($value) {
        if (is_array($value)) {
            return array_map('wp_unslash', $value);
        }
        return is_string($value) ? stripslashes($value) : $value;
    }
}
if (!function_exists('wp_salt')) {
    function wp_salt($scheme = 'auth') {
        return 'bono-test-salt-' . $scheme . '-0123456789abcdef0123456789abcdef';
    }
}
if (!function_exists('home_url')) {
    function home_url($path = '') { return 'https://example.test' . $path; }
}
if (!function_exists('get_queried_object_id')) {
    function get_queried_object_id() { return 0; }
}
if (!function_exists('wp_get_referer')) {
    function wp_get_referer() { return ''; }
}
if (!function_exists('url_to_postid')) {
    function url_to_postid($url) { return 0; }
}
if (!function_exists('is_ssl')) {
    function is_ssl() { return true; }
}
if (!function_exists('current_time')) {
    function current_time($type, $gmt = 0) {
        return 'mysql' === $type ? gmdate('Y-m-d H:i:s') : time();
    }
}
