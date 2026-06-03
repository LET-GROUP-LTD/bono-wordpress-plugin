# WordPress Forms Integration Testing — Design

**Date:** 2026-06-03
**Status:** Approved design (pending spec review)
**Scope:** `bono-wordpress-plugin` only. Adds a self-contained, reproducible integration-test suite that exercises the plugin against real WordPress + the supported form plugins. No product-code changes are planned (unless a real bug is found, in which case it is fixed with a regression test).

---

## Problem

The plugin claims support for **6 form providers**, each a separate WordPress plugin, plus a generic JS capture mechanism. Verified from code (`includes/class-bono-*-capture.php`):

| # | Form plugin | `provider` | Hook | Availability |
|---|-------------|-----------|------|--------------|
| 1 | Contact Form 7 | `cf7` | `wpcf7_before_send_mail` | Free |
| 2 | WPForms | `wpforms` | `wpforms_process_complete` | Lite free |
| 3 | Elementor **Pro** Forms | `elementor` | `elementor_pro/forms/new_record` | **Paid (Pro only)** |
| 4 | Gravity Forms | `gravity` | `gform_after_submission` | **Paid (no free tier)** |
| 5 | Fluent Forms | `fluent` | `fluentform/submission_inserted` | Free |
| 6 | Forminator | `forminator` | `forminator_custom_form_submit_before_set_fields` | Free |
| + | Generic capture | `generic` | JS on CSS selectors → REST `/wp-json/bono/v1/capture` | — |

The **existing** PHPUnit suite (`tests/CapturePipelineTest.php` et al.) validates extraction/mapping/normalization logic, but it **fakes the form layer** with WP stubs and synthetic payloads — it never runs against the real form plugins. There is no test that the plugin's registered capture hooks fire correctly and emit the right normalized payload when a real CF7/WPForms/Fluent/Forminator submission occurs.

## Goal

A reproducible, CI-runnable integration suite that boots a real WordPress with the Bono plugin + the free form plugins installed, fires each provider's real submission hook programmatically, and asserts the plugin emits the correct normalized payload — covering behavior and edge cases across all 6 providers.

---

## Decisions (locked)

- **Paid plugins handled by simulation.** The 4 free plugins (CF7, WPForms Lite, Fluent Forms, Forminator) are installed for real. Elementor Pro and Gravity Forms (paid, not installable for free with forms) are exercised by firing their documented hook directly with a faithfully-constructed native object. Fully reproducible, no licenses, CI-safe.
- **Primary deliverable: automated integration suite + documented environment** (not a manual-only QA env).
- **Verification target: a recording mock backend.** The suite asserts on the HTTP payload the plugin *sends* (captured by an extended `mock-bono-api`), not on a real Bono lead. Deterministic, no real leads, runs in CI.
- **Submission mechanism: programmatic hook firing via `wp eval` (Approach A).** For each free plugin, a PHP trigger drives the plugin's submission path so its **real** registered hook fires; for the 2 paid plugins, the trigger fires the hook directly with a representative object. No headless browser.
- **Location & tooling: inside the plugin repo, using `@wordpress/env`.** A self-contained suite in `tests-integration/`, runnable as a separate CI job, versioned with the plugin.

---

## Design

### Architecture

A live WordPress (wp-env) hosts the plugin-under-test and the 4 free form plugins. A Node recording mock backend stands in for Bono. A Node test runner orchestrates each case: reset the mock → fire one provider's submission hook (via `wp-env run cli wp eval-file`) with parametrized input → read the recorded request → assert on the normalized payload.

```
node test runner ──(1) reset──▶ mock backend (records requests)
       │                              ▲
       │ (2) wp-env run cli           │ (3) plugin capture hook
       │     wp eval-file trigger     │     → POST /api/wordpress/submissions
       ▼                              │
   WordPress (wp-env) ── Bono plugin ─┘
   + CF7/WPForms/Fluent/Forminator
```

### Components

1. **`.wp-env.json`** (plugin repo root) — wp-env config: mounts the plugin as plugin-under-test; installs the 4 free form plugins by wp.org slug (`contact-form-7`, `wpforms-lite`, `fluentform`, `forminator`); pins PHP/WP versions. Reproducibility caveat: slugs resolve to latest; pin via zip URLs if version drift causes flakiness.

2. **`tests-integration/mock-backend/server.mjs`** — recording mock Bono API (Node, stdlib `http`, no deps):
   - `POST /api/wordpress/submissions` → records `{ headers, body, receivedAt }` to an in-memory log; returns a configurable response (default `200 { success:true, leadId:"mock-…" }`; settable to 409/500 for edge cases).
   - `POST /api/wordpress/sites/register` → returns canned `{ site_id, api_key }` (available if a test wants the auto-provision path; default flow sets options directly).
   - Control endpoints: `POST /__control/reset` (clear log + reset response), `GET /__control/requests` (return recorded log), `POST /__control/response` (set the next response status/body).
   - Reachable from the WP container via `host.docker.internal`.

3. **`tests-integration/provision/`**:
   - `setup.sh` — `wp-env start`, wait for WP readiness, run seed + configure.
   - `seed-forms.php` (`wp eval-file`) — creates one representative form per free provider with name/email/phone (+ message) fields; persists the created form IDs to a known WP option (`bono_test_form_ids`) for triggers to read.
   - `configure-plugin.php` (`wp eval-file`) — sets the Bono plugin options: `api_base_url` → mock URL, `api_key`, `site_id`, `enable_debug_log` on.

4. **`tests-integration/triggers/`** — one PHP trigger per provider, run via `wp eval-file`, parametrized through env vars (e.g. `BONO_TEST_NAME`, `BONO_TEST_EMAIL`, `BONO_TEST_PHONE`, `BONO_TEST_CASE`):
   - `cf7.php`, `wpforms.php`, `fluent.php`, `forminator.php` — drive each plugin's submission path so its **real** registered hook fires; the Bono capture handler does the rest.
   - `elementor.php`, `gravity.php` — **simulated**: fire `elementor_pro/forms/new_record` / `gform_after_submission` with a faithfully-constructed representative object (paid plugins not installed). Documented as simulated.
   - Where a free plugin lacks a clean programmatic submission entry, the trigger falls back to firing its hook with a faithfully-constructed native object — documented inline as semi-simulated.

5. **`tests-integration/runner/`** — Node test runner (`node:test`). Per case: reset mock → (optionally set mock response) → `wp-env run cli wp eval-file triggers/<provider>.php` with params → `GET /__control/requests` → assert.

### Assertions (every case)

`provider` correct · `sourceKey` matches `provider:formId:pageId` · `contact.name` / `contact.email` / `contact.phone` normalized as expected · `idempotencyKey` present · `validation.isValid` as expected · no unexpected/extra fields (redaction holds).

### Test matrix (behavior + edge cases)

| Case | What it verifies | Applies to |
|------|------------------|-----------|
| Happy path | name+email+phone → correct contact + provider + sourceKey | all 6 |
| Hebrew name (RTL) | name preserved as-is | CF7 + one more |
| First+Last separate fields | combined into one name | WPForms / Gravity |
| Phone normalization | Israeli `050-…` passes through correctly in payload | CF7 / Fluent |
| Missing required contact (no email & no phone) | submission **skipped** — 0 requests recorded | CF7 / Forminator |
| Field-mapping override | manual mapping respected | CF7 |
| Duplicate within idempotency window | second submission skipped | CF7 |
| Generic capture | `generic` provider via REST `/bono/v1/capture` + nonce | generic |
| Mock returns non-2xx (409/500) | plugin enqueues for retry (assert queue row / async scheduled) | CF7 |

### CI

A separate workflow `integration.yml` (Docker-heavy): `npm i` (wp-env + runner) → `wp-env start` → provision → run suite. Runs on PR + main, **does not block deploy** (consistent with the existing CI philosophy: heavy suites run in parallel, not in the deploy gate). The existing static gate (lint / PHPCS / PHPUnit / PHPStan) is unchanged.

---

## Testing / acceptance

- `tests-integration` suite green locally: `wp-env start` + provision + runner pass for all matrix cases.
- Each free provider's **real** hook fires and produces the expected normalized payload at the mock.
- Each simulated paid provider's hook produces the expected payload.
- Edge cases behave as specified (skip-on-missing-contact records 0 requests; duplicate skipped; non-2xx enqueues retry).
- New `integration.yml` CI job passes on a real run.
- Existing static gate remains green (no product-code regressions). If a real product bug is surfaced, it is fixed with a regression test and noted.

## Out of scope

- Real Elementor Pro / Gravity Forms installs (require paid licenses) — exercised by simulation only.
- Headless-browser (Playwright) front-end submission tests — possible future addition.
- Changes to plugin product code, except a fix (with regression test) if the suite surfaces a genuine bug.
- Backend-side behavior (the mock stands in for Bono; full plugin→staging E2E was already verified manually on 2026-06-02).
