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
