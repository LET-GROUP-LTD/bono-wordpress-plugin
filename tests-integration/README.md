# Forms Integration Test Suite

Boots real WordPress (via `@wordpress/env`) with the Bono plugin + the 4 free form
plugins, fires each provider's submission hook, and asserts the normalized payload
against a recording mock backend. Does not touch staging or create real leads.

## Providers covered

- **Real installs:** Contact Form 7 5.9.8, WPForms Lite 1.9.1.5, Fluent Forms 5.1.0, Forminator 1.36.0.
- **Simulated (paid, not installed):** Elementor Pro, Gravity Forms — driven by firing
  their hook directly with a faithful native object (see `triggers/elementor.php`, `triggers/gravity.php`).
- **Generic capture:** driven over the REST route `bono/v1/capture` with a minted nonce (see `triggers/generic-nonce.php`).

## Layout

```
tests-integration/
  mock-backend/
    server.mjs        — recording mock Bono API; records POSTs to /api/wordpress/*,
                        control endpoints /__control/{reset,requests,response}
    server.test.mjs   — self-tests for the mock server
  provision/
    wait-for-wp.mjs   — polls WordPress until it is ready
    seed-forms.php    — creates one form per provider, stores IDs in WP option bono_test_form_ids
    configure-plugin.php — points the plugin at the mock backend
  triggers/           — one PHP trigger per provider (run via wp eval-file);
                        inputs passed through WP option bono_test_trigger_env
    elementor.php
    fluent.php
    forminator.php
    generic-nonce.php
    gravity.php
    queue-count.php   — helper: reads the async-queue depth
    wpforms.php
  runner/
    helpers.mjs       — shared utilities (resetMock, pollRequests, wp-env exec wrapper)
    cf7.test.mjs
    elementor.test.mjs
    fluent.test.mjs
    forminator.test.mjs
    generic.test.mjs
    gravity.test.mjs
    retry.test.mjs    — non-2xx retry / async-queue enqueue tests
    wpforms.test.mjs
```

## Run locally

1. `npm install`
2. Start the mock backend:
   ```bash
   MOCK_PORT=3001 node tests-integration/mock-backend/server.mjs &
   ```
3. Start WordPress:
   ```bash
   npm run env:start
   ```
4. Provision (seed forms + configure plugin):
   ```bash
   npm run provision
   ```
5. Run the full suite:
   ```bash
   npm run test:integration
   ```
   Or run a single file:
   ```bash
   node --test tests-integration/runner/cf7.test.mjs
   ```

Stop with `npm run env:stop`. Reset everything (wipes the Docker volumes) with `npm run env:clean`.

## npm scripts reference

| Script | What it does |
|---|---|
| `env:start` | `wp-env start` — boots WordPress + MySQL in Docker |
| `env:stop` | `wp-env stop` |
| `env:clean` | `wp-env clean all` — destroys volumes |
| `mock` | `node tests-integration/mock-backend/server.mjs` |
| `test:mock` | Self-tests the mock server (`node --test tests-integration/mock-backend/`) |
| `test:integration` | Full runner suite (`node --test tests-integration/runner/`) |
| `provision` | wait-for-wp → seed-forms.php → configure-plugin.php |

## Notes

- The plugin posts to `http://host.docker.internal:3001/api/wordpress/submissions`;
  `host.docker.internal` is explicitly allowed in the plugin's HTTP allowlist
  (`class-bono-api-client.php`, `class-bono-settings.php`).
- Tests use per-run-unique emails (`dana-${Date.now()}@example.com`) because the
  plugin de-duplicates submissions within a per-minute idempotency window — reusing
  the same email+form within ~5 minutes would cause the second submission to be skipped.
- Pinned plugin versions and the WordPress core version (6.5, PHP 8.1) live in
  `.wp-env.json`. If a download URL 404s, bump the version there.
- CF7 does not have a `triggers/cf7.php` — it is exercised via the WP REST API
  directly from the test runner (no hook-firing shim needed).

## CI

`.github/workflows/integration.yml` runs on every PR and every push to `main`
(parallel; not in the deploy gate). The workflow:

1. Maps `host.docker.internal → 127.0.0.1` in `/etc/hosts` (Linux runner has no
   automatic loopback alias — this is the known-fragile step).
2. Starts the mock backend, then self-tests it (`npm run test:mock`).
3. Starts `wp-env`, injects the host gateway into the WordPress container.
4. Runs `npm run provision` then `npm run test:integration`.
5. On failure, tails `wp-content/debug.log` for diagnostics.
