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
</div>
