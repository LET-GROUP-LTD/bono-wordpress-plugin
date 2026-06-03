import http from 'node:http';

export function startMockServer(port = 3001) {
  const state = { requests: [], nextResponse: { status: 200, body: { success: true, leadId: 'mock-lead' } } };

  const send = (res, status, obj) => {
    res.writeHead(status, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(obj));
  };
  const readBody = (req) => new Promise((resolve) => {
    let d = '';
    req.on('data', (c) => (d += c));
    req.on('end', () => resolve(d));
  });

  const server = http.createServer(async (req, res) => {
    const raw = await readBody(req);
    const url = req.url || '';

    if (req.method === 'POST' && url === '/__control/reset') {
      state.requests = [];
      state.nextResponse = { status: 200, body: { success: true, leadId: 'mock-lead' } };
      return send(res, 200, { ok: true });
    }
    if (req.method === 'GET' && url === '/__control/requests') {
      return send(res, 200, state.requests);
    }
    if (req.method === 'POST' && url === '/__control/response') {
      const cfg = raw ? JSON.parse(raw) : {};
      state.nextResponse = { status: cfg.status ?? 200, body: cfg.body ?? { success: true } };
      return send(res, 200, { ok: true });
    }

    // Any /api/wordpress/* — record and answer with the configured response.
    if (req.method === 'POST' && url.startsWith('/api/wordpress/')) {
      let parsed = null;
      try { parsed = raw ? JSON.parse(raw) : null; } catch { parsed = { _unparsed: raw }; }
      state.requests.push({ path: url, headers: req.headers, body: parsed, receivedAt: new Date().toISOString() });
      if (url.endsWith('/sites/register')) {
        return send(res, 200, { site_id: 'mock-site', api_key: 'mock-key' });
      }
      return send(res, state.nextResponse.status, state.nextResponse.body);
    }

    send(res, 404, { error: 'not_found' });
  });

  return new Promise((resolve) => {
    server.listen(port, '0.0.0.0', () => {
      resolve({ port: server.address().port, close: () => server.close() });
    });
  });
}

// Allow `node server.mjs` to run a long-lived instance on a fixed port (for wp-env to reach).
if (import.meta.url === `file://${process.argv[1]}`) {
  const port = Number(process.env.MOCK_PORT || 3001);
  startMockServer(port).then((s) => console.log(`mock backend listening on ${s.port}`));
}
