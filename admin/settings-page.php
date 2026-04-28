<?php
/**
 * Settings page template.
 *
 * @package BonoLeadsConnector
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1><?php esc_html_e('Bono Leads Connector', 'bono-leads-connector'); ?></h1>
    <p><?php esc_html_e('Connect this WordPress site to Bono and send form submissions as lead sources.', 'bono-leads-connector'); ?></p>

    <form method="post" action="options.php">
        <?php
        settings_fields('bono_leads_connector');
        do_settings_sections('bono-leads-connector');
        submit_button();
        ?>
    </form>
</div>
