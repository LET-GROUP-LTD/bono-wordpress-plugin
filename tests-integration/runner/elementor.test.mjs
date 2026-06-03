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
  // sourceKey must resolve to the form_id set in the trigger ('elem-test-7'),
  // not fall back to the form name. Verifies the trigger sets 'form_id' correctly.
  assert.ok(p.sourceKey.startsWith('elementor:'), `sourceKey should start with 'elementor:' but got: ${p.sourceKey}`);
  assert.ok(p.sourceKey.includes('elem-test-7'), `sourceKey should include form id 'elem-test-7' but got: ${p.sourceKey}`);
  assert.equal(p.contact.name, 'Dana Cohen');
  assert.equal(p.contact.email, `elem-${RUN_ID}@example.com`);
  assert.equal(p.contact.phone, '0501234567');
});
