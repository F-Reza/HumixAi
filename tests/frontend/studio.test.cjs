const assert = require('node:assert/strict');
const test = require('node:test');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const source = fs.readFileSync(path.join(root, 'public/assets/humixai.js'), 'utf8');
const template = fs.readFileSync(path.join(root, 'resources/views/ai-tools/index.blade.php'), 'utf8');
const tick = () => new Promise(resolve => setTimeout(resolve, 5));

/** A deliberately small DOM harness: executes the shipped event handlers without a browser or network. */
function mount({ history = true, search = '', job, poll, capabilities = {}, extraResponses = {} } = {}) {
    const ids = new Map();
    const all = [];
    class Element {
        constructor(tag = 'div') {
            this.tagName = tag; this.children = []; this.dataset = {}; this.attributes = {}; this.events = {};
            this.value = ''; this.hidden = false; this.disabled = false; this.readOnly = false; this.open = false;
            this.className = ''; this._text = ''; this.selectionStart = 0; this.selectionEnd = 0;
            this.classList = {
                contains: name => this.className.split(/\s+/).includes(name),
                add: (...names) => this.className = [...new Set([...this.className.split(/\s+/).filter(Boolean), ...names])].join(' '),
                remove: (...names) => this.className = this.className.split(/\s+/).filter(name => !names.includes(name)).join(' '),
                toggle: (name, value) => { const desired = value === undefined ? !this.classList.contains(name) : value; desired ? this.classList.add(name) : this.classList.remove(name); return desired; },
            };
        }
        set id(value) { this._id = value; ids.set(value, this); }
        get id() { return this._id; }
        set textContent(value) { this._text = String(value); this.children = []; }
        get textContent() { return this._text + this.children.map(child => child.textContent || '').join(''); }
        append(...children) { children.forEach(child => { if (typeof child === 'string') child = Object.assign(new Element('#text'), { textContent: child }); child.parent = this; this.children.push(child); }); }
        prepend(...children) { children.forEach(child => child.parent = this); this.children.unshift(...children); }
        replaceChildren(...children) { this._text = ''; this.children = []; this.append(...children); }
        replaceWith(child) { if (this.parent) this.parent.children.splice(this.parent.children.indexOf(this), 1, child); }
        remove() { if (this.parent) this.parent.children.splice(this.parent.children.indexOf(this), 1); }
        addEventListener(name, callback) { (this.events[name] ||= []).push(callback); }
        async fire(name, extra = {}) { const event = { target: this, preventDefault() {}, ...extra }; return Promise.all((this.events[name] || []).map(callback => callback(event))); }
        setAttribute(name, value) { this.attributes[name] = String(value); }
        getAttribute(name) { return this.attributes[name]; }
        querySelector(selector) { return this.children.find(child => matches(child, selector)) || null; }
        showModal() { this.open = true; }
        close() { this.open = false; }
        focus() {}
        scrollIntoView() {}
        click() { return this.fire('click'); }
        closest(selector) { return matches(this, selector) ? this : this.parent?.closest(selector) || null; }
        get lastElementChild() { return this.children.at(-1); }
    }
    function matches(element, selector) {
        if (selector.startsWith('.')) return element.classList.contains(selector.slice(1));
        const data = selector.match(/^\[data-([\w-]+)(?:="([^"]+)")?\]$/);
        if (data) { const name = data[1].replace(/-([a-z])/g, (_, c) => c.toUpperCase()); return name in element.dataset && (data[2] === undefined || element.dataset[name] === data[2]); }
        return element.tagName === selector;
    }
    for (const match of template.matchAll(/<([a-z][\w-]*)\b([^>]*?)(?:\/?)>/g)) {
        const element = new Element(match[1]);
        for (const attr of match[2].matchAll(/([\w-]+)="([^"]*)"/g)) {
            const [name, value] = [attr[1], attr[2]];
            if (name === 'id') element.id = value;
            else if (name === 'class') element.className = value;
            else if (name.startsWith('data-')) element.dataset[name.slice(5).replace(/-([a-z])/g, (_, c) => c.toUpperCase())] = value;
            else element.attributes[name] = value;
        }
        all.push(element);
    }
    const body = new Element('body'); body.dataset = { page: 'studio', authenticated: 'true' };
    const csrf = { content: 'test-csrf' };
    const document = {
        body,
        getElementById: id => ids.get(id),
        querySelector: selector => selector === 'meta[name="csrf-token"]' ? csrf : all.find(element => matches(element, selector)) || null,
        querySelectorAll: selector => all.filter(element => matches(element, selector)),
        createElement: tag => new Element(tag),
        createTextNode: text => { const element = new Element('#text'); element.textContent = text; return element; },
        addEventListener() {},
    };
    ids.get('mode').value = 'standard'; ids.get('language').value = 'auto'; ids.get('strength').value = 'balanced'; ids.get('length').value = 'preserve';
    for (const id of ['mode', 'language', 'strength', 'length']) ids.get(id).options = [ids.get(id).value, 'professional', 'en', 'bn', 'light', 'strong', 'shorter', 'longer'].map(value => ({ value }));
    const storage = () => { const map = new Map(); return { getItem: key => map.get(key) || null, setItem: (key, value) => map.set(key, value), removeItem: key => map.delete(key) }; };
    const requests = [];
    const fetch = async (url, options = {}) => {
        const request = { url, method: options.method || 'GET', body: options.body ? JSON.parse(options.body) : undefined }; requests.push(request);
        let data;
        if (Object.hasOwn(extraResponses, url)) data = typeof extraResponses[url] === 'function' ? await extraResponses[url](request) : extraResponses[url];
        else if (url === '/ai-tools/status') data = { user: { id: 1, history_enabled: history }, detector: { configured: false }, rewriter: { configured: true }, imports: { formats: ['txt'], url: false }, plagiarism: { configured: false }, limits: {}, ...capabilities };
        else if (url === '/account') data = { user: { id: 1, history_enabled: history }, usage: {} };
        else if (url === '/account/tokens') data = { tokens: [] };
        else if (url === '/documents' && request.method === 'GET') data = { documents: [] };
        else if (url === '/documents' || url === '/documents/existing' && request.method === 'PATCH') data = { id: 'existing', ...request.body };
        else if (url === '/documents/existing') data = { id: 'existing', title: 'Saved draft', source_text: 'Original saved source text.', current_text: 'Previously saved revision.', settings: {}, revisions: [] };
        else if (url === '/ai-tools/jobs' && request.method === 'POST') data = typeof job === 'function' ? await job(request) : job;
        else if (url.startsWith('/ai-tools/jobs/')) data = await poll();
        else throw new Error(`Unexpected request: ${request.method} ${url}`);
        return { ok: true, status: 200, headers: { get: () => 'application/json' }, json: async () => ({ success: true, data }) };
    };
    const location = { search, origin: 'http://localhost', assign() {} };
    const context = { document, window: { addEventListener() {}, confirm: () => true, print() {} }, location, localStorage: storage(), sessionStorage: storage(), navigator: { clipboard: { writeText: async () => {} } }, fetch, FormData, URL, URLSearchParams, Blob, setTimeout: (callback, delay) => { const timer = setTimeout(callback, delay === 1100 ? 0 : delay); timer.unref?.(); return timer; }, clearTimeout, console };
    vm.runInNewContext(source, context, { filename: 'humixai.js' });
    return { ids, all, requests, async consent() { ids.get('externalConsent').checked = true; await ids.get('externalConsent').fire('change'); } };
}

test('a failed rewrite preserves an existing editable revision and displays the completed baseline', async () => {
    const app = mount({ job: { id: 'job-1', status: 'failed', stage: 'failed', error: 'Protected text validation failed.', result: { original_detection: { status: 'completed', overall_score: 72, score_type: 'class_probability', sentences: [], quality: {}, word_count: 5 }, violations: ['A citation was changed.'], completed_operations: ['original_scan'], usage: { used_words: 5, reserved_words: 0, remaining: 95 } } } });
    await tick();
    app.ids.get('inputText').value = 'An original draft with a citation.';
    app.ids.get('outputText').value = 'Keep this manually edited revision.';
    await app.consent();
    await app.ids.get('comboButton').fire('click');
    assert.equal(app.ids.get('outputText').value, 'Keep this manually edited revision.');
    assert.equal(app.ids.get('outputText').readOnly, false);
    assert.match(app.ids.get('jobResultInfo').textContent, /original scan completed/i);
    assert.match(app.ids.get('jobResultInfo').textContent, /A citation was changed/);
    assert.equal(app.requests.filter(request => request.method === 'PATCH').length, 0, 'failed rewrite must not trigger an output autosave');
});

test('the revision editor stays read-only until an asynchronous rewrite finishes', async () => {
    let finish;
    const completion = new Promise(resolve => finish = resolve);
    const app = mount({ job: { id: 'job-2', status: 'queued', stage: 'queued' }, poll: () => completion });
    await tick(); app.ids.get('inputText').value = 'An original source for an asynchronous rewrite.'; app.ids.get('outputText').value = 'Existing revision.';
    await app.consent(); const running = app.ids.get('humanizeButton').fire('click');
    assert.equal(app.ids.get('outputText').readOnly, true);
    await tick(); finish({ id: 'job-2', status: 'failed', stage: 'failed', error: 'Provider unavailable.', result: null });
    await running;
    assert.equal(app.ids.get('outputText').readOnly, false);
    assert.equal(app.ids.get('outputText').value, 'Existing revision.');
});

test('history-off drafts cannot silently share or export an older saved document', async () => {
    const app = mount({ history: false, search: '?document=existing' });
    await tick(); await tick();
    assert.equal(app.ids.get('inputText').value, 'Original saved source text.');
    app.ids.get('inputText').value = 'A newer session-only source that must not export old saved data.';
    await app.ids.get('shareButton').fire('click');
    const docx = app.all.find(element => element.dataset.export === 'docx');
    await docx.fire('click');
    assert.equal(app.requests.some(request => request.url.includes('/share') || request.url.includes('/export')), false);
    assert.equal(app.ids.get('shareDialog').open, false);
});

test('unconfigured web similarity cannot submit a provider request', async () => {
    const app = mount(); await tick();
    app.ids.get('inputText').value = 'A source long enough for a web-similarity review.';
    app.ids.get('reviewType').value = 'plagiarism'; await app.consent();
    await app.ids.get('runContentCheck').fire('click');
    assert.equal(app.ids.get('plagiarismOption').disabled, true);
    assert.equal(app.requests.some(request => request.url === '/ai-tools/plagiarism'), false);
});

test('URL import fetches only a preview and identifies the returned source host', async () => {
    const app = mount({ capabilities: { imports: { url: true, formats: ['txt'] } }, extraResponses: { '/imports/url': { title: 'Public article', text: 'An extracted draft to inspect before adding it.', source_url: 'https://example.com/article', format: 'url', warnings: ['Review the extraction.'] } } });
    await tick(); app.ids.get('inputText').value = 'Keep my current studio draft.';
    app.ids.get('importUrl').value = 'https://example.com/article';
    await app.ids.get('importUrl').fire('input');
    assert.match(app.ids.get('urlHostPreview').textContent, /example\.com/);
    await app.ids.get('urlImportForm').fire('submit');
    assert.equal(app.ids.get('inputText').value, 'Keep my current studio draft.');
    assert.match(app.ids.get('importPreview').value, /An extracted draft/);
    assert.match(app.ids.get('importFilename').textContent, /example\.com/);
    assert.equal(app.ids.get('importDialog').open, true);
    assert.equal(app.requests.some(request => request.url.startsWith('/ai-tools/') && request.method === 'POST'), false);
});

test('subscription management stays available when new checkout is disabled and requires an explicit click', async () => {
    const app = mount({ extraResponses: { '/billing/status': { configured: false, can_manage: true, plan: 'pro', subscription_status: 'active', plans: [{ id: 'pro', available: false }] }, '/billing/portal': { url: 'https://billing.stripe.com/p/session-test' } } });
    await tick(); await app.ids.get('accountButton').fire('click'); await tick();
    const container = app.ids.get('billingContent');
    assert.match(container.textContent, /Subscriptions are not configured/);
    const manage = container.children.find(child => /Manage subscription securely/.test(child.textContent));
    assert.ok(manage);
    assert.equal(app.requests.some(request => request.url === '/billing/portal'), false);
    await manage.fire('click');
    assert.equal(app.requests.filter(request => request.url === '/billing/portal').length, 1);
    assert.equal(app.requests.some(request => request.url === '/billing/checkout'), false);
});
