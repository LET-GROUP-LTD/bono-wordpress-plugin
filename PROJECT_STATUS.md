# Bono Leads Connector — Project Status & Roadmap

**Current version:** `0.6.0`
**Status:** Feature-complete against the maturation roadmap (P0 + P1 + P2 all shipped to `main`).
**Last updated:** 2026-06-01

This plugin captures form submissions from client WordPress sites and delivers them
to Bono as lead sources. It is distributed privately (not via WordPress.org) and
installed as part of onboarding a Bono customer.

---

## 1. Current state — what's implemented

### Delivery (reliable, never lose a lead)
- **Action Scheduler** queue with async retry + exponential backoff; **WP-Cron fallback**
  when Action Scheduler is unavailable. `includes/class-bono-submission-queue.php`
- **Dead-letter + TTL**: failed rows retained 30 days, sent rows 7 days, then auto-pruned.
- Deterministic **idempotency keys** (per-minute fingerprint) on both plugin and backend.
- Short-window **rate limiting** per source key.

### Form coverage
- Contact Form 7, WPForms, Elementor Pro, **Gravity Forms, Fluent Forms, Forminator**.
- **Generic opt-in capture**: a non-blocking JS listener on admin-chosen CSS selectors
  (or any form with a `data-bono-capture` attribute) → nonce-authenticated REST endpoint.
  `includes/class-bono-generic-capture.php`, `assets/js/bono-generic-capture.js`
- Hebrew-aware heuristic contact detection (name / email / phone), with a **per-form
  manual override UI** when the heuristic guesses wrong. `includes/class-bono-field-mapping.php`

### Connection & provisioning
- **One-click connect** (token exchange): paste a provisioning token from the Bono
  workspace → the plugin registers the site and receives `site_id` + API key automatically.
  `includes/class-bono-settings.php` (`handle_connect_site`), `includes/class-bono-api-client.php`
- **API key encrypted at rest** with libsodium (key derived from `wp_salt` via HKDF;
  envelope prefix `bono:enc:v1:`); transparent migration of legacy plaintext.

### Self-update (private channel)
- Bundles `plugin-update-checker`; reads release ZIPs from a **public distribution repo**
  (`bono-wordpress-plugin-dist`) tokenlessly. `bono-leads-connector.php`
- `.github/workflows/release.yml` builds a clean ZIP on a `v*` tag and publishes the
  Release to the dist repo. **Requires one-time operator setup — see §4.**

### Observability
- **Structured JSON logging** (gated behind a debug flag; PII never logged).
  `Bono_Form_Capture::format_log_entry()`
- **Status endpoint** `GET /wp-json/bono/v1/status` (authenticated with the site's API
  key) exposing version, connection state, and queue counts/health — never lead data.
  `includes/class-bono-status-endpoint.php`

### Internationalization
- Full **Hebrew (`he_IL`)** translation: `.po` + `.mo` + `.l10n.php` in `languages/`.
  Text domain loaded on `init`. English is the source/fallback.

---

## 2. Quality gates (CI)

Every PR and push to `main` runs `.github/workflows/ci.yml`:

| Gate | What |
|------|------|
| Lint PHP | `php -l` on all source, matrix PHP 7.4 / 8.0 / 8.1 / 8.2 / 8.3 |
| Composer integrity | `validate` + `install --no-dev` + every declared dep vendored |
| Lint JS | `node --check` on `assets/**/*.js` |
| PHPUnit | 26 tests / 84 assertions, PHP 7.4 + 8.3 (`composer test`) |
| PHPCS | full **WordPress-Extra** standard, blocks on errors (`composer phpcs`) |
| PHPStan | level 5 with WordPress stubs (`composer phpstan`) |

Tests use a lightweight WP-function **stub bootstrap** (no `wp-env`/Docker) — see `tests/`.

---

## 3. Local development

```bash
composer install            # installs runtime + dev deps (PHPUnit, PHPCS, PHPStan)
composer test               # PHPUnit
composer phpcs              # WordPress-Extra lint (errors block; warnings informational)
composer phpcbf             # auto-fix formatting
composer phpstan            # static analysis (level 5)
```

- `vendor/` is git-ignored and rebuilt by `composer install`; the release workflow
  vendors production deps into the shipped ZIP.
- `BONO_DEFAULT_API_BASE_URL` currently points at **staging** (`https://dev.bono.let.co.il/api`).
  Switch to production at release. Overridable per-site via `BONO_API_BASE_URL` in `wp-config.php`.

---

## 4. ⚠️ Operator setup required to activate auto-updates

These are one-time GitHub-admin tasks (owner-level), not code:

1. Create a **public** repo `LET-GROUP-LTD/bono-wordpress-plugin-dist` (with an initial README commit).
2. Add a repo secret `DIST_REPO_TOKEN` to **this** repo — a fine-grained PAT with
   **Contents: read+write on the dist repo only** (the default `GITHUB_TOKEN` can't write cross-repo).
3. Cut the first release: `git tag v0.6.0 && git push origin v0.6.0` → `release.yml` publishes the ZIP.

Until this is done, the plugin works fully but cannot self-update.

---

## 5. Backlog — future work (none urgent)

| Item | Notes | Priority |
|------|-------|----------|
| Backend consumption of `/status` | Have Bono periodically poll connected sites for delivery reconciliation. Cross-repo (backend), needs product decision. | Medium |
| HMAC payload signing | Considered in P1, deferred — the bearer API key is sufficient today. Adds anti-tamper/anti-replay beyond idempotency. Cross-repo (backend must verify). | Low |
| Elementor free-vs-Pro notice | Warn in admin when Elementor is active but Pro (forms) is not. | Low |
| Additional locales | Translation tooling is in place; add `languages/*-<locale>.po` + compile. | As needed |
| Field-mapping for nested/array fields | Current mapping targets flat field keys. | Low |

---

## 6. Conventions

- **OpenAPI is the contract** with the backend; the submission payload (incl. the
  explicit `contact` object) matches `POST /api/wordpress/submissions`. Don't change
  the payload shape without coordinating the backend.
- **No secrets in logs.** Logging is gated behind the debug flag and restricted to a
  non-sensitive allow-list.
- **PHPCS exclusions** in `phpcs.xml.dist` are limited to two documented, architecturally
  inapplicable sniffs (passive third-party form capture has no nonce; dynamic table name
  in prepared queries). Don't broaden without justification.
- Default branch is `main`; direct pushes are gated → open a PR (CI must pass).
- Releases are tag-driven (`v*`); keep the header `Version:`, the `BONO_PLUGIN_VERSION`
  constant, and the tag in sync (the release workflow enforces this).
