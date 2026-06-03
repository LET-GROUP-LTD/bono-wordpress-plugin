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

export async function resetMock() {
  await fetch(MOCK + '/__control/reset', { method: 'POST' });
}
export async function setMockResponse(status, body) {
  await fetch(MOCK + '/__control/response', {
    method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ status, body }),
  });
}
export async function getRequests() {
  const res = await fetch(MOCK + '/__control/requests');
  return res.json();
}

/** Run a PHP trigger inside wp-env, passing test inputs via env vars. */
export async function wpEval(triggerFile, env = {}) {
  const args = ['run', 'cli', '--env-cwd=' + PLUGIN_DIR, 'wp', 'eval-file',
    `tests-integration/triggers/${triggerFile}`];
  const prefixed = Object.fromEntries(Object.entries(env).map(([k, v]) => [`BONO_TEST_${k}`, String(v)]));
  const { stdout } = await exec(WP_ENV_BIN, args, { env: { ...process.env, ...prefixed } });
  return stdout;
}

/** Read a value (e.g. a seeded form id) from the bono_test_form_ids option. */
export async function getFormId(key) {
  const { stdout } = await exec(WP_ENV_BIN, ['run', 'cli', 'wp', 'option', 'get', 'bono_test_form_ids', '--format=json']);
  return JSON.parse(stdout)[key];
}

/** Submit the seeded CF7 form through CF7's real REST feedback endpoint. */
export async function cf7Submit(fields) {
  const formId = await getFormId('cf7');
  const pageId = await getFormId('cf7_page');
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
export async function waitForRequests(n, ms = 4000) {
  const deadline = Date.now() + ms;
  while (Date.now() < deadline) {
    const r = await getRequests();
    if (r.length >= n) return r;
    await new Promise((res) => setTimeout(res, 150));
  }
  return getRequests();
}
