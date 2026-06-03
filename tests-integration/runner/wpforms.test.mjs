import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { resetMock, wpEval, waitForRequests } from './helpers.mjs';

const RUN_ID = Date.now();
beforeEach(async () => { await resetMock(); });

// WPForms first+last name split: NOT tested here.
// Bono_WPForms_Capture::extract_wpforms_fields() reads field['value'] directly — a
// single combined string. WPForms itself may deliver a name field as a pre-combined
// value OR as sub-keys (first/last), but this handler only handles the 'value' key,
// so first+last combination never reaches the plugin as separate parts. The combined
// name case is fully exercised by the happy-path test below. The first+last split
// path is covered by the Gravity Forms handler (which receives input_3_3/input_3_6).
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
