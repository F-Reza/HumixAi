import { normalizeSettings, characterCount, terminal, describeResult } from './settings.mjs';
const $ = id => document.getElementById(id);
let config = null;
let lastJob = null;
let controller = null;
let busy = false;
const status = (text, error = false) => { $('status').textContent = text; $('status').classList.toggle('error', error); };
const setBusy = value => { busy = value; document.querySelectorAll('[data-action],#capture,#saveSettings,#clearSettings').forEach(button => { button.disabled = value; }); $('stop').disabled = !value; $('resume').disabled = value || !lastJob; };

async function api(path, method = 'GET', body) {
  if (!config) throw new Error('Connect your HumixAi app and token first.');
  if (!/^\/api\/v1\/jobs(?:\/[a-f0-9-]{36})?$/i.test(path)) throw new Error('Unsupported API operation.');
  const timeout = new AbortController();
  const cancel = () => timeout.abort();
  controller?.signal.addEventListener('abort', cancel, { once: true });
  if (controller?.signal.aborted) timeout.abort();
  const timer = setTimeout(cancel, 90000);
  try {
    const response = await fetch(config.url + path, { method, redirect: 'error', credentials: 'omit', signal: timeout.signal,
      headers: { Accept: 'application/json', Authorization: `Bearer ${config.token}`, ...(body ? { 'Content-Type': 'application/json' } : {}) }, body: body ? JSON.stringify(body) : undefined });
    if (!response.ok) {
      await response.body?.cancel();
      const messages = { 401: 'This workspace token is invalid or expired.', 403: 'The token needs jobs and the requested action scopes.', 404: 'The job or API route was not found.', 422: 'The app rejected this draft or its settings. Check text length and consent.', 429: 'The app allowance or request rate limit was reached.' };
      throw new Error(messages[response.status] || 'The app could not complete this request. Check its server and provider configuration.');
    }
    const reader = response.body?.getReader();
    if (!reader) throw new Error('The app returned an empty response.');
    const decoder = new TextDecoder();
    let raw = '', bytes = 0;
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      bytes += value.byteLength;
      if (bytes > 1000000) { await reader.cancel(); throw new Error('The app response exceeded the client size limit.'); }
      raw += decoder.decode(value, { stream: true });
    }
    raw += decoder.decode();
    let json;
    try { json = JSON.parse(raw); } catch { throw new Error('The app returned an unreadable response.'); }
    if (json?.success !== true || !json.data || typeof json.data !== 'object') throw new Error('The app did not return a successful operation.');
    return json.data;
  } catch (error) {
    // Browser network errors must not expose URL query strings, tokens or submitted text.
    if (error instanceof TypeError || timeout.signal.aborted) throw new Error('The connection stopped or timed out. A submitted job may still be running; check your workspace.');
    throw error;
  } finally { clearTimeout(timer); controller?.signal.removeEventListener('abort', cancel); }
}

async function wait(ms, signal) {
  await new Promise((resolve, reject) => {
    const done = () => { signal.removeEventListener('abort', cancel); resolve(); };
    const timer = setTimeout(done, ms);
    const cancel = () => { clearTimeout(timer); signal.removeEventListener('abort', cancel); reject(new Error('Stopped waiting. The server job may continue; use Resume last job.')); };
    if (signal.aborted) cancel(); else signal.addEventListener('abort', cancel, { once: true });
  });
}

async function poll(job) {
  for (let attempt = 0; !terminal(job) && attempt < 90; attempt++) {
    status(`Job ${job.status || 'queued'} · ${job.stage || 'waiting'}. Closing the popup does not cancel the server job.`);
    await wait(3000, controller.signal);
    job = await api(`/api/v1/jobs/${lastJob.id}`);
  }
  if (!terminal(job)) { status('Still waiting after the polling limit. Resume later or open your workspace.'); return; }
  const result = describeResult(job);
  status(result.summary, job.status === 'failed');
  $('result').value = result.text;
  $('copy').disabled = !result.text;
}

$('saveSettings').addEventListener('click', async () => {
  try {
    const next = normalizeSettings($('appUrl').value, $('token').value);
    // Request only this selected host, during the user's explicit button action.
    if (!await chrome.permissions.request({ origins: [next.originPermission] })) throw new Error('Host access was not granted. Nothing was connected.');
    const previous = config;
    config = next;
    lastJob = null;
    await chrome.storage.session.set({ humixConnection: next });
    await chrome.storage.session.remove('humixJob');
    if (previous && previous.originPermission !== next.originPermission) await chrome.permissions.remove({ origins: [previous.originPermission] });
    $('resume').disabled = true;
    status('Connection saved for this browser session. Connectivity and provider availability have not been verified.');
  } catch (error) { status(error.message, true); }
});
$('clearSettings').addEventListener('click', async () => {
  const previous = config;
  config = null; lastJob = null; $('token').value = ''; $('consent').checked = false; $('result').value = ''; $('copy').disabled = true; $('resume').disabled = true;
  await chrome.storage.session.remove(['humixConnection', 'humixJob']);
  if (previous) await chrome.permissions.remove({ origins: [previous.originPermission] });
  status('Session connection cleared. No app request was sent.');
});
$('capture').addEventListener('click', async () => {
  try {
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    if (!tab?.id) throw new Error('Select a normal web tab first.');
    const results = await chrome.scripting.executeScript({ target: { tabId: tab.id }, func: () => {
      const active = document.activeElement;
      if (active instanceof HTMLTextAreaElement || (active instanceof HTMLInputElement && ['text', 'search', 'url', 'email', 'tel'].includes(active.type))) {
        return active.value.slice(active.selectionStart ?? 0, active.selectionEnd ?? 0);
      }
      return window.getSelection()?.toString() || '';
    } });
    const selected = results[0]?.result;
    if (!selected || typeof selected !== 'string') throw new Error('Highlight text in the page first, then reopen this popup.');
    if (characterCount(selected) > 60000) throw new Error('The selection exceeds 60,000 characters. Choose a smaller passage.');
    $('source').value = selected;
    $('source').dispatchEvent(new Event('input'));
    status('Selected text copied into this popup. Review it before approving an action. No text has been sent.');
  } catch { status('This page selection could not be read. Browser internal pages and protected frames are unavailable; paste the text instead.', true); }
});
$('source').addEventListener('input', () => { $('count').textContent = `${characterCount($('source').value).toLocaleString()} / 60,000 characters`; });
document.querySelectorAll('[data-action]').forEach(button => button.addEventListener('click', async () => {
  if (busy) return;
  try {
    if (!config) throw new Error('Connect your workspace first.');
    if (!$('consent').checked) throw new Error('Acknowledge external processing before sending this draft.');
    const text = $('source').value;
    if (!text.trim() || characterCount(text) < 15 || characterCount(text) > 60000) throw new Error('Use a draft between 15 and 60,000 characters.');
    setBusy(true); controller = new AbortController(); $('result').value = ''; $('copy').disabled = true;
    const action = button.dataset.action;
    const job = await api('/api/v1/jobs', 'POST', { text, action, mode: $('mode').value, strength: $('strength').value, external_processing_consent: true, idempotency_key: `extension_${crypto.randomUUID()}` });
    if (!/^[a-f0-9-]{36}$/i.test(job.id)) throw new Error('The app returned an invalid job identifier.');
    lastJob = { id: job.id, action };
    await chrome.storage.session.set({ humixJob: lastJob });
    await poll(job);
  } catch (error) { status(error.message, true); }
  finally { setBusy(false); controller = null; }
}));
$('resume').addEventListener('click', async () => {
  if (busy || !lastJob) return;
  try {
    if (!$('consent').checked) throw new Error('Approve reading the private job result before resuming.');
    setBusy(true); controller = new AbortController();
    await poll(await api(`/api/v1/jobs/${lastJob.id}`));
  } catch (error) { status(error.message, true); }
  finally { setBusy(false); controller = null; }
});
$('stop').addEventListener('click', () => controller?.abort());
$('copy').addEventListener('click', async () => { try { await navigator.clipboard.writeText($('result').value); status('Result copied. Review facts and meaning before using it.'); } catch { status('Clipboard access is unavailable. Select and copy the result manually.', true); } });
try {
  const saved = await chrome.storage.session.get(['humixConnection', 'humixJob']);
  if (saved.humixConnection) {
    config = normalizeSettings(saved.humixConnection.url, saved.humixConnection.token);
    $('appUrl').value = config.url; $('token').value = config.token;
    lastJob = /^[a-f0-9-]{36}$/i.test(saved.humixJob?.id || '') ? saved.humixJob : null;
    $('resume').disabled = !lastJob;
    status('Session connection loaded. No request has been sent.');
  }
} catch { status('Session storage is unavailable. Update your browser before using this extension.', true); }
