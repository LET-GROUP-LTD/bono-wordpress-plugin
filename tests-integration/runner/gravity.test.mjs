import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { resetMock, wpEval, waitForRequests } from './helpers.mjs';

const RUN_ID = Date.now();
beforeEach(async () => { await resetMock(); });

test('Gravity (simulated) combines first+last name', async () => {
  await wpEval('gravity.php', { FIRST: 'Dana', LAST: 'Cohen', EMAIL: `grav-${RUN_ID}@example.com`, PHONE: '0501234567' });
  const reqs = await waitForRequests(1);
  assert.equal(reqs.length, 1);
  const p = reqs[0].body;
  assert.equal(p.provider, 'gravity');
  assert.equal(p.contact.name, 'Dana Cohen');
  assert.equal(p.contact.email, `grav-${RUN_ID}@example.com`);
  assert.equal(p.sourceKey.startsWith('gravity:7'), true);
});
