<?php
/**
 * Plugin uninstall cleanup.
 *
 * @package BonoLeadsConnector
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('bono_leads_connector_settings');

if (defined('BONO_DROP_DATA_ON_UNINSTALL') && true === BONO_DROP_DATA_ON_UNINSTALL) {
    global $wpdb;

    if (isset($wpdb->prefix)) {
        $table_name = $wpdb->prefix . 'bono_submission_queue';
        $wpdb->query("DROP TABLE IF EXISTS {$table_name}");
    }
}
