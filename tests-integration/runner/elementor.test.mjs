import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { resetMock, wpEval, waitForRequests } from './helpers.mjs';

const RUN_ID = Date.now();
beforeEach(async () => { await resetMock(); });

test('Elementor (simulated) happy path', async () => {
  await wpEval('elementor.php', { NAME: 'Dana Cohen', EMAIL: `elem-${RUN_ID}@example.com`, PHONE: '0501234567' });
  const reqs = await waitForRequests(1);
  assert.equal(reqs.length, 1);
  const p = reqs[0].body;
  assert.equal(p.provider, 'elementor');
  assert.equal(p.contact.name, 'Dana Cohen');
  assert.equal(p.contact.email, `elem-${RUN_ID}@example.com`);
  assert.equal(p.contact.phone, '0501234567');
});
