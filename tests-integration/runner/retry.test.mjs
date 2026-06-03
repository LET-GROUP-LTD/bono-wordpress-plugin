import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { resetMock, setMockResponse, wpEval, waitForRequests } from './helpers.mjs';
const exec = promisify(execFile);
const WP_ENV_BIN = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'node_modules', '.bin', 'wp-env');
const RUN_ID = Date.now();

async function queueCount() {
  const { stdout } = await exec(WP_ENV_BIN, ['run', 'cli', '--env-cwd=wp-content/plugins/bono-leads-connector',
    'wp', 'eval-file', 'tests-integration/triggers/queue-count.php']);
  return Number(stdout.trim());
}

beforeEach(async () => { await resetMock(); });

test('non-2xx response enqueues the submission for retry', async () => {
  const before = await queueCount();
  await setMockResponse(500, { success: false });
  await wpEval('fluent.php', { NAME: 'Retry Me', EMAIL: `retry-${RUN_ID}@example.com`, PHONE: '0500000000' });
  await waitForRequests(1);          // the failed attempt was still sent (and recorded)
  await new Promise((r) => setTimeout(r, 1000));
  const after = await queueCount();
  assert.equal(after, before + 1, 'one row enqueued after a failed send');
});
