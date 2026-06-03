import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { resetMock, wpEval, getRequests, waitForRequests } from './helpers.mjs';

const RUN_ID = Date.now();
beforeEach(async () => { await resetMock(); });

test('Forminator happy path', async () => {
  await wpEval('forminator.php', { NAME: 'Dana Cohen', EMAIL: `form-${RUN_ID}@example.com`, PHONE: '0501234567' });
  const reqs = await waitForRequests(1);
  assert.equal(reqs.length, 1);
  const p = reqs[0].body;
  assert.equal(p.provider, 'forminator');
  assert.equal(p.contact.name, 'Dana Cohen');
  assert.equal(p.contact.email, `form-${RUN_ID}@example.com`);
});

test('Forminator missing contact (name only) → skipped, zero requests', async () => {
  // Brief wait to let the fire-and-forget option delete from the previous test
  // finish before we set the option for this test, avoiding a race condition.
  await new Promise((r) => setTimeout(r, 500));
  await wpEval('forminator.php', { NAME: 'Only Name', OMIT_CONTACT: '1' });
  await new Promise((r) => setTimeout(r, 1500));
  assert.equal((await getRequests()).length, 0);
});
