import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { resetMock, wpEval, waitForRequests } from './helpers.mjs';

const RUN_ID = Date.now();
beforeEach(async () => { await resetMock(); });

test('WPForms happy path → normalized payload', async () => {
  await wpEval('wpforms.php', { NAME: 'Dana Cohen', EMAIL: `wpf-${RUN_ID}@example.com`, PHONE: '050-1234567' });
  const reqs = await waitForRequests(1);
  assert.equal(reqs.length, 1);
  const p = reqs[0].body;
  assert.equal(p.provider, 'wpforms');
  assert.equal(p.contact.name, 'Dana Cohen');
  assert.equal(p.contact.email, `wpf-${RUN_ID}@example.com`);
  assert.equal(p.contact.phone, '0501234567');
  assert.equal(p.validation.isValid, true);
});
