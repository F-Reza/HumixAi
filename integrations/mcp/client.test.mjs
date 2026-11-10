import test from 'node:test';
import assert from 'node:assert/strict';
import { PassThrough } from 'node:stream';
import { HumixClient, normalizeAppUrl } from './client.mjs';
import { createHandler, startServer, TOOLS } from './server.mjs';

const modern = { 'io.modelcontextprotocol/protocolVersion': '2026-07-28', 'io.modelcontextprotocol/clientCapabilities': {} };
test('app URL only accepts HTTPS or loopback HTTP without URL credentials', () => {
  assert.equal(normalizeAppUrl('http://127.0.0.1:8000/'), 'http://127.0.0.1:8000');
  assert.equal(normalizeAppUrl('https://app.example/studio/'), 'https://app.example/studio');
  for (const value of ['http://example.test', 'https://user:secret@app.test', 'https://app.test?token=secret', 'file:///private']) assert.throws(() => normalizeAppUrl(value));
});
test('API credentials stay in a header and actual nullable result is preserved', async () => {
  let seen;
  const client = new HumixClient({ url: 'https://app.example', token: 'private-token', fetchImpl: async (url, options) => {
    seen = { url, options };
    return new Response(JSON.stringify({ success: true, data: { status: 'unavailable', overall_score: null } }));
  } });
  assert.deepEqual(await client.request('/api/v1/style', { method: 'POST', body: { text: 'A private draft.' } }), { status: 'unavailable', overall_score: null });
  assert.equal(seen.url, 'https://app.example/api/v1/style');
  assert.equal(seen.options.headers.Authorization, 'Bearer private-token');
  assert.equal(seen.options.redirect, 'error');
  assert.ok(!seen.url.includes('private'));
});
test('missing token, unsupported path and HTTP failures are safe', async () => {
  let called = false;
  const fetchImpl = async () => { called = true; return new Response('secret-token private-draft stack', { status: 500 }); };
  const empty = new HumixClient({ url: 'https://app.example', fetchImpl });
  await assert.rejects(empty.request('/api/v1/jobs'), /Set HUMIXAI_TOKEN/);
  assert.equal(called, false);
  const client = new HumixClient({ url: 'https://app.example', token: 'secret-token', fetchImpl });
  await assert.rejects(client.request('/api/v1/update-key'), /not supported/);
  await assert.rejects(client.request('/api/v1/jobs'), error => !error.message.includes('secret-token') && !error.message.includes('private-draft'));
});
test('client rejects oversized API responses and malformed JSON', async () => {
  const options = { url: 'https://app.example', token: 'token' };
  await assert.rejects(new HumixClient({ ...options, fetchImpl: async () => new Response('x'.repeat(1000001)) }).request('/api/v1/jobs'), /size limit/);
  await assert.rejects(new HumixClient({ ...options, fetchImpl: async () => new Response('not-json') }).request('/api/v1/jobs'), /unreadable/);
});
test('modern discovery and legacy initialization both expose six bounded tools', async () => {
  const handler = createHandler({ request: async () => { throw new Error('should not call'); } });
  const discovered = await handler({ jsonrpc: '2.0', id: 1, method: 'server/discover', params: { _meta: modern } });
  assert.equal(discovered.resultType, 'complete');
  assert.ok(discovered.supportedVersions.includes('2026-07-28'));
  const initialized = await handler({ jsonrpc: '2.0', id: 2, method: 'initialize', params: { protocolVersion: '2025-11-25', capabilities: {} } });
  assert.equal(initialized.protocolVersion, '2025-11-25');
  const listed = await handler({ jsonrpc: '2.0', id: 3, method: 'tools/list' });
  assert.equal(listed.tools.length, 6);
  assert.ok(TOOLS.every(tool => tool.inputSchema.additionalProperties === false));
});
test('consent is mandatory before calls and caller cannot inject a URL or credentials', async () => {
  let calls = 0;
  const handler = createHandler({ request: async () => { calls++; return {}; } });
  for (const args of [{ text: 'A sufficiently long source.', consent: false }, { text: 'A sufficiently long source.', consent: true, url: 'https://evil.test' }]) {
    await assert.rejects(handler({ jsonrpc: '2.0', id: calls + 1, method: 'tools/call', params: { _meta: modern, name: 'humix_detect', arguments: args } }), /invalid arguments/);
  }
  assert.equal(calls, 0);
});
test('job submissions use action scopes on the server and job retrieval never starts a new job', async () => {
  const calls = [];
  const handler = createHandler({ request: async (path, options) => { calls.push({ path, options }); return { id: '019a0000-0000-7000-8000-000000000001', status: 'queued' }; } });
  const submit = await handler({ jsonrpc: '2.0', id: 1, method: 'tools/call', params: { _meta: modern, name: 'humix_combo', arguments: { text: 'A sufficiently long source.', consent: true, mode: 'academic' } } });
  assert.equal(calls[0].path, '/api/v1/jobs');
  assert.equal(calls[0].options.body.action, 'combo');
  assert.equal(calls[0].options.body.external_processing_consent, true);
  assert.match(calls[0].options.body.idempotency_key, /^mcp_/);
  await handler({ jsonrpc: '2.0', id: 2, method: 'tools/call', params: { _meta: modern, name: 'humix_job', arguments: { id: submit.structuredContent.id, consent: true } } });
  assert.equal(calls[1].path, '/api/v1/jobs/019a0000-0000-7000-8000-000000000001');
  assert.equal(calls[1].options.method, undefined);
});
test('stdio emits JSON messages only, supports newlines and ignores valid notifications', async () => {
  const input = new PassThrough(), output = new PassThrough();
  let written = '';
  output.on('data', chunk => { written += chunk; });
  const stop = startServer({ input, output, handler: createHandler() });
  input.write(JSON.stringify({ jsonrpc: '2.0', id: 'init', method: 'initialize', params: { protocolVersion: '2025-11-25' } }) + '\n');
  await new Promise(resolve => setImmediate(resolve));
  input.write(JSON.stringify({ jsonrpc: '2.0', method: 'notifications/initialized' }) + '\n');
  input.write(JSON.stringify({ jsonrpc: '2.0', id: 'tools', method: 'tools/list' }) + '\n');
  await new Promise(resolve => setImmediate(resolve));
  const messages = written.trim().split('\n').map(JSON.parse);
  assert.equal(messages.length, 2);
  assert.equal(messages[0].id, 'init');
  assert.equal(messages[1].result.tools.length, 6);
  stop(); input.end(); output.end();
});
test('modern unknown versions return a protocol error without making a request', async () => {
  const handler = createHandler();
  await assert.rejects(handler({ jsonrpc: '2.0', id: 1, method: 'tools/list', params: { _meta: { ...modern, 'io.modelcontextprotocol/protocolVersion': '1900-01-01' } } }), error => error.code === -32022 && error.data.supported.includes('2026-07-28'));
});
