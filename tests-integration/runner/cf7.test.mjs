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

// Note: a "missing contact → skipped" case is NOT tested here. CF7's [email*] is
// required, so CF7 itself blocks the submission before the Bono wpcf7_before_send_mail
// hook fires. That edge case is covered on Forminator where the hook fires
// post-validation with raw arrays.

// A unique suffix per test-run prevents idempotency transients from a prior run
// (which persist in WP for 5 min) from suppressing submissions in this run.
const RUN_ID = Date.now();

test('CF7 Hebrew name preserved', async () => {
  // Use a run-unique email so the idempotency key cannot collide with a prior run.
  await cf7Submit({ 'your-name': 'דנה כהן', 'your-email': `dana-${RUN_ID}@example.com`, 'your-phone': '0501234567' });
  const reqs = await waitForRequests(1);
  assert.equal(reqs[0].body.contact.name, 'דנה כהן');
});

test('CF7 duplicate within idempotency window → second skipped', async () => {
  const fields = { 'your-name': 'Dup Person', 'your-email': `dup-${RUN_ID}@example.com`, 'your-phone': '0500000000' };
  await cf7Submit(fields);
  await waitForRequests(1);
  await cf7Submit(fields); // identical within the per-minute window
  await new Promise((r) => setTimeout(r, 1500));
  const reqs = await getRequests();
  assert.equal(reqs.length, 1, 'duplicate suppressed');
});

test('CF7 field-mapping override wins over auto-detection', async () => {
  const { execFile } = await import('node:child_process');
  const { promisify } = await import('node:util');
  const exec = promisify(execFile);
  // Resolve the wp-env binary the same way helpers.mjs does (it is not on PATH).
  const { fileURLToPath } = await import('node:url');
  const { dirname, join } = await import('node:path');
  const WP_ENV_BIN = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'node_modules', '.bin', 'wp-env');

  const { stdout } = await exec(WP_ENV_BIN, ['run', 'cli', 'wp', 'option', 'get', 'bono_test_form_ids', '--format=json']);
  const formId = JSON.parse(stdout).cf7;
  const mapping = JSON.stringify({ [`cf7:${formId}`]: { email: 'your-alt-email' } });
  await exec(WP_ENV_BIN, ['run', 'cli', 'wp', 'option', 'update', 'bono_field_mappings', mapping, '--format=json']);
  try {
    await cf7Submit({
      'your-name': 'Map Person',
      'your-email': `auto-${RUN_ID}@example.com`,
      'your-alt-email': `mapped-${RUN_ID}@example.com`,
      'your-phone': '0500000001',
    });
    const reqs = await waitForRequests(1);
    assert.equal(reqs[0].body.contact.email, `mapped-${RUN_ID}@example.com`, 'mapping overrides detection');
  } finally {
    await exec(WP_ENV_BIN, ['run', 'cli', 'wp', 'option', 'delete', 'bono_field_mappings']);
  }
});
