import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { resetMock, waitForRequests } from './helpers.mjs';

const exec = promisify(execFile);
const WP_ENV_BIN = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'node_modules', '.bin', 'wp-env');
const WP = process.env.WP_URL || 'http://localhost:8888';
const RUN_ID = Date.now();

beforeEach(async () => { await resetMock(); });

async function mintNonce() {
  const { stdout } = await exec(WP_ENV_BIN, [
    'run', 'cli', '--env-cwd=wp-content/plugins/bono-leads-connector',
    'wp', 'eval-file', 'tests-integration/triggers/generic-nonce.php',
  ]);
  return stdout.trim();
}

test('Generic capture via REST + nonce → normalized payload', async () => {
  const nonce = await mintNonce();
  const res = await fetch(`${WP}/index.php?rest_route=/bono/v1/capture`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Bono-Nonce': nonce },
    body: JSON.stringify({
      _bono_nonce: nonce,
      // The callback reads formId (camelCase) — not form_id.
      formId: '7',
      formName: 'Bono Generic Test',
      pageUrl: 'http://localhost:8888/generic-test-page',
      fields: {
        name: 'Dana Cohen',
        email: `gen-${RUN_ID}@example.com`,
        phone: '0501234567',
      },
    }),
  });
  assert.equal(res.status < 400, true, `Expected 2xx but got ${res.status}`);
  const reqs = await waitForRequests(1);
  assert.equal(reqs.length, 1, 'Expected exactly 1 request at mock');
  const p = reqs[0].body;
  assert.equal(p.provider, 'generic');
  assert.equal(p.contact.name, 'Dana Cohen');
  assert.equal(p.contact.email, `gen-${RUN_ID}@example.com`);
});

test('Generic capture rejects a bad nonce', async () => {
  const res = await fetch(`${WP}/index.php?rest_route=/bono/v1/capture`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Bono-Nonce': 'bogus' },
    body: JSON.stringify({
      _bono_nonce: 'bogus',
      formId: '7',
      fields: { name: 'x', email: 'y@z.com' },
    }),
  });
  assert.equal(res.status, 403, `Expected 403 but got ${res.status}`);
  assert.equal((await waitForRequests(1, 1000)).length, 0, 'Expected no requests at mock');
});
