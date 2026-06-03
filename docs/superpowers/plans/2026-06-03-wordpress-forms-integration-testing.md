# WordPress Forms Integration Testing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a self-contained, reproducible integration-test suite (`tests-integration/`) that boots real WordPress via `@wordpress/env` with the 4 free form plugins installed, fires each of the 6 providers' real submission hooks, and asserts the plugin emits the correct normalized payload against a recording mock backend.

**Architecture:** A Node recording mock backend stands in for Bono. `@wordpress/env` hosts WordPress + the Bono plugin (mounted) + CF7/WPForms-Lite/Fluent/Forminator (by slug). A Node test runner (`node:test`) resets the mock, fires one provider's submission hook via `wp-env run cli wp eval-file triggers/<provider>.php` (CF7 uses the real CF7 REST feedback endpoint; paid Elementor/Gravity are simulated by firing their hook directly), reads the recorded request, and asserts the normalized payload. A separate CI workflow runs it.

**Tech Stack:** Node (stdlib `http`, `node:test`), `@wordpress/env` (Docker), wp-cli (`wp eval-file`), PHP triggers, GitHub Actions.

---

## Reference facts (verified from plugin code — do not re-derive)

- **Outgoing payload keys** (`build_submission_payload`, `class-bono-form-capture.php`): `provider`, `sourceKey`, `formId`, `formName`, `pageId`, `pageUrl`, `submittedAt`, `fields`, `contact` (`{name,email,phone}`), `validation` (`{isValid, missing}`), `idempotencyKey`.
- **`sourceKey` format:** `create_source_key($provider,$form_id,$page_id)` → `"provider:formId:pageId"` (pageId may be e.g. `page_9` or omitted/null).
- **Phone normalization:** `"050-1234567"` → `"0501234567"` (formatting stripped, plugin-side).
- **Hebrew names** are preserved verbatim (`"דנה כהן"`).
- **Skip-on-missing-contact:** when neither email nor phone is present, `send_payload` sets `validation.isValid = false` and **returns without any HTTP request**. `validation.missing` lists missing roles (e.g. contains `'name'`).
- **HTTP target:** `Bono_API_Client::send_submission` POSTs to `build_endpoint(api_base_url, '/wordpress/submissions')` with headers `X-Bono-Api-Key`, `X-Bono-Site-Id`.
- **API base URL guard:** `is_allowed_api_base_url` allows any `https` host, and `http` only for hosts `localhost`, `127.0.0.1`, `host.docker.internal`. **`Bono_Settings::is_allowed_api_base_url` is consulted first if present — confirm it shares this allowlist (it mirrors the same array).**
- **Provider hook signatures (real registered handlers):**
  - CF7 — `handle_before_send_mail($contact_form)` on `wpcf7_before_send_mail`; reads `WPCF7_Submission::get_instance()->get_posted_data()`.
  - WPForms — `handle_process_complete($fields,$entry,$form_data,$entry_id)` on `wpforms_process_complete`.
  - Fluent — `handle_submission_inserted($entry_id,$form_data,$form)` on `fluentform/submission_inserted`; `$form_data` = input-name→value.
  - Forminator — `handle_before_set_fields($entry,$form_id,$field_data_array)` on `forminator_custom_form_submit_before_set_fields`; `$field_data_array` = list of `['name'=>..., 'value'=>...]`.
  - Elementor (**simulated**) — `handle_new_record($record,$handler)` on `elementor_pro/forms/new_record`; `$record` is an object exposing `->get('fields')`, `->get('form_settings')`, `->get('page_id')`, `->get('post_id')`, `->get('meta')`.
  - Gravity (**simulated**) — `handle_after_submission($entry,$form)` on `gform_after_submission`; `$entry` keyed by field id; `$form` has `id`, `title`, `fields[]`; `$entry['source_url']`.
- **Generic capture:** REST route namespace `bono/v1`, route `capture`, nonce action constant `Bono_Generic_Capture::NONCE_ACTION`, nonce accepted via param `_bono_nonce` or header `X-Bono-Nonce`.
- **Known-good native entry shapes** already exist in `tests/CapturePipelineTest.php` (Gravity `$entry`/`$form`, Fluent `$form_data`, Forminator `$field_data`, generic flat assoc) — reuse them verbatim as trigger inputs.

---

## File Structure

```
.wp-env.json                                   # wp-env config (root)
package.json                                   # devDeps + npm scripts (root)
tests-integration/
  mock-backend/
    server.mjs                                 # recording mock Bono API
    server.test.mjs                            # node:test for the mock itself
  provision/
    seed-forms.php                             # wp eval-file: create one form per provider, store IDs in option bono_test_form_ids
    configure-plugin.php                       # wp eval-file: point Bono plugin at the mock
    wait-for-wp.mjs                            # poll wp-env until WP responds
  triggers/
    cf7.php                                    # (full real path note) helper to read seeded CF7 form id
    wpforms.php                                # fire wpforms_process_complete with real form context
    fluent.php                                 # fire fluentform/submission_inserted
    forminator.php                             # fire forminator_custom_form_submit_before_set_fields
    elementor.php                              # simulated: fire elementor_pro/forms/new_record with a Record double
    gravity.php                                # simulated: fire gform_after_submission
    generic.php                                # (note) generic uses REST, driven from the runner over HTTP
  runner/
    helpers.mjs                                # resetMock, getRequests, setMockResponse, wpEval, cf7Submit
    cf7.test.mjs                               # CF7 happy + edge cases
    wpforms.test.mjs
    fluent.test.mjs
    forminator.test.mjs
    elementor.test.mjs
    gravity.test.mjs
    generic.test.mjs
    retry.test.mjs                             # non-2xx → enqueue
  README.md                                    # how to run locally
.github/workflows/integration.yml             # CI job
```

---

### Task 1: Scaffold npm + wp-env config

**Files:**
- Create: `package.json`
- Create: `.wp-env.json`
- Modify: `.gitignore`

- [ ] **Step 1: Create `package.json`**

```json
{
  "name": "bono-leads-connector-integration",
  "private": true,
  "description": "Integration test harness for the Bono Leads Connector plugin",
  "scripts": {
    "env:start": "wp-env start",
    "env:stop": "wp-env stop",
    "env:clean": "wp-env clean all",
    "mock": "node tests-integration/mock-backend/server.mjs",
    "test:mock": "node --test tests-integration/mock-backend/",
    "test:integration": "node --test tests-integration/runner/",
    "provision": "node tests-integration/provision/wait-for-wp.mjs && wp-env run cli wp eval-file wp-content/plugins/bono-leads-connector/tests-integration/provision/seed-forms.php && wp-env run cli wp eval-file wp-content/plugins/bono-leads-connector/tests-integration/provision/configure-plugin.php"
  },
  "devDependencies": {
    "@wordpress/env": "^10.0.0"
  }
}
```

- [ ] **Step 2: Create `.wp-env.json`**

The Bono plugin is mounted at `.` (repo root). The 4 free form plugins load by wp.org slug. WP/PHP pinned for reproducibility.

```json
{
  "core": "WordPress/WordPress#6.5",
  "phpVersion": "8.1",
  "plugins": [
    ".",
    "https://downloads.wordpress.org/plugin/contact-form-7.6.0.zip",
    "https://downloads.wordpress.org/plugin/wpforms-lite.1.9.0.zip",
    "https://downloads.wordpress.org/plugin/fluentform.5.1.0.zip",
    "https://downloads.wordpress.org/plugin/forminator.1.36.0.zip"
  ],
  "config": {
    "WP_DEBUG": true,
    "WP_DEBUG_LOG": true
  }
}
```

> Pinned zip URLs (not bare slugs) make the form-plugin versions reproducible. If a URL 404s at implementation time, bump to the nearest published version on wp.org and record it in `tests-integration/README.md`.

- [ ] **Step 3: Append to `.gitignore`**

```
# Integration test harness
/node_modules/
/.wp-env-home/
```

- [ ] **Step 4: Install and verify wp-env boots**

Run: `npm install && npm run env:start`
Expected: wp-env pulls images and prints `WordPress development site started at http://localhost:8888`.

Run: `wp-env run cli wp plugin list --status=active --field=name`
Expected output includes: `bono-leads-connector`, `contact-form-7`, `wpforms-lite`, `fluentform`, `forminator`.

- [ ] **Step 5: Commit**

```bash
git add package.json .wp-env.json .gitignore package-lock.json
git commit -m "test(integration): scaffold wp-env + npm harness"
```

---

### Task 2: Recording mock backend

**Files:**
- Create: `tests-integration/mock-backend/server.mjs`
- Test: `tests-integration/mock-backend/server.test.mjs`

- [ ] **Step 1: Write the failing test**

`tests-integration/mock-backend/server.test.mjs`:

```js
import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { startMockServer } from './server.mjs';

let server, base;
before(async () => { server = await startMockServer(0); base = `http://127.0.0.1:${server.port}`; });
after(() => server.close());

async function j(method, path, body) {
  const res = await fetch(base + path, {
    method,
    headers: { 'Content-Type': 'application/json' },
    body: body ? JSON.stringify(body) : undefined,
  });
  return { status: res.status, body: await res.json().catch(() => null) };
}

test('records a submission and exposes it via __control/requests', async () => {
  await j('POST', '/__control/reset');
  const r = await fetch(base + '/api/wordpress/submissions', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Bono-Api-Key': 'k', 'X-Bono-Site-Id': 's' },
    body: JSON.stringify({ provider: 'cf7', contact: { name: 'A' } }),
  });
  assert.equal(r.status, 200);
  assert.equal((await r.json()).success, true);
  const { body } = await j('GET', '/__control/requests');
  assert.equal(body.length, 1);
  assert.equal(body[0].body.provider, 'cf7');
  assert.equal(body[0].headers['x-bono-api-key'], 'k');
});

test('reset clears the log', async () => {
  await j('POST', '/__control/reset');
  const { body } = await j('GET', '/__control/requests');
  assert.equal(body.length, 0);
});

test('configurable response status/body', async () => {
  await j('POST', '/__control/reset');
  await j('POST', '/__control/response', { status: 500, body: { success: false } });
  const r = await fetch(base + '/api/wordpress/submissions', { method: 'POST', body: '{}' });
  assert.equal(r.status, 500);
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run: `node --test tests-integration/mock-backend/server.test.mjs`
Expected: FAIL — `Cannot find module './server.mjs'`.

- [ ] **Step 3: Implement `tests-integration/mock-backend/server.mjs`**

```js
import http from 'node:http';

export function startMockServer(port = 3001) {
  const state = { requests: [], nextResponse: { status: 200, body: { success: true, leadId: 'mock-lead' } } };

  const send = (res, status, obj) => {
    res.writeHead(status, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(obj));
  };
  const readBody = (req) => new Promise((resolve) => {
    let d = '';
    req.on('data', (c) => (d += c));
    req.on('end', () => resolve(d));
  });

  const server = http.createServer(async (req, res) => {
    const raw = await readBody(req);
    const url = req.url || '';

    if (req.method === 'POST' && url === '/__control/reset') {
      state.requests = [];
      state.nextResponse = { status: 200, body: { success: true, leadId: 'mock-lead' } };
      return send(res, 200, { ok: true });
    }
    if (req.method === 'GET' && url === '/__control/requests') {
      return send(res, 200, state.requests);
    }
    if (req.method === 'POST' && url === '/__control/response') {
      const cfg = raw ? JSON.parse(raw) : {};
      state.nextResponse = { status: cfg.status ?? 200, body: cfg.body ?? { success: true } };
      return send(res, 200, { ok: true });
    }

    // Any /api/wordpress/* — record and answer with the configured response.
    if (req.method === 'POST' && url.startsWith('/api/wordpress/')) {
      let parsed = null;
      try { parsed = raw ? JSON.parse(raw) : null; } catch { parsed = { _unparsed: raw }; }
      state.requests.push({ path: url, headers: req.headers, body: parsed, receivedAt: new Date().toISOString() });
      if (url.endsWith('/sites/register')) {
        return send(res, 200, { site_id: 'mock-site', api_key: 'mock-key' });
      }
      return send(res, state.nextResponse.status, state.nextResponse.body);
    }

    send(res, 404, { error: 'not_found' });
  });

  return new Promise((resolve) => {
    server.listen(port, '0.0.0.0', () => {
      resolve({ port: server.address().port, close: () => server.close() });
    });
  });
}

// Allow `node server.mjs` to run a long-lived instance on a fixed port (for wp-env to reach).
if (import.meta.url === `file://${process.argv[1]}`) {
  const port = Number(process.env.MOCK_PORT || 3001);
  startMockServer(port).then((s) => console.log(`mock backend listening on ${s.port}`));
}
```

- [ ] **Step 4: Run the test**

Run: `node --test tests-integration/mock-backend/server.test.mjs`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add tests-integration/mock-backend/
git commit -m "test(integration): recording mock Bono backend"
```

---

### Task 3: Provision scripts (seed CF7 form + configure plugin)

**Files:**
- Create: `tests-integration/provision/wait-for-wp.mjs`
- Create: `tests-integration/provision/seed-forms.php`
- Create: `tests-integration/provision/configure-plugin.php`

- [ ] **Step 1: Create `tests-integration/provision/wait-for-wp.mjs`**

```js
const url = process.env.WP_URL || 'http://localhost:8888';
const deadline = Date.now() + 60_000;
while (Date.now() < deadline) {
  try {
    const res = await fetch(url + '/wp-login.php');
    if (res.ok || res.status === 200) { console.log('WP is up'); process.exit(0); }
  } catch { /* not ready */ }
  await new Promise((r) => setTimeout(r, 1500));
}
console.error('WP did not come up in time');
process.exit(1);
```

- [ ] **Step 2: Create `tests-integration/provision/seed-forms.php`**

Seeds a CF7 form (a `wpcf7_contact_form` post) with name/email/phone/message fields, and records IDs other triggers will read. WPForms/Fluent/Forminator form seeding is added in their own tasks; this file establishes the `bono_test_form_ids` option contract now.

```php
<?php
/**
 * Seed representative forms and store their IDs in option `bono_test_form_ids`.
 * Run via: wp eval-file .../seed-forms.php
 */
$ids = get_option( 'bono_test_form_ids', array() );
if ( ! is_array( $ids ) ) { $ids = array(); }

// --- Contact Form 7 ---
if ( empty( $ids['cf7'] ) && class_exists( 'WPCF7_ContactForm' ) ) {
    $cf7 = WPCF7_ContactForm::get_template( array( 'title' => 'Bono Test CF7' ) );
    $cf7->set_properties( array(
        'form' =>
            "[text* your-name]\n[email* your-email]\n[tel your-phone]\n[textarea your-message]\n[submit \"Send\"]",
    ) );
    $cf7_id = $cf7->save();
    // Place it on a published page so page_id/page_url resolve.
    $page_id = wp_insert_post( array(
        'post_title'   => 'Bono Test CF7 Page',
        'post_status'  => 'publish',
        'post_type'    => 'page',
        'post_content' => '[contact-form-7 id="' . $cf7_id . '"]',
    ) );
    $ids['cf7']      = (string) $cf7_id;
    $ids['cf7_page'] = (string) $page_id;
}

update_option( 'bono_test_form_ids', $ids );
echo "seeded: " . wp_json_encode( $ids ) . "\n";
```

- [ ] **Step 3: Create `tests-integration/provision/configure-plugin.php`**

Points the Bono plugin at the mock. `host.docker.internal` resolves to the host from inside the wp-env container (Mac native; Linux CI mapping added in Task 13). `MOCK_PORT` defaults to 3001.

```php
<?php
/**
 * Configure the Bono plugin to send to the recording mock backend.
 * Run via: wp eval-file .../configure-plugin.php
 */
$port = getenv( 'BONO_MOCK_PORT' ) ? getenv( 'BONO_MOCK_PORT' ) : '3001';
$settings = get_option( 'bono_leads_connector_settings', array() );
if ( ! is_array( $settings ) ) { $settings = array(); }
$settings['api_base_url']     = 'http://host.docker.internal:' . $port . '/api';
$settings['api_key']          = 'integration-test-key';
$settings['site_id']          = 'integration-test-site';
$settings['enable_debug_log'] = true;
update_option( 'bono_leads_connector_settings', $settings );

// Sanity: confirm the URL passes the plugin's allowlist guard.
$client = new Bono_API_Client();
$ref = new ReflectionMethod( 'Bono_API_Client', 'is_allowed_api_base_url' );
$ref->setAccessible( true );
$ok = $ref->invoke( $client, $settings['api_base_url'] );
echo "configured api_base_url=" . $settings['api_base_url'] . " allowed=" . ( $ok ? '1' : '0' ) . "\n";
if ( ! $ok ) { fwrite( STDERR, "api_base_url rejected by guard\n" ); exit( 1 ); }
```

- [ ] **Step 4: Verify provisioning runs**

Run (with wp-env up): `npm run provision`
Expected: prints `seeded: {"cf7":"<id>","cf7_page":"<id>"}` and `configured api_base_url=http://host.docker.internal:3001/api allowed=1`.

- [ ] **Step 5: Commit**

```bash
git add tests-integration/provision/
git commit -m "test(integration): provisioning — seed CF7 form + configure plugin"
```

---

### Task 4: Runner helpers + CF7 happy-path vertical slice

This proves the whole pipeline end-to-end with the **real** CF7 submission path (REST feedback), de-risking the harness before adding other providers.

**Files:**
- Create: `tests-integration/runner/helpers.mjs`
- Test: `tests-integration/runner/cf7.test.mjs`

- [ ] **Step 1: Create `tests-integration/runner/helpers.mjs`**

```js
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
const exec = promisify(execFile);

const MOCK = process.env.MOCK_URL || 'http://127.0.0.1:3001';
const WP = process.env.WP_URL || 'http://localhost:8888';
const PLUGIN_DIR = 'wp-content/plugins/bono-leads-connector';

export async function resetMock() {
  await fetch(MOCK + '/__control/reset', { method: 'POST' });
}
export async function setMockResponse(status, body) {
  await fetch(MOCK + '/__control/response', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ status, body }),
  });
}
export async function getRequests() {
  const res = await fetch(MOCK + '/__control/requests');
  return res.json();
}

/** Run a PHP trigger inside wp-env, passing test inputs via env vars. */
export async function wpEval(triggerFile, env = {}) {
  const args = ['run', 'cli', '--env-cwd=' + PLUGIN_DIR, 'wp', 'eval-file',
    `tests-integration/triggers/${triggerFile}`];
  const prefixed = Object.fromEntries(Object.entries(env).map(([k, v]) => [`BONO_TEST_${k}`, String(v)]));
  const { stdout } = await exec('wp-env', args, { env: { ...process.env, ...prefixed } });
  return stdout;
}

/** Read a value (e.g. a seeded form id) from the bono_test_form_ids option. */
export async function getFormId(key) {
  const { stdout } = await exec('wp-env', ['run', 'cli', 'wp', 'option', 'get', 'bono_test_form_ids', '--format=json']);
  return JSON.parse(stdout)[key];
}

/** Submit the seeded CF7 form through CF7's real REST feedback endpoint. */
export async function cf7Submit(fields) {
  const formId = await getFormId('cf7');
  const pageId = await getFormId('cf7_page');
  const fd = new FormData();
  fd.set('_wpcf7', formId);
  fd.set('_wpcf7_unit_tag', `wpcf7-f${formId}-p${pageId}-o1`);
  fd.set('_wpcf7_container_post', pageId);
  for (const [k, v] of Object.entries(fields)) fd.set(k, v);
  const res = await fetch(`${WP}/index.php?rest_route=/contact-form-7/v1/contact-forms/${formId}/feedback`, {
    method: 'POST', body: fd,
  });
  return res.json();
}

/** Poll the mock until at least `n` requests are recorded, or timeout. */
export async function waitForRequests(n, ms = 4000) {
  const deadline = Date.now() + ms;
  while (Date.now() < deadline) {
    const r = await getRequests();
    if (r.length >= n) return r;
    await new Promise((res) => setTimeout(res, 150));
  }
  return getRequests();
}
```

- [ ] **Step 2: Write the failing test `tests-integration/runner/cf7.test.mjs`**

```js
import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { resetMock, getRequests, cf7Submit, waitForRequests } from './helpers.mjs';

beforeEach(async () => { await resetMock(); });

test('CF7 happy path → normalized payload at mock', async () => {
  await cf7Submit({ 'your-name': 'Dana Cohen', 'your-email': 'dana@example.com', 'your-phone': '050-1234567' });
  const reqs = await waitForRequests(1);
  assert.equal(reqs.length, 1);
  const p = reqs[0].body;
  assert.equal(p.provider, 'cf7');
  assert.match(p.sourceKey, /^cf7:\d+:/);
  assert.equal(p.contact.name, 'Dana Cohen');
  assert.equal(p.contact.email, 'dana@example.com');
  assert.equal(p.contact.phone, '0501234567');
  assert.equal(p.validation.isValid, true);
  assert.ok(p.idempotencyKey);
  assert.equal(reqs[0].headers['x-bono-site-id'], 'integration-test-site');
});
```

- [ ] **Step 3: Run it to confirm it fails (harness not yet wired / mock empty)**

Start the mock and wp-env in the background first (see README in Task 14):
```bash
MOCK_PORT=3001 node tests-integration/mock-backend/server.mjs &   # mock
npm run env:start && npm run provision
node --test tests-integration/runner/cf7.test.mjs
```
Expected initially: FAIL (e.g. 0 requests) if anything in the chain is misconfigured. Diagnose using `wp-env run cli wp eval "error_log('x');"` and the mock's recorded requests.

- [ ] **Step 4: Make it pass**

No new plugin code. Resolve configuration only: ensure (a) the mock is reachable at `host.docker.internal:3001` from the container (`wp-env run cli wp eval 'echo wp_remote_retrieve_response_code(wp_remote_post("http://host.docker.internal:3001/api/wordpress/submissions"));'` returns `200`), (b) provisioning ran, (c) CF7 form is on a published page. Re-run until PASS.

- [ ] **Step 5: Commit**

```bash
git add tests-integration/runner/helpers.mjs tests-integration/runner/cf7.test.mjs
git commit -m "test(integration): CF7 happy-path vertical slice via real REST feedback"
```

---

### Task 5: CF7 edge cases

**Files:**
- Modify: `tests-integration/runner/cf7.test.mjs`

- [ ] **Step 1: Add failing edge-case tests**

Append to `cf7.test.mjs`:

```js
test('CF7 Hebrew name preserved', async () => {
  await cf7Submit({ 'your-name': 'דנה כהן', 'your-email': 'dana@example.com', 'your-phone': '0501234567' });
  const reqs = await waitForRequests(1);
  assert.equal(reqs[0].body.contact.name, 'דנה כהן');
});

test('CF7 missing contact (no email, no phone) → skipped, zero requests', async () => {
  await cf7Submit({ 'your-name': 'Only Name' });
  // Give the plugin time; then assert NOTHING was sent.
  await new Promise((r) => setTimeout(r, 1500));
  const reqs = await getRequests();
  assert.equal(reqs.length, 0);
});

test('CF7 duplicate within idempotency window → second skipped', async () => {
  const fields = { 'your-name': 'Dup Person', 'your-email': 'dup@example.com', 'your-phone': '0500000000' };
  await cf7Submit(fields);
  await waitForRequests(1);
  await cf7Submit(fields); // identical within the per-minute window
  await new Promise((r) => setTimeout(r, 1500));
  const reqs = await getRequests();
  assert.equal(reqs.length, 1, 'duplicate suppressed');
});

test('CF7 field-mapping override wins over auto-detection', async () => {
  // Map this form's email role to a specific field key, then submit a form where
  // auto-detection would pick a different value. Mapping must win.
  // bono_field_mappings shape mirrors tests/CapturePipelineTest mapping test:
  //   { "cf7:<formId>": { "email": "your-alt-email" } }
  const { execFile } = await import('node:child_process');
  const { promisify } = await import('node:util');
  const exec = promisify(execFile);
  const { stdout } = await exec('wp-env', ['run', 'cli', 'wp', 'option', 'get', 'bono_test_form_ids', '--format=json']);
  const formId = JSON.parse(stdout).cf7;
  const mapping = JSON.stringify({ [`cf7:${formId}`]: { email: 'your-alt-email' } });
  await exec('wp-env', ['run', 'cli', 'wp', 'option', 'update', 'bono_field_mappings', mapping, '--format=json']);
  try {
    // Seeded CF7 form has no `your-alt-email` field by default, so add the value via a known field:
    // submit both your-email (auto) and your-alt-email (mapped); mapped must win.
    await cf7Submit({
      'your-name': 'Map Person',
      'your-email': 'auto@example.com',
      'your-alt-email': 'mapped@example.com',
      'your-phone': '0500000000',
    });
    const reqs = await waitForRequests(1);
    assert.equal(reqs[0].body.contact.email, 'mapped@example.com', 'mapping overrides detection');
  } finally {
    await exec('wp-env', ['run', 'cli', 'wp', 'option', 'delete', 'bono_field_mappings']);
  }
});
```

> The seeded CF7 form (Task 3) must include a `[email your-alt-email]` field for this test. Add it to `seed-forms.php`'s CF7 template (`[email your-alt-email]`) so the mapped field exists. Verify the `bono_field_mappings` key format against `class-bono-field-mapping.php` (`provider:form_id` → `{role: field_key}`) — match it exactly, copying the shape used in `tests/CapturePipelineTest.php`.

> Note on the "missing contact" assertion: CF7 marks `[email*]`/`[tel]` — to actually reach the Bono hook with a missing contact, the seeded form's email/phone must be **optional** for this case. The seed form uses `[email* your-email]`; for this test, submit with a valid email format but then assert skip only when BOTH email and phone are absent. If CF7 validation blocks the submission before `wpcf7_before_send_mail` fires, drive this specific edge case through the `wpforms`/`forminator` trigger instead (their hooks fire post-validation with raw arrays). Keep this test on whichever provider lets the missing-contact payload actually reach `send_payload`; document the choice inline.

- [ ] **Step 2: Run; expect the new tests to fail or reveal the validation nuance above**

Run: `node --test tests-integration/runner/cf7.test.mjs`
Expected: Hebrew + duplicate PASS; missing-contact may need the relocation noted above.

- [ ] **Step 3: Resolve missing-contact placement**

If CF7 blocks pre-hook, move the missing-contact assertion to `forminator.test.mjs` (Task 8) and delete it here, leaving a comment. Otherwise keep it.

- [ ] **Step 4: Re-run to green**

Run: `node --test tests-integration/runner/cf7.test.mjs`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add tests-integration/runner/cf7.test.mjs
git commit -m "test(integration): CF7 edge cases (Hebrew, duplicate, missing-contact)"
```

---

### Task 6: WPForms trigger + cases

WPForms Lite is installed. We seed a real WPForms form (a `wpforms` post whose `post_content` is the form JSON), then fire the **real** `wpforms_process_complete` hook with an entry referencing it — exercising the real registered Bono handler and the real form-metadata getters.

**Files:**
- Modify: `tests-integration/provision/seed-forms.php`
- Create: `tests-integration/triggers/wpforms.php`
- Test: `tests-integration/runner/wpforms.test.mjs`

- [ ] **Step 1: Extend `seed-forms.php` — add WPForms form**

Insert before the final `update_option`:

```php
// --- WPForms ---
if ( empty( $ids['wpforms'] ) && post_type_exists( 'wpforms' ) ) {
    $wpf_id = wp_insert_post( array(
        'post_title'  => 'Bono Test WPForms',
        'post_status' => 'publish',
        'post_type'   => 'wpforms',
        'post_content' => wp_json_encode( array(
            'id'     => 0,
            'fields' => array(
                '1' => array( 'id' => '1', 'type' => 'name',  'label' => 'Name' ),
                '2' => array( 'id' => '2', 'type' => 'email', 'label' => 'Email' ),
                '3' => array( 'id' => '3', 'type' => 'phone', 'label' => 'Phone' ),
            ),
            'settings' => array( 'form_title' => 'Bono Test WPForms' ),
        ) ),
    ) );
    $ids['wpforms'] = (string) $wpf_id;
}
```

- [ ] **Step 2: Create `tests-integration/triggers/wpforms.php`**

Fires the real hook with the `$fields,$entry,$form_data,$entry_id` shapes WPForms uses. Inputs via env (`BONO_TEST_NAME`, etc.).

```php
<?php
$name  = getenv( 'BONO_TEST_NAME' )  !== false ? getenv( 'BONO_TEST_NAME' )  : 'Dana Cohen';
$email = getenv( 'BONO_TEST_EMAIL' ) !== false ? getenv( 'BONO_TEST_EMAIL' ) : 'dana@example.com';
$phone = getenv( 'BONO_TEST_PHONE' ) !== false ? getenv( 'BONO_TEST_PHONE' ) : '0501234567';

$ids     = get_option( 'bono_test_form_ids', array() );
$form_id = isset( $ids['wpforms'] ) ? (int) $ids['wpforms'] : 0;

$fields = array(
    '1' => array( 'name' => 'Name',  'value' => $name,  'id' => '1', 'type' => 'name' ),
    '2' => array( 'name' => 'Email', 'value' => $email, 'id' => '2', 'type' => 'email' ),
    '3' => array( 'name' => 'Phone', 'value' => $phone, 'id' => '3', 'type' => 'phone' ),
);
$entry     = array( 'fields' => $fields );
$form_data = array( 'id' => $form_id, 'settings' => array( 'form_title' => 'Bono Test WPForms' ) );

do_action( 'wpforms_process_complete', $fields, $entry, $form_data, 999 );
echo "fired wpforms_process_complete form_id={$form_id}\n";
```

- [ ] **Step 3: Write the failing test `tests-integration/runner/wpforms.test.mjs`**

```js
import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { resetMock, wpEval, waitForRequests } from './helpers.mjs';

beforeEach(async () => { await resetMock(); });

test('WPForms happy path → normalized payload', async () => {
  await wpEval('wpforms.php', { NAME: 'Dana Cohen', EMAIL: 'dana@example.com', PHONE: '050-1234567' });
  const reqs = await waitForRequests(1);
  assert.equal(reqs.length, 1);
  const p = reqs[0].body;
  assert.equal(p.provider, 'wpforms');
  assert.equal(p.contact.name, 'Dana Cohen');
  assert.equal(p.contact.email, 'dana@example.com');
  assert.equal(p.contact.phone, '0501234567');
  assert.equal(p.validation.isValid, true);
});
```

- [ ] **Step 4: Run → seed, fire, assert**

Run: `npm run provision && node --test tests-integration/runner/wpforms.test.mjs`
Expected: PASS. If `provider` or `contact` is wrong, inspect how the Bono WPForms handler reads `$fields` (`class-bono-wpforms-capture.php`) and adjust the `$fields` shape in the trigger to match the real WPForms entry structure (field arrays keyed by id with `name`/`value`).

- [ ] **Step 5: Commit**

```bash
git add tests-integration/provision/seed-forms.php tests-integration/triggers/wpforms.php tests-integration/runner/wpforms.test.mjs
git commit -m "test(integration): WPForms trigger + happy path"
```

---

### Task 7: Fluent Forms trigger + cases

**Files:**
- Create: `tests-integration/triggers/fluent.php`
- Test: `tests-integration/runner/fluent.test.mjs`

- [ ] **Step 1: Create `tests-integration/triggers/fluent.php`**

Reuses the known-good Fluent shape from `CapturePipelineTest` (`$form_data` = input-name→value). `$form` carries `id`/`title`.

```php
<?php
$name  = getenv( 'BONO_TEST_NAME' )  !== false ? getenv( 'BONO_TEST_NAME' )  : 'Dana Cohen';
$email = getenv( 'BONO_TEST_EMAIL' ) !== false ? getenv( 'BONO_TEST_EMAIL' ) : 'dana@example.com';
$phone = getenv( 'BONO_TEST_PHONE' ) !== false ? getenv( 'BONO_TEST_PHONE' ) : '0501234567';

$form_data = array( 'name' => $name, 'email' => $email, 'phone' => $phone );
$form      = array( 'id' => 5, 'title' => 'Bono Test Fluent' );

do_action( 'fluentform/submission_inserted', 100, $form_data, $form );
echo "fired fluentform/submission_inserted\n";
```

- [ ] **Step 2: Write the failing test `tests-integration/runner/fluent.test.mjs`**

```js
import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { resetMock, wpEval, waitForRequests } from './helpers.mjs';

beforeEach(async () => { await resetMock(); });

test('Fluent happy path + phone normalization', async () => {
  await wpEval('fluent.php', { NAME: 'Dana Cohen', EMAIL: 'dana@example.com', PHONE: '050-123-4567' });
  const reqs = await waitForRequests(1);
  const p = reqs[0].body;
  assert.equal(p.provider, 'fluent');
  assert.equal(p.contact.name, 'Dana Cohen');
  assert.equal(p.contact.phone, '0501234567');
  assert.equal(p.validation.isValid, true);
});
```

- [ ] **Step 3: Run**

Run: `node --test tests-integration/runner/fluent.test.mjs`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add tests-integration/triggers/fluent.php tests-integration/runner/fluent.test.mjs
git commit -m "test(integration): Fluent Forms trigger + phone normalization"
```

---

### Task 8: Forminator trigger + cases (incl. missing-contact skip)

**Files:**
- Create: `tests-integration/triggers/forminator.php`
- Test: `tests-integration/runner/forminator.test.mjs`

- [ ] **Step 1: Create `tests-integration/triggers/forminator.php`**

Reuses the known-good Forminator shape (`$field_data_array` of `['name'=>..., 'value'=>...]`). A `BONO_TEST_OMIT_CONTACT=1` env produces a name-only submission for the skip test.

```php
<?php
$name  = getenv( 'BONO_TEST_NAME' )  !== false ? getenv( 'BONO_TEST_NAME' )  : 'Dana Cohen';
$email = getenv( 'BONO_TEST_EMAIL' ) !== false ? getenv( 'BONO_TEST_EMAIL' ) : 'dana@example.com';
$phone = getenv( 'BONO_TEST_PHONE' ) !== false ? getenv( 'BONO_TEST_PHONE' ) : '0501234567';
$omit  = getenv( 'BONO_TEST_OMIT_CONTACT' ) === '1';

$field_data = array( array( 'name' => 'name-1', 'value' => $name ) );
if ( ! $omit ) {
    $field_data[] = array( 'name' => 'email-1', 'value' => $email );
    $field_data[] = array( 'name' => 'phone-1', 'value' => $phone );
}

do_action( 'forminator_custom_form_submit_before_set_fields', null, 9, $field_data );
echo "fired forminator_custom_form_submit_before_set_fields omit={$omit}\n";
```

- [ ] **Step 2: Write the failing test `tests-integration/runner/forminator.test.mjs`**

```js
import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { resetMock, wpEval, getRequests, waitForRequests } from './helpers.mjs';

beforeEach(async () => { await resetMock(); });

test('Forminator happy path', async () => {
  await wpEval('forminator.php', { NAME: 'Dana Cohen', EMAIL: 'dana@example.com', PHONE: '0501234567' });
  const reqs = await waitForRequests(1);
  const p = reqs[0].body;
  assert.equal(p.provider, 'forminator');
  assert.equal(p.contact.name, 'Dana Cohen');
  assert.equal(p.contact.email, 'dana@example.com');
});

test('Forminator missing contact (name only) → skipped, zero requests', async () => {
  await wpEval('forminator.php', { NAME: 'Only Name', OMIT_CONTACT: '1' });
  await new Promise((r) => setTimeout(r, 1500));
  assert.equal((await getRequests()).length, 0);
});
```

- [ ] **Step 3: Run**

Run: `node --test tests-integration/runner/forminator.test.mjs`
Expected: PASS (2 tests).

- [ ] **Step 4: Commit**

```bash
git add tests-integration/triggers/forminator.php tests-integration/runner/forminator.test.mjs
git commit -m "test(integration): Forminator trigger + missing-contact skip"
```

---

### Task 9: Elementor (simulated) trigger + case

Elementor Pro is not installed. The trigger fires `elementor_pro/forms/new_record` with a minimal **Record double** exposing the `->get(...)` accessors the Bono handler uses (`fields`, `form_settings`, `page_id`, `post_id`, `meta`).

**Files:**
- Create: `tests-integration/triggers/elementor.php`
- Test: `tests-integration/runner/elementor.test.mjs`

- [ ] **Step 1: Create `tests-integration/triggers/elementor.php`**

```php
<?php
$name  = getenv( 'BONO_TEST_NAME' )  !== false ? getenv( 'BONO_TEST_NAME' )  : 'Dana Cohen';
$email = getenv( 'BONO_TEST_EMAIL' ) !== false ? getenv( 'BONO_TEST_EMAIL' ) : 'dana@example.com';
$phone = getenv( 'BONO_TEST_PHONE' ) !== false ? getenv( 'BONO_TEST_PHONE' ) : '0501234567';

/** Minimal stand-in for \ElementorPro\Modules\Forms\Classes\Form_Record. */
class Bono_Test_Elementor_Record {
    private $data;
    public function __construct( $data ) { $this->data = $data; }
    public function get( $key ) { return isset( $this->data[ $key ] ) ? $this->data[ $key ] : null; }
    public function get_form_settings( $key ) {
        $s = $this->get( 'form_settings' );
        return is_array( $s ) && isset( $s[ $key ] ) ? $s[ $key ] : null;
    }
}

$record = new Bono_Test_Elementor_Record( array(
    'fields' => array(
        'name'  => array( 'id' => 'name',  'value' => $name,  'title' => 'Name' ),
        'email' => array( 'id' => 'email', 'value' => $email, 'title' => 'Email' ),
        'phone' => array( 'id' => 'phone', 'value' => $phone, 'title' => 'Phone' ),
    ),
    'form_settings' => array( 'form_name' => 'Bono Test Elementor', 'id' => 'abc123' ),
    'page_id'       => 12,
    'post_id'       => 12,
    'meta'          => array(),
) );

do_action( 'elementor_pro/forms/new_record', $record, null );
echo "fired elementor_pro/forms/new_record (simulated)\n";
```

- [ ] **Step 2: Write the failing test `tests-integration/runner/elementor.test.mjs`**

```js
import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { resetMock, wpEval, waitForRequests } from './helpers.mjs';

beforeEach(async () => { await resetMock(); });

test('Elementor (simulated) happy path', async () => {
  await wpEval('elementor.php', { NAME: 'Dana Cohen', EMAIL: 'dana@example.com', PHONE: '0501234567' });
  const reqs = await waitForRequests(1);
  const p = reqs[0].body;
  assert.equal(p.provider, 'elementor');
  assert.equal(p.contact.name, 'Dana Cohen');
  assert.equal(p.contact.email, 'dana@example.com');
  assert.equal(p.contact.phone, '0501234567');
});
```

- [ ] **Step 3: Run; align the Record double with the handler**

Run: `node --test tests-integration/runner/elementor.test.mjs`
Expected: PASS. If fields aren't read, re-check `class-bono-elementor-capture.php` (`$record->get('fields')` → each field's `value`) and adjust the field array shape in the trigger to match exactly.

- [ ] **Step 4: Commit**

```bash
git add tests-integration/triggers/elementor.php tests-integration/runner/elementor.test.mjs
git commit -m "test(integration): Elementor (simulated) trigger + happy path"
```

---

### Task 10: Gravity (simulated) trigger + cases

Gravity Forms is not installed. The trigger fires `gform_after_submission` with the known-good `$entry`/`$form` shapes from `CapturePipelineTest` (entry keyed by field id; `$form['fields']` carrying field descriptors incl. a name field with first/last inputs).

**Files:**
- Create: `tests-integration/triggers/gravity.php`
- Test: `tests-integration/runner/gravity.test.mjs`

- [ ] **Step 1: Create `tests-integration/triggers/gravity.php`**

```php
<?php
$first = getenv( 'BONO_TEST_FIRST' ) !== false ? getenv( 'BONO_TEST_FIRST' ) : 'Dana';
$last  = getenv( 'BONO_TEST_LAST' )  !== false ? getenv( 'BONO_TEST_LAST' )  : 'Cohen';
$email = getenv( 'BONO_TEST_EMAIL' ) !== false ? getenv( 'BONO_TEST_EMAIL' ) : 'dana@example.com';
$phone = getenv( 'BONO_TEST_PHONE' ) !== false ? getenv( 'BONO_TEST_PHONE' ) : '0501234567';

// Gravity name field (id 1) splits into 1.3 (first) / 1.6 (last); email id 2; phone id 3.
$entry = array(
    '1.3'        => $first,
    '1.6'        => $last,
    '2'          => $email,
    '3'          => $phone,
    'source_url' => 'http://localhost:8888/gravity-page',
);
$form = array(
    'id'     => 7,
    'title'  => 'Bono Test Gravity',
    'fields' => array(
        array( 'id' => 1, 'type' => 'name',  'label' => 'Name' ),
        array( 'id' => 2, 'type' => 'email', 'label' => 'Email' ),
        array( 'id' => 3, 'type' => 'phone', 'label' => 'Phone' ),
    ),
);

do_action( 'gform_after_submission', $entry, $form );
echo "fired gform_after_submission (simulated)\n";
```

- [ ] **Step 2: Write the failing test `tests-integration/runner/gravity.test.mjs`**

```js
import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { resetMock, wpEval, waitForRequests } from './helpers.mjs';

beforeEach(async () => { await resetMock(); });

test('Gravity (simulated) combines first+last name', async () => {
  await wpEval('gravity.php', { FIRST: 'Dana', LAST: 'Cohen', EMAIL: 'dana@example.com', PHONE: '0501234567' });
  const reqs = await waitForRequests(1);
  const p = reqs[0].body;
  assert.equal(p.provider, 'gravity');
  assert.equal(p.contact.name, 'Dana Cohen');
  assert.equal(p.contact.email, 'dana@example.com');
  assert.equal(p.sourceKey.startsWith('gravity:7'), true);
});
```

- [ ] **Step 3: Run; align entry/form with `extract_gravity_fields`**

Run: `node --test tests-integration/runner/gravity.test.mjs`
Expected: PASS. If name isn't combined, re-check `extract_gravity_fields` in `class-bono-gravity-capture.php` and match the entry key convention it expects (it iterates `$form['fields']` then `$entry`). Reuse the exact shape from `tests/CapturePipelineTest.php` if in doubt.

- [ ] **Step 4: Commit**

```bash
git add tests-integration/triggers/gravity.php tests-integration/runner/gravity.test.mjs
git commit -m "test(integration): Gravity (simulated) trigger + first/last name combine"
```

---

### Task 11: Generic capture (REST + nonce)

The generic path is JS→REST. From the runner we call the REST route directly. The nonce is page-bound, so the trigger mints a valid nonce server-side and echoes it; the runner posts it to `/wp-json/bono/v1/capture` with header `X-Bono-Nonce`.

**Files:**
- Create: `tests-integration/triggers/generic-nonce.php`
- Test: `tests-integration/runner/generic.test.mjs`

- [ ] **Step 1: Create `tests-integration/triggers/generic-nonce.php`**

```php
<?php
// Mint a valid nonce for the generic-capture REST route and print it.
$action = Bono_Generic_Capture::NONCE_ACTION;
echo wp_create_nonce( $action );
```

- [ ] **Step 2: Write the failing test `tests-integration/runner/generic.test.mjs`**

```js
import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { resetMock, waitForRequests } from './helpers.mjs';
const exec = promisify(execFile);

const WP = process.env.WP_URL || 'http://localhost:8888';
beforeEach(async () => { await resetMock(); });

async function mintNonce() {
  const { stdout } = await exec('wp-env', ['run', 'cli', '--env-cwd=wp-content/plugins/bono-leads-connector',
    'wp', 'eval-file', 'tests-integration/triggers/generic-nonce.php']);
  return stdout.trim();
}

test('Generic capture via REST + nonce → normalized payload', async () => {
  const nonce = await mintNonce();
  const res = await fetch(`${WP}/index.php?rest_route=/bono/v1/capture`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Bono-Nonce': nonce },
    body: JSON.stringify({
      _bono_nonce: nonce,
      form_id: '7',
      fields: { name: 'Dana Cohen', email: 'dana@example.com', phone: '0501234567' },
    }),
  });
  assert.equal(res.status < 400, true);
  const reqs = await waitForRequests(1);
  const p = reqs[0].body;
  assert.equal(p.provider, 'generic');
  assert.equal(p.contact.name, 'Dana Cohen');
  assert.equal(p.contact.email, 'dana@example.com');
});

test('Generic capture rejects a bad nonce', async () => {
  const res = await fetch(`${WP}/index.php?rest_route=/bono/v1/capture`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Bono-Nonce': 'bogus' },
    body: JSON.stringify({ _bono_nonce: 'bogus', form_id: '7', fields: { name: 'x', email: 'y@z.com' } }),
  });
  assert.equal(res.status, 403);
  assert.equal((await waitForRequests(1, 1000)).length, 0);
});
```

> The exact request body shape (`fields` object vs. flat keys, `form_id` param name) must match what `class-bono-generic-capture.php`'s REST callback reads. Open that file, match the param names it pulls from `$request`, and adjust the body accordingly before finalizing.

- [ ] **Step 3: Run; align body with the REST callback**

Run: `node --test tests-integration/runner/generic.test.mjs`
Expected: PASS (2 tests). Adjust the request body keys to match the callback's expected params.

- [ ] **Step 4: Commit**

```bash
git add tests-integration/triggers/generic-nonce.php tests-integration/runner/generic.test.mjs
git commit -m "test(integration): generic capture via REST + nonce (happy + bad-nonce)"
```

---

### Task 12: Non-2xx response → retry enqueue

When the mock returns a non-2xx, the plugin enqueues the submission for retry in `wp_bono_submission_queue`. This verifies the durable-queue path end-to-end.

**Files:**
- Create: `tests-integration/triggers/queue-count.php`
- Test: `tests-integration/runner/retry.test.mjs`

- [ ] **Step 1: Create `tests-integration/triggers/queue-count.php`**

```php
<?php
global $wpdb;
$t = $wpdb->prefix . 'bono_submission_queue';
$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) );
if ( ! $exists ) { echo '0'; return; }
echo (string) (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ); // phpcs:ignore WordPress.DB.PreparedSQL
```

- [ ] **Step 2: Write the failing test `tests-integration/runner/retry.test.mjs`**

```js
import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { resetMock, setMockResponse, wpEval, waitForRequests } from './helpers.mjs';
const exec = promisify(execFile);

async function queueCount() {
  const { stdout } = await exec('wp-env', ['run', 'cli', '--env-cwd=wp-content/plugins/bono-leads-connector',
    'wp', 'eval-file', 'tests-integration/triggers/queue-count.php']);
  return Number(stdout.trim());
}

beforeEach(async () => { await resetMock(); });

test('non-2xx response enqueues the submission for retry', async () => {
  const before = await queueCount();
  await setMockResponse(500, { success: false });
  await wpEval('fluent.php', { NAME: 'Retry Me', EMAIL: 'retry@example.com', PHONE: '0500000000' });
  await waitForRequests(1);          // the failed attempt was still sent (and recorded)
  await new Promise((r) => setTimeout(r, 1000));
  const after = await queueCount();
  assert.equal(after, before + 1, 'one row enqueued after a failed send');
});
```

- [ ] **Step 3: Run**

Run: `node --test tests-integration/runner/retry.test.mjs`
Expected: PASS. (The queue table is created by the plugin's activation hook under wp-env, which activates the plugin cleanly — unlike the ad-hoc local rig.)

- [ ] **Step 4: Commit**

```bash
git add tests-integration/triggers/queue-count.php tests-integration/runner/retry.test.mjs
git commit -m "test(integration): non-2xx response enqueues retry row"
```

---

### Task 13: CI workflow

**Files:**
- Create: `.github/workflows/integration.yml`

- [ ] **Step 1: Create `.github/workflows/integration.yml`**

`host.docker.internal` is added to the runner via Docker's host-gateway so the wp-env container can reach the mock running on the runner host.

```yaml
name: Integration

on:
  pull_request:
  push:
    branches: [main]

concurrency:
  group: integration-${{ github.ref }}
  cancel-in-progress: true

jobs:
  integration:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: '20'
      - name: Map host.docker.internal
        run: echo "127.0.0.1 host.docker.internal" | sudo tee -a /etc/hosts
      - run: npm install
      - name: Start mock backend
        run: |
          MOCK_PORT=3001 node tests-integration/mock-backend/server.mjs &
          for i in $(seq 1 20); do curl -sf http://127.0.0.1:3001/__control/requests && break; sleep 0.5; done
      - name: Self-test the mock
        run: npm run test:mock
      - name: Start wp-env
        run: npm run env:start
      - name: Add host-gateway to wp-env container
        run: |
          CID=$(docker ps --filter "name=wordpress" --format '{{.ID}}' | head -n1)
          docker exec "$CID" sh -c 'getent hosts host.docker.internal || echo "$(ip route | awk "/default/ {print \$3}") host.docker.internal" >> /etc/hosts' || true
      - name: Provision
        run: npm run provision
      - name: Integration suite
        run: npm run test:integration
      - name: Tail WP debug log on failure
        if: failure()
        run: wp-env run cli wp eval 'echo @file_get_contents(WP_CONTENT_DIR."/debug.log");' || true
```

> CI-network note: reaching the runner host from inside the wp-env container is the one fragile point. The two `host.docker.internal` steps cover Linux runners. If the container still can't reach the host, fall back to publishing the mock inside the wp-env Docker network (document whichever path works in `tests-integration/README.md`). This job is **not** wired into the deploy gate — it runs in parallel, consistent with the repo's CI philosophy.

- [ ] **Step 2: Validate YAML locally**

Run: `node -e "require('js-yaml')" 2>/dev/null || npx --yes js-yaml .github/workflows/integration.yml >/dev/null && echo "YAML OK"`
Expected: `YAML OK` (or use any YAML linter available).

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/integration.yml
git commit -m "ci(integration): run forms integration suite on PR + main"
```

---

### Task 14: Docs

**Files:**
- Create: `tests-integration/README.md`

- [ ] **Step 1: Create `tests-integration/README.md`**

```markdown
# Forms Integration Test Suite

Boots real WordPress (via @wordpress/env) with the Bono plugin + the 4 free form
plugins, fires each provider's real submission hook, and asserts the normalized
payload against a recording mock backend.

## Providers
- Real installs: Contact Form 7, WPForms Lite, Fluent Forms, Forminator.
- Simulated (paid, not installed): Elementor Pro, Gravity Forms — driven by firing
  their hook directly with a faithful native object.
- Generic capture: driven over the REST route `bono/v1/capture` with a minted nonce.

## Run locally
1. `npm install`
2. Start the mock: `MOCK_PORT=3001 node tests-integration/mock-backend/server.mjs &`
3. `npm run env:start`
4. `npm run provision`
5. `npm run test:integration`

Stop with `npm run env:stop`. Reset everything with `npm run env:clean`.

## Notes
- The plugin posts to `http://host.docker.internal:3001/api/wordpress/submissions`.
  `host.docker.internal` is in the plugin's http allowlist.
- Pinned form-plugin versions live in `.wp-env.json`. Bump there if a download 404s.
- These tests do not touch staging or create real leads.
```

- [ ] **Step 2: Commit**

```bash
git add tests-integration/README.md
git commit -m "docs(integration): how to run the forms integration suite"
```

---

## Self-Review

**Spec coverage:**
- 6 providers + generic → Tasks 4–11. ✅
- Paid simulated via hook → Tasks 9, 10. ✅
- Mock backend recording + configurable response → Task 2. ✅
- wp-env in plugin repo → Task 1. ✅
- Approach A (wp eval hook firing); CF7 via real REST → Tasks 4, 6–11. ✅
- Test matrix (happy, Hebrew, first+last, phone, missing-contact skip, field-mapping, duplicate, generic, non-2xx) → Tasks 5 (incl. field-mapping override step), 7, 8, 10, 11, 12. ✅
- CI → Task 13. Docs → Task 14. ✅

**Placeholder scan:** No "TBD"/"implement later". Per-provider triggers carry complete code; the few "align with the handler" steps point at a specific named file + the known-good `CapturePipelineTest` shape to copy — concrete, not vague.

**Type/name consistency:** `bono_test_form_ids` option, `BONO_TEST_*` env prefix, `wpEval(file, env)`, `resetMock/getRequests/setMockResponse/waitForRequests` helpers, mock control routes (`/__control/reset|requests|response`), and the `/api/wordpress/submissions` path are used consistently across tasks.

**Known risk carried from spec:** container→host networking for the mock (Task 13 note) and per-plugin native-shape fidelity (each trigger task points to the real handler file + reusable known-good shapes).
