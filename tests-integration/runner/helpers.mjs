import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { fileURLToPath } from 'node:url';
import { join, dirname } from 'node:path';
const exec = promisify(execFile);

const MOCK = process.env.MOCK_URL || 'http://127.0.0.1:3001';
const WP = process.env.WP_URL || 'http://localhost:8888';
const PLUGIN_DIR = 'wp-content/plugins/bono-leads-connector';

// Use local binary since wp-env is not on PATH globally.
const __filename = fileURLToPath(import.meta.url);
const REPO_ROOT = join(dirname(__filename), '..', '..');
const WP_ENV_BIN = join(REPO_ROOT, 'node_modules', '.bin', 'wp-env');

const POLL_INTERVAL_MS = 150;
const POLL_TIMEOUT_MS = 4000;

export async function resetMock() {
  const res = await fetch(MOCK + '/__control/reset', { method: 'POST' });
  if (!res.ok) throw new Error(`resetMock failed: ${res.status}`);
}
export async function setMockResponse(status, body) {
  const res = await fetch(MOCK + '/__control/response', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ status, body }),
  });
  if (!res.ok) throw new Error(`setMockResponse failed: ${res.status}`);
}
export async function getRequests() {
  const res = await fetch(MOCK + '/__control/requests');
  if (!res.ok) throw new Error(`getRequests failed: ${res.status}`);
  return res.json();
}

/**
 * Run a PHP trigger inside wp-env, passing test inputs via a WP option.
 *
 * wp-env runs inside Docker so host-process env vars are not visible to PHP
 * getenv(). Instead we write the inputs to the `bono_test_trigger_env` WP
 * option before executing the trigger, and the trigger reads from that option.
 * The option is deleted after the trigger runs to keep state clean.
 */
export async function wpEval(triggerFile, env = {}) {
  const envJson = JSON.stringify(env);
  // Store env data in WP option so the PHP trigger can read it.
  await exec(WP_ENV_BIN, [
    'run', 'cli', 'wp', 'option', 'update', 'bono_test_trigger_env', envJson,
  ]);
  const args = ['run', 'cli', '--env-cwd=' + PLUGIN_DIR, 'wp', 'eval-file',
    `tests-integration/triggers/${triggerFile}`];
  const { stdout } = await exec(WP_ENV_BIN, args, { env: { ...process.env } });
  return stdout;
}

/** Read all seeded form ids from the bono_test_form_ids option in a single spawn. */
export async function getFormIds() {
  const { stdout } = await exec(WP_ENV_BIN, ['run', 'cli', 'wp', 'option', 'get', 'bono_test_form_ids', '--format=json']);
  return JSON.parse(stdout);
}

/** Read a single value (e.g. a seeded form id) from the bono_test_form_ids option. */
export async function getFormId(key) {
  return (await getFormIds())[key];
}

/** Submit the seeded CF7 form through CF7's real REST feedback endpoint. */
export async function cf7Submit(fields) {
  const ids = await getFormIds();
  const formId = ids.cf7;
  const pageId = ids.cf7_page;
  const fd = new FormData();
  fd.set('_wpcf7', formId);
  fd.set('_wpcf7_unit_tag', `wpcf7-f${formId}-p${pageId}-o1`);
  fd.set('_wpcf7_container_post', pageId);
  for (const [k, v] of Object.entries(fields)) fd.set(k, v);
  const res = await fetch(`${WP}/index.php?rest_route=/contact-form-7/v1/contact-forms/${formId}/feedback`, {
    method: 'POST', body: fd,
  });
  return res.json();
}

/** Poll the mock until at least `n` requests are recorded, or timeout. */
export async function waitForRequests(n, ms = POLL_TIMEOUT_MS) {
  const deadline = Date.now() + ms;
  while (Date.now() < deadline) {
    const r = await getRequests();
    if (r.length >= n) return r;
    await new Promise((resolve) => setTimeout(resolve, POLL_INTERVAL_MS));
  }
  return getRequests();
}
