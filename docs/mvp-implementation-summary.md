# Bono Leads Connector MVP Summary

## Purpose

Create a stable WordPress plugin MVP that captures supported form submissions, normalizes them as Bono lead source submissions, and sends them to the Bono API without breaking the WordPress site when optional integrations are unavailable.

## Files Created Or Modified

- `bono-leads-connector.php`
- `includes/class-bono-plugin.php`
- `includes/class-bono-settings.php`
- `includes/class-bono-api-client.php`
- `includes/class-bono-form-capture.php`
- `includes/class-bono-cf7-capture.php`
- `includes/class-bono-elementor-capture.php`
- `includes/class-bono-wpforms-capture.php`
- `admin/settings-page.php`
- `uninstall.php`
- `README.md`
- `readme.txt`

## Manual Test Plan

1. Start the local WordPress Docker environment.
2. Activate Bono Leads Connector from WordPress Admin.
3. Open `Settings > Bono Leads`.
4. Save API Base URL, API Key, Site ID, and Debug Logging settings.
5. Click `Test API Connection` and verify Bono receives a test payload at `/wordpress/test`.
6. Install and activate Contact Form 7, submit a test form, and verify Bono receives a normalized payload at `/wordpress/submissions`.
7. Install Elementor Pro Forms later, submit a test form, and verify Bono receives a normalized payload at `/wordpress/submissions`.
8. Install WPForms later, submit a test form, and verify Bono receives a normalized payload at `/wordpress/submissions`.

## Open Questions

- Final Bono API authentication and submission contract should be verified against backend OpenAPI when available.
- Production secret storage can be hardened after the MVP validates the end-to-end flow.
