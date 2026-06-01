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
    <?php settings_errors('bono_leads_connector_settings'); ?>

    <?php if (isset($_GET['bono_test_status'], $_GET['bono_test_message'])) : ?>
        <?php
        $notice_type = 'success' === sanitize_key(wp_unslash($_GET['bono_test_status'])) ? 'notice-success' : 'notice-error';
        $notice_message = sanitize_text_field(wp_unslash($_GET['bono_test_message']));
        $status_code = isset($_GET['bono_test_code']) ? sanitize_text_field(wp_unslash($_GET['bono_test_code'])) : '';
        ?>
        <div class="notice <?php echo esc_attr($notice_type); ?> is-dismissible">
            <p>
                <?php echo esc_html($notice_message); ?>
                <?php if ('' !== $status_code) : ?>
                    <?php
                    printf(
                        /* translators: %s: HTTP status code. */
                        esc_html__('HTTP status: %s', 'bono-leads-connector'),
                        esc_html($status_code)
                    );
                    ?>
                <?php endif; ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['bono_queue_status'], $_GET['bono_queue_message'])) : ?>
        <?php
        $queue_notice_type = 'success' === sanitize_key(wp_unslash($_GET['bono_queue_status'])) ? 'notice-success' : 'notice-error';
        $queue_notice_message = sanitize_text_field(wp_unslash($_GET['bono_queue_message']));
        ?>
        <div class="notice <?php echo esc_attr($queue_notice_type); ?> is-dismissible">
            <p><?php echo esc_html($queue_notice_message); ?></p>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['bono_connect_status'], $_GET['bono_connect_message'])) : ?>
        <?php
        $connect_notice_type = 'success' === sanitize_key(wp_unslash($_GET['bono_connect_status'])) ? 'notice-success' : 'notice-error';
        $connect_notice_message = sanitize_text_field(wp_unslash($_GET['bono_connect_message']));
        ?>
        <div class="notice <?php echo esc_attr($connect_notice_type); ?> is-dismissible">
            <p><?php echo esc_html($connect_notice_message); ?></p>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['bono_mapping_status'], $_GET['bono_mapping_message'])) : ?>
        <?php
        $mapping_notice_type = 'success' === sanitize_key(wp_unslash($_GET['bono_mapping_status'])) ? 'notice-success' : 'notice-error';
        $mapping_notice_message = sanitize_text_field(wp_unslash($_GET['bono_mapping_message']));
        ?>
        <div class="notice <?php echo esc_attr($mapping_notice_type); ?> is-dismissible">
            <p><?php echo esc_html($mapping_notice_message); ?></p>
        </div>
    <?php endif; ?>

    <?php
    $bono_settings = Bono_Settings::get_settings();
    $bono_is_connected = !empty($bono_settings['site_id']) && !empty($bono_settings['api_key']);
    ?>
    <h2><?php esc_html_e('Connect to Bono', 'bono-leads-connector'); ?></h2>
    <?php if ($bono_is_connected) : ?>
        <p>
            <span class="dashicons dashicons-yes-alt" style="color:#46b450;"></span>
            <?php
            printf(
                /* translators: %s: Bono site ID. */
                esc_html__('This site is connected to Bono (Site ID: %s). Reconnect with a new token to rotate credentials.', 'bono-leads-connector'),
                '<code>' . esc_html($bono_settings['site_id']) . '</code>'
            );
            ?>
        </p>
    <?php else : ?>
        <p><?php esc_html_e('Generate a connection token in your Bono workspace settings, then paste it here to connect this site automatically.', 'bono-leads-connector'); ?></p>
    <?php endif; ?>
    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('bono_connect_site'); ?>
        <input type="hidden" name="action" value="bono_connect_site" />
        <input
            type="text"
            class="regular-text"
            name="bono_provisioning_token"
            value=""
            placeholder="<?php echo esc_attr__('Paste your Bono connection token', 'bono-leads-connector'); ?>"
            autocomplete="off"
        />
        <?php submit_button($bono_is_connected ? __('Reconnect', 'bono-leads-connector') : __('Connect', 'bono-leads-connector'), 'primary', 'submit', false); ?>
    </form>

    <hr />

    <h2><?php esc_html_e('Manual configuration', 'bono-leads-connector'); ?></h2>
    <p><?php esc_html_e('Advanced: configure the API connection manually instead of using a connection token.', 'bono-leads-connector'); ?></p>
    <form method="post" action="options.php">
        <?php
        settings_fields('bono_leads_connector');
        do_settings_sections('bono-leads-connector');
        submit_button();
        ?>
    </form>

    <hr />

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
        <?php wp_nonce_field('bono_test_api_connection'); ?>
        <input type="hidden" name="action" value="bono_test_api_connection" />
        <?php submit_button(__('Test API Connection', 'bono-leads-connector'), 'secondary', 'submit', false); ?>
    </form>

    <hr />

    <h2><?php esc_html_e('Field Mapping', 'bono-leads-connector'); ?></h2>
    <p><?php esc_html_e('Bono auto-detects which field is the name, email, and phone. If a form is detected incorrectly, map its fields here. Forms appear automatically after their first submission.', 'bono-leads-connector'); ?></p>
    <?php
    $bono_known_forms = class_exists('Bono_Field_Mapping') ? Bono_Field_Mapping::get_known_forms() : array();
    $bono_field_mappings = class_exists('Bono_Field_Mapping') ? Bono_Field_Mapping::get_all_mappings() : array();
    $bono_mapping_roles = array(
        'name' => __('Name', 'bono-leads-connector'),
        'email' => __('Email', 'bono-leads-connector'),
        'phone' => __('Phone', 'bono-leads-connector'),
    );
    ?>
    <?php if (empty($bono_known_forms)) : ?>
        <p><em><?php esc_html_e('No forms captured yet. Submit a form on this site once, then return here to map its fields.', 'bono-leads-connector'); ?></em></p>
    <?php else : ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('bono_save_field_mappings'); ?>
            <input type="hidden" name="action" value="bono_save_field_mappings" />
            <table class="widefat striped" style="max-width:900px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Form', 'bono-leads-connector'); ?></th>
                        <?php foreach ($bono_mapping_roles as $bono_role_label) : ?>
                            <th><?php echo esc_html($bono_role_label); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bono_known_forms as $bono_form) : ?>
                        <?php
                        $bono_form_key = isset($bono_form['key']) ? (string) $bono_form['key'] : '';
                        $bono_form_fields = isset($bono_form['fields']) && is_array($bono_form['fields']) ? $bono_form['fields'] : array();
                        $bono_form_label = '' !== (string) $bono_form['form_name'] ? $bono_form['form_name'] : $bono_form['form_id'];
                        $bono_current = isset($bono_field_mappings[$bono_form_key]) ? $bono_field_mappings[$bono_form_key] : array();
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($bono_form_label); ?></strong><br />
                                <code><?php echo esc_html($bono_form['provider'] . ':' . $bono_form['form_id']); ?></code>
                            </td>
                            <?php foreach ($bono_mapping_roles as $bono_role => $bono_role_label) : ?>
                                <?php $bono_selected = isset($bono_current[$bono_role]) ? (string) $bono_current[$bono_role] : ''; ?>
                                <td>
                                    <select name="bono_field_mappings[<?php echo esc_attr($bono_form_key); ?>][<?php echo esc_attr($bono_role); ?>]">
                                        <option value=""><?php esc_html_e('Auto-detect', 'bono-leads-connector'); ?></option>
                                        <?php foreach ($bono_form_fields as $bono_field) : ?>
                                            <option value="<?php echo esc_attr($bono_field); ?>" <?php selected($bono_selected, $bono_field); ?>>
                                                <?php echo esc_html($bono_field); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php submit_button(__('Save Field Mappings', 'bono-leads-connector'), 'primary', 'submit', false); ?>
        </form>
    <?php endif; ?>

    <hr />

    <h2><?php esc_html_e('Submission Queue', 'bono-leads-connector'); ?></h2>
    <p><?php esc_html_e('Failed submissions are stored and retried automatically in the background (Action Scheduler, with a WP-Cron fallback).', 'bono-leads-connector'); ?></p>
    <?php
    $queue_health = isset($queue_counts['health']) && is_array($queue_counts['health']) ? $queue_counts['health'] : array();
    $queue_health_state = isset($queue_health['state']) ? sanitize_key($queue_health['state']) : 'healthy';
    $queue_health_label = isset($queue_health['label']) ? sanitize_text_field($queue_health['label']) : __('Healthy', 'bono-leads-connector');
    $queue_health_description = isset($queue_health['description']) ? sanitize_text_field($queue_health['description']) : '';
    $queue_health_class = 'healthy' === $queue_health_state ? 'notice-success' : ('warning' === $queue_health_state ? 'notice-warning' : 'notice-error');
    ?>
    <div class="notice <?php echo esc_attr($queue_health_class); ?> inline">
        <p>
            <strong><?php echo esc_html(sprintf(/* translators: %s: queue health state label (e.g. Healthy). */ __('Queue health: %s', 'bono-leads-connector'), $queue_health_label)); ?></strong>
            <?php if ('' !== $queue_health_description) : ?>
                <?php echo esc_html($queue_health_description); ?>
            <?php endif; ?>
        </p>
    </div>
    <ul>
        <li><?php echo esc_html(sprintf(/* translators: %d: number of pending submissions. */ __('Pending: %d', 'bono-leads-connector'), (int) $queue_counts['pending'])); ?></li>
        <li><?php echo esc_html(sprintf(/* translators: %d: number of submissions being retried. */ __('Retrying: %d', 'bono-leads-connector'), (int) $queue_counts['retrying'])); ?></li>
        <li><?php echo esc_html(sprintf(/* translators: %d: number of successfully sent submissions. */ __('Sent: %d', 'bono-leads-connector'), (int) $queue_counts['sent'])); ?></li>
        <li><?php echo esc_html(sprintf(/* translators: %d: number of failed submissions. */ __('Failed: %d', 'bono-leads-connector'), (int) $queue_counts['failed'])); ?></li>
        <?php if (isset($queue_counts['oldest_pending_age']) && is_numeric($queue_counts['oldest_pending_age'])) : ?>
            <li>
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: %s: human-readable age. */
                        __('Oldest pending age: %s', 'bono-leads-connector'),
                        human_time_diff(time() - (int) $queue_counts['oldest_pending_age'], time())
                    )
                );
                ?>
            </li>
        <?php endif; ?>
    </ul>
    <?php if (!empty($queue_counts['latest_failed_at'])) : ?>
        <p>
            <?php
            printf(
                /* translators: %s: datetime in site timezone. */
                esc_html__('Latest failed update: %s', 'bono-leads-connector'),
                esc_html(get_date_from_gmt($queue_counts['latest_failed_at'], 'Y-m-d H:i:s'))
            );
            ?>
        </p>
    <?php endif; ?>

    <h3><?php esc_html_e('Latest Failed Errors', 'bono-leads-connector'); ?></h3>
    <p><?php esc_html_e('Only safe queue metadata is shown. Payload, contact values, raw fields, and API keys are never displayed here.', 'bono-leads-connector'); ?></p>
    <?php if (!empty($queue_latest_failed)) : ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Created', 'bono-leads-connector'); ?></th>
                    <th><?php esc_html_e('Updated', 'bono-leads-connector'); ?></th>
                    <th><?php esc_html_e('Provider', 'bono-leads-connector'); ?></th>
                    <th><?php esc_html_e('Source Key', 'bono-leads-connector'); ?></th>
                    <th><?php esc_html_e('Attempts', 'bono-leads-connector'); ?></th>
                    <th><?php esc_html_e('Last Error', 'bono-leads-connector'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($queue_latest_failed as $failed_row) : ?>
                    <tr>
                        <td><?php echo esc_html(isset($failed_row['created_at']) ? get_date_from_gmt($failed_row['created_at'], 'Y-m-d H:i:s') : ''); ?></td>
                        <td><?php echo esc_html(isset($failed_row['updated_at']) ? get_date_from_gmt($failed_row['updated_at'], 'Y-m-d H:i:s') : ''); ?></td>
                        <td><?php echo esc_html(isset($failed_row['provider']) ? $failed_row['provider'] : ''); ?></td>
                        <td><?php echo esc_html(isset($failed_row['source_key']) ? $failed_row['source_key'] : ''); ?></td>
                        <td><?php echo esc_html(isset($failed_row['attempts']) ? (string) (int) $failed_row['attempts'] : '0'); ?></td>
                        <td><?php echo esc_html(isset($failed_row['last_error']) ? $failed_row['last_error'] : ''); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php else : ?>
        <p><?php esc_html_e('No failed queue rows.', 'bono-leads-connector'); ?></p>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px;">
        <?php wp_nonce_field('bono_retry_failed_submissions'); ?>
        <input type="hidden" name="action" value="bono_retry_failed_submissions" />
        <?php submit_button(__('Retry Failed Submissions', 'bono-leads-connector'), 'secondary', 'submit', false); ?>
    </form>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;">
        <?php wp_nonce_field('bono_process_queue_now'); ?>
        <input type="hidden" name="action" value="bono_process_queue_now" />
        <?php submit_button(__('Process Queue Now', 'bono-leads-connector'), 'secondary', 'submit', false); ?>
    </form>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-left:8px;">
        <?php wp_nonce_field('bono_delete_sent_queue_rows'); ?>
        <input type="hidden" name="action" value="bono_delete_sent_queue_rows" />
        <?php submit_button(__('Delete Sent Queue Rows', 'bono-leads-connector'), 'secondary', 'submit', false); ?>
    </form>

    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-left:8px;">
        <?php wp_nonce_field('bono_delete_failed_queue_rows'); ?>
        <input type="hidden" name="action" value="bono_delete_failed_queue_rows" />
        <?php submit_button(__('Delete Failed Queue Rows (Destructive)', 'bono-leads-connector'), 'delete', 'submit', false, array('onclick' => "return confirm('" . esc_js(__('Delete all failed queue rows? This cannot be undone.', 'bono-leads-connector')) . "');")); ?>
    </form>
</div>
