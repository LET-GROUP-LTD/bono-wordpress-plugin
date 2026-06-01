=== Bono Leads Connector ===
Contributors: bono
Tags: leads, forms, contact form 7, elementor, gravity forms, fluent forms, forminator
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Captures supported WordPress form submissions and sends normalized lead source payloads to Bono.

== Description ==

Bono Leads Connector is a standalone WordPress plugin for sending supported WordPress form submissions to the Bono API.

Supports Contact Form 7, Elementor Pro Forms, WPForms, Gravity Forms, Fluent Forms, and Forminator when those plugins are installed (missing integrations are skipped safely), plus an opt-in generic capture for custom/theme forms.

== Installation ==

1. Place the plugin in `wp-content/plugins/bono-leads-connector`.
2. Activate Bono Leads Connector from WordPress Admin.
3. Configure settings under `Settings > Bono Leads`.

== Build ==

Dependencies are managed with Composer. Before packaging the plugin, run
`composer install --no-dev` from the plugin root to vendor Action Scheduler
into `vendor/`. When `vendor/` is absent the submission queue degrades
gracefully to WP-Cron.

== Changelog ==

= 0.3.0 =
* Added capture integrations for Gravity Forms, Fluent Forms, and Forminator
  (auto-detected; skipped when the plugin is not installed).
* Added opt-in generic capture for custom/theme forms: choose forms by CSS
  selector in settings or a data-bono-capture attribute. A non-blocking frontend
  script forwards submissions to a plugin REST endpoint (the API key stays
  server-side); the same validation, dedup, rate-limit, and queue apply.

= 0.2.0 =
* Added one-click "Connect to Bono": paste a provisioning token from your Bono
  workspace and the site is registered automatically (site_id + API key issued
  and stored, key encrypted at rest). Manual configuration remains available.
* Submission queue now uses Action Scheduler for the recurring sweep, with a
  prompt async retry after a failed delivery (post-request loopback) instead
  of waiting for the next 5-minute tick or for site traffic. Falls back to
  WP-Cron when Action Scheduler is not bundled.
* Queue rows are auto-pruned (sent after 7 days, failed/dead-letter after 30)
  so the table no longer grows unbounded.
* Fixed a timezone bug where retry backoff was bypassed in non-UTC timezones
  (next_attempt_at is stored in GMT but was compared against local time).
* The API key is now encrypted at rest (libsodium, key derived from wp-config
  salts) instead of being stored as plaintext. Existing keys migrate on the
  next settings save.

= 0.1.0 =
* Initial MVP.
