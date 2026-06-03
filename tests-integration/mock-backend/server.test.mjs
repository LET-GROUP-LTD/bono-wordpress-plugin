import { test, before, after } from 'node:test';
import assert from 'node:assert/strict';
import { startMockServer } from './server.mjs';

let server, base;
before(async () => { server = await startMockServer(0); base = `http://127.0.0.1:${server.port}`; });
after(() => server.close());

async function j(method, path, body) {
  const res = await fetch(base + path, {
    method,
    headers: { 'Content-Type': 'application/json' },
    body: body ? JSON.stringify(body) : undefined,
  });
  return { status: res.status, body: await res.json().catch(() => null) };
}

test('records a submission and exposes it via __control/requests', async () => {
  await j('POST', '/__control/reset');
  const r = await fetch(base + '/api/wordpress/submissions', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-Bono-Api-Key': 'k', 'X-Bono-Site-Id': 's' },
    body: JSON.stringify({ provider: 'cf7', contact: { name: 'A' } }),
  });
  assert.equal(r.status, 200);
  assert.equal((await r.json()).success, true);
  const { body } = await j('GET', '/__control/requests');
  assert.equal(body.length, 1);
  assert.equal(body[0].body.provider, 'cf7');
  assert.equal(body[0].headers['x-bono-api-key'], 'k');
});

test('reset clears the log', async () => {
  await j('POST', '/__control/reset');
  const { body } = await j('GET', '/__control/requests');
  assert.equal(body.length, 0);
});

test('configurable response status/body', async () => {
  await j('POST', '/__control/reset');
  await j('POST', '/__control/response', { status: 500, body: { success: false } });
  const r = await fetch(base + '/api/wordpress/submissions', { method: 'POST', body: '{}' });
  assert.equal(r.status, 500);
});
