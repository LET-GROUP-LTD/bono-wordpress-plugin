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

The MVP stores the API key in the WordPress options table as plain text. The storage is isolated in `Bono_Settings` so it can be hardened later.

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
  }
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
