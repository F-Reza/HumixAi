export class ClientError extends Error {}

export function normalizeAppUrl(value) {
  let url;
  try { url = new URL(value); } catch { throw new ClientError('Set HUMIXAI_URL to your HumixAi app URL.'); }
  const local = ['localhost', '127.0.0.1', '[::1]'].includes(url.hostname);
  if ((url.protocol !== 'https:' && !(url.protocol === 'http:' && local)) || url.username || url.password || url.search || url.hash) {
    throw new ClientError('Use HTTPS, or HTTP on localhost, without credentials, query parameters or fragments.');
  }
  return url.href.replace(/\/+$/, '');
}

export class HumixClient {
  constructor({ url = '', token = '', fetchImpl = globalThis.fetch, timeout = 90000 } = {}) {
    this.url = url;
    this.token = token;
    this.fetchImpl = fetchImpl;
    this.timeout = Math.min(120000, Math.max(1000, timeout));
  }

  async request(path, { method = 'GET', body, signal } = {}) {
    const base = normalizeAppUrl(this.url);
    if (!this.token || typeof this.token !== 'string' || this.token.length > 200 || /\s/.test(this.token)) {
      throw new ClientError('Set HUMIXAI_TOKEN to a scoped HumixAi API token. No request was sent.');
    }
    if (!/^\/api\/v1\/(?:jobs(?:\/[a-f0-9-]{36})?|style|review)$/i.test(path)) {
      throw new ClientError('This API operation is not supported by the client.');
    }
    const encoded = body === undefined ? undefined : JSON.stringify(body);
    if (encoded && Buffer.byteLength(encoded, 'utf8') > 350000) throw new ClientError('The request exceeds the client size limit.');
    const controller = new AbortController();
    const cancel = () => controller.abort();
    signal?.addEventListener('abort', cancel, { once: true });
    if (signal?.aborted) controller.abort();
    const timer = setTimeout(cancel, this.timeout);
    timer.unref?.();
    try {
      const response = await this.fetchImpl(base + path, {
        method, body: encoded, redirect: 'error', credentials: 'omit', signal: controller.signal,
        headers: { Accept: 'application/json', Authorization: `Bearer ${this.token}`, ...(encoded ? { 'Content-Type': 'application/json' } : {}) },
      });
      if (!response.ok) {
        const messages = { 401: 'The HumixAi token is invalid or expired.', 403: 'The HumixAi token lacks a required scope.',
          404: 'This job or API route is unavailable.', 409: 'This operation conflicts with an existing request.',
          422: 'HumixAi rejected the input. Check its length, selection, settings and consent.',
          429: 'The HumixAi allowance or request rate limit was reached.' };
        await response.body?.cancel();
        throw new ClientError(messages[response.status] || 'The HumixAi server could not complete this request.');
      }
      const reader = response.body?.getReader();
      if (!reader) throw new ClientError('HumixAi returned an empty response.');
      const chunks = [];
      let size = 0;
      while (true) {
        const { done, value } = await reader.read();
        if (done) break;
        size += value.byteLength;
        if (size > 1000000) { await reader.cancel(); throw new ClientError('The HumixAi response exceeded the client size limit.'); }
        chunks.push(value);
      }
      let json;
      try { json = JSON.parse(Buffer.concat(chunks).toString('utf8')); } catch { throw new ClientError('HumixAi returned an unreadable response.'); }
      if (!json || typeof json !== 'object' || json.success !== true || !Object.hasOwn(json, 'data')) throw new ClientError('HumixAi did not report a successful API operation.');
      return json.data;
    } catch (error) {
      if (error instanceof ClientError) throw error;
      throw new ClientError(controller.signal.aborted ? 'The request was cancelled or timed out. A submitted job may still be running; check it in HumixAi.' : 'The HumixAi app could not be reached. Check its URL and server status.');
    } finally {
      clearTimeout(timer);
      signal?.removeEventListener('abort', cancel);
    }
  }
}
