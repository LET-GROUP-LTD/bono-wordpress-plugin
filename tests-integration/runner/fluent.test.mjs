import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { resetMock, wpEval, waitForRequests } from './helpers.mjs';

const RUN_ID = Date.now();
beforeEach(async () => { await resetMock(); });

test('Fluent happy path + phone normalization', async () => {
  await wpEval('fluent.php', { NAME: 'Dana Cohen', EMAIL: `fluent-${RUN_ID}@example.com`, PHONE: '050-123-4567' });
  const reqs = await waitForRequests(1);
  assert.equal(reqs.length, 1);
  const p = reqs[0].body;
  assert.equal(p.provider, 'fluent');
  assert.equal(p.contact.name, 'Dana Cohen');
  assert.equal(p.contact.email, `fluent-${RUN_ID}@example.com`);
  assert.equal(p.contact.phone, '0501234567');
  assert.equal(p.validation.isValid, true);
});
