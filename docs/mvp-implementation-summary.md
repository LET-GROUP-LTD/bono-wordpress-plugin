# Bono Leads Connector MVP Summary

## Purpose

Create a stable WordPress plugin MVP that captures supported form submissions, normalizes them as Bono lead source submissions, and sends them to the Bono API without breaking the WordPress site when optional integrations are unavailable.

Submissions now include normalized contact detection and validation using:

`name && (phone || email)`

Production Hardening Phase 1 adds HTTPS enforcement for the Bono API Base URL, local Docker HTTP exceptions, masked API key display, deterministic idempotency keys, and short local duplicate suppression.

Production Hardening Phase 2 adds a durable WordPress queue table with WP-Cron retries, queue status visibility in settings, and admin controls for retrying failed rows or processing the queue immediately.

Production Hardening Phase 3 adds per-source rate protection, queue health states, oldest pending visibility, latest failed error summaries without payload/PII, and queue maintenance delete actions.

## Files Created Or Modified

- `bono-leads-connector.php`
- `includes/class-bono-plugin.php`
- `includes/class-bono-settings.php`
- `includes/class-bono-api-client.php`
- `includes/class-bono-form-capture.php`
- `includes/class-bono-cf7-capture.php`
- `includes/class-bono-elementor-capture.php`
- `includes/class-bono-wpforms-capture.php`
- `includes/class-bono-submission-queue.php`
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
19. Simulate API failure and verify failed submissions are inserted into `{wp_prefix}bono_submission_queue`.
20. Verify queue statuses and counts are visible in `Settings > Bono Leads`.
21. Restore API availability, run `Process Queue Now`, and verify queued rows move to `sent`.
22. Verify retry backoff transitions: 5, 15, 30, 60 minutes, then `failed` on attempt 5.
23. Verify `Retry Failed Submissions` changes failed rows to retrying with immediate next attempt.
24. Verify rate protection blocks more than 20 valid submissions per 5 minutes per `sourceKey`.
25. Verify queue health state renders as Healthy, Warning, or Critical based on failed/pending counts and oldest pending age.
26. Verify latest failed error summaries show only created/updated timestamps, provider, source key, attempts, and sanitized error text.
27. Verify `Delete Sent Queue Rows` removes sent rows and shows deleted count.
28. Verify `Delete Failed Queue Rows (Destructive)` removes failed rows and shows deleted count.

## Open Questions

- Final Bono API authentication and submission contract should be verified against backend OpenAPI when available.
- Production secret storage can be hardened after the MVP validates the end-to-end flow.
- Queue is implemented using WP-Cron and a custom table; Action Scheduler is intentionally not used.
- Queue table is retained on uninstall by default and only dropped when `BONO_DROP_DATA_ON_UNINSTALL === true`.
- Admin queue visibility intentionally excludes payload, contact values, raw fields, and API keys.
