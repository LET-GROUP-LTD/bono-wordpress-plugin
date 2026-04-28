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
