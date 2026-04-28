# Bono Leads Connector MVP Summary

## Purpose

Create a stable WordPress plugin MVP that captures supported form submissions, normalizes them as Bono lead source submissions, and sends them to the Bono API without breaking the WordPress site when optional integrations are unavailable.

Submissions now include normalized contact detection and validation using:

`name && (phone || email)`

Production Hardening Phase 1 adds HTTPS enforcement for the Bono API Base URL, local Docker HTTP exceptions, masked API key display, deterministic idempotency keys, and short local duplicate suppression.

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
5. Verify `https://` API Base URLs are accepted.
6. Verify local development URLs using `http://localhost`, `http://127.0.0.1`, or `http://host.docker.internal` are accepted.
7. Verify non-local insecure URLs such as `http://example.com` are rejected or preserve the previous valid value with a settings error.
8. Verify a saved API key is masked in the settings UI and an empty API key submission keeps the existing key.
9. Click `Test API Connection` and verify Bono receives a test payload at `/wordpress/test`.
10. Install and activate Contact Form 7, submit a test form, and verify Bono receives a normalized payload at `/wordpress/submissions`.
11. Install Elementor Pro Forms later, submit a test form, and verify Bono receives a normalized payload at `/wordpress/submissions`.
12. Install WPForms later, submit a test form, and verify Bono receives a normalized payload at `/wordpress/submissions`.
13. Verify invalid submissions are skipped and not sent when `name` is missing.
14. Verify invalid submissions are skipped and not sent when both `phone` and `email` are missing.
15. Verify raw submitted fields are still present under `payload.fields` when `payload.contact` is detected.
16. Verify valid submission payloads include `idempotencyKey`.
17. Verify outbound submission requests include `X-Bono-Idempotency-Key` and `X-Bono-Plugin-Version`.
18. Verify rapid duplicate submissions with the same idempotency key are skipped for 5 minutes.

## Open Questions

- Final Bono API authentication and submission contract should be verified against backend OpenAPI when available.
- Production secret storage can be hardened after the MVP validates the end-to-end flow.
- Durable retry/queue handling remains future work; Phase 1 intentionally does not add Action Scheduler or replay failed submissions.
