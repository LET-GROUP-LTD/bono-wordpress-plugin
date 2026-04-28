# Bono Leads Connector

Bono Leads Connector is a standalone WordPress plugin for sending WordPress form submissions to the Bono API as normalized lead source submissions.

## Local Development

The local WordPress Docker project is separate from the plugin source.

- WordPress local project: `bono-wp-local`
- Plugin source: `bono-wordpress-plugin`
- Docker mounts the plugin into `wp-content/plugins/bono-leads-connector`

Start the local WordPress environment from `bono-wp-local`, then open the WordPress admin and activate **Bono Leads Connector** from the Plugins screen.

## Settings

Plugin settings are available in WordPress Admin under `Settings > Bono Leads`.

Configure:

- API Base URL
- API Key
- Site ID
- Debug Logging

Production API Base URLs must use `https://`. Local development may use `http://localhost`, `http://127.0.0.1`, or `http://host.docker.internal` so Docker-based WordPress can reach local services such as `http://host.docker.internal:3000`.

The MVP stores the API key in the WordPress options table as plain text. The settings UI masks saved keys and leaves the existing key unchanged when the field is submitted empty. The storage is isolated in `Bono_Settings` so it can be hardened later.

## Supported MVP Integrations

- Contact Form 7
- Elementor Pro Forms
- WPForms

All integrations are optional. If a supported form plugin is not installed, Bono Leads Connector skips that integration without causing activation errors.

## Submission Behavior

When a supported form submission succeeds, the plugin sends a normalized payload to:

`{api_base_url}/wordpress/submissions`

The plugin sends a `sourceKey` using:

`provider:formId:pageId`

Examples:

- `cf7:123:page_45`
- `elementor:contact_form:page_88`
- `wpforms:15:page_9`

Bono backend remains responsible for deciding whether the Source is connected to an active Campaign.

## Contact Validation Rule

Submission validation rule:

`Name && (Phone || Email)`

- A submission is sent to Bono only when `contact.name` exists and at least one of `contact.phone` or `contact.email` exists.
- Invalid submissions are skipped gracefully and do not break the form flow.
- Raw form fields remain in `payload.fields` even when normalized contact fields are also detected.
- Valid submissions include an `idempotencyKey` generated from provider, source key, form/page identifiers, email/phone, and the submitted minute bucket. Raw field values and the API key are not part of this key.
- Rapid duplicates with the same idempotency key are suppressed locally for 5 minutes using a WordPress transient.
- Valid submissions are rate-limited per `sourceKey` before sending or queueing. Default limit is 20 submissions per 5 minutes per `sourceKey`.
- If immediate delivery fails, the submission is stored in a durable WordPress queue table and retried by WP-Cron.

## Durable Queue + Retry

Failed deliveries are persisted in:

`{wp_prefix}bono_submission_queue`

Queue statuses:

- `pending`
- `retrying`
- `sent`
- `failed`

Processing behavior:

- Capture flow still tries immediate send first.
- On failure, submission is queued with the same `idempotencyKey`.
- WP-Cron processes up to 10 due rows every 5 minutes.
- Retry backoff is 5, 15, 30, then 60 minutes.
- After 5 total attempts, status becomes `failed`.

Settings page now includes a queue section with counts and admin controls:

- Queue health: `Healthy`, `Warning`, or `Critical`
- Oldest pending age
- Latest failed timestamp
- Latest 5 failed errors using safe metadata only
- `Retry Failed Submissions` marks failed rows as retrying with immediate next attempt.
- `Process Queue Now` runs the worker once manually.
- `Delete Sent Queue Rows` removes completed queue rows.
- `Delete Failed Queue Rows (Destructive)` removes failed queue rows.

This queue is intentionally lightweight and uses WordPress APIs only (no Action Scheduler).

Queue health states:

- `Healthy`: no failed rows and pending count is under 10.
- `Warning`: failed count is greater than 0 or pending count is 10 or more.
- `Critical`: failed count is 10 or more, or oldest pending row is older than 1 hour.

Privacy note: the admin queue view never displays payload, contact values, raw fields, or API keys. Latest failed errors show only created/updated timestamps, provider, source key, attempts, and sanitized error text.

Supported field alias families for smart detection include:

- Name: `name`, `full_name`, `fullname`, `your-name`, `contact_name`, `customer_name`, `first_name` + `last_name`, Hebrew variants like `שם`, `שם מלא`, `שם פרטי` + `שם משפחה`
- Email: `email`, `your-email`, `e-mail`, `email_address`, `emailaddress`, `mail`, `contact_email`, Hebrew variants like `דואל`, `דוא"ל`, `אימייל`, `מייל`
- Phone: `phone`, `tel`, `telephone`, `mobile`, `cellphone`, `cell`, `phone_number`, `your-phone`, `contact_phone`, Hebrew variants like `מספר טלפון`, `טלפון`, `נייד`, `פלאפון`

Example normalized payload fields:

```json
{
  "fields": {
    "your-name": "Tal Ohana",
    "your-email": "tal@example.com",
    "your-phone": "054-444-3618"
  },
  "contact": {
    "name": "Tal Ohana",
    "email": "tal@example.com",
    "phone": "0544443618"
  },
  "validation": {
    "isValid": true,
    "missing": [],
    "rule": "name && (phone || email)"
  },
  "idempotencyKey": "..."
}
```

## Provider Testing

Contact Form 7:

1. Install and activate Contact Form 7.
2. Add a CF7 form to a WordPress page.
3. Submit the form and verify the mock API receives `POST /wordpress/submissions`.

Elementor Pro Forms:

1. Install and activate Elementor Pro.
2. Add an Elementor form to a WordPress page.
3. Submit the form and verify the mock API receives `POST /wordpress/submissions` with provider `elementor`.

WPForms:

1. Install and activate WPForms.
2. Add a WPForms form to a WordPress page.
3. Submit the form and verify the mock API receives `POST /wordpress/submissions` with provider `wpforms`.

## Uninstall behavior

By default, uninstall removes plugin options but keeps queue data.

To drop the queue table on uninstall, define:

`BONO_DROP_DATA_ON_UNINSTALL` as `true`.
