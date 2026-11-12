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
    ids.get('mode').value = 'standard'; ids.get('language').value = 'auto'; ids.get('strength').value = 'balanced'; ids.get('length').value = 'similar';
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
    const navigations = [], windowEvents = {};
    const location = { search, origin: 'http://localhost', assign(url) { navigations.push(url); } };
    const context = { document, window: { addEventListener(name, handler) { windowEvents[name] = handler; }, confirm: () => true, print() {} }, location, localStorage: storage(), sessionStorage: storage(), navigator: { clipboard: { writeText: async () => {} } }, fetch, FormData, URL, URLSearchParams, Blob, setTimeout: (callback, delay) => { const timer = setTimeout(callback, delay === 1100 ? 0 : delay); timer.unref?.(); return timer; }, clearTimeout, console };
    vm.runInNewContext(source, context, { filename: 'humixai.js' });
    return { ids, all, requests, navigations, windowEvents, async consent() { ids.get('externalConsent').checked = true; await ids.get('externalConsent').fire('change'); } };
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


test('logout does not depend on saving a draft and bypasses the navigation warning', async () => {
    const app = mount({ history: false, extraResponses: {'/logout': {logged_out:true}} });
    await tick();
    app.ids.get('inputText').value = 'An unsaved private session draft.';
    await app.all.find(e => e.classList.contains('logout-form')).fire('submit');
    assert.ok(app.requests.some(r => r.url === '/logout' && r.method === 'POST'));
    assert.equal(app.requests.some(r => r.url === '/documents' && r.method === 'POST'), false);
    assert.deepEqual(app.navigations, ['/login']);
    let prevented = false;
    app.windowEvents.beforeunload({preventDefault(){prevented=true;}});
    assert.equal(prevented, false);
});

test('logout failure retains the draft and allows another sign-out attempt', async () => {
    let attempts = 0;
    const app = mount({ extraResponses: {'/logout': () => { if (++attempts === 1) throw new Error('Network unavailable'); return {logged_out:true}; }} });
    await tick();
    app.ids.get('inputText').value = 'Keep this draft if logout fails.';
    const form = app.all.find(e => e.classList.contains('logout-form'));
    await form.fire('submit');
    assert.equal(app.ids.get('inputText').value, 'Keep this draft if logout fails.');
    assert.deepEqual(app.navigations, []);
    let prevented = false;
    app.windowEvents.beforeunload({preventDefault(){prevented=true;}});
    assert.equal(prevented, true);
    await form.fire('submit');
    assert.equal(attempts, 2);
    assert.deepEqual(app.navigations, ['/login']);
});


test('saved voice loads for review without automatically submitting a provider request', async () => {
    const app = mount({ search:'?voice=profile-1', extraResponses:{'/voice-profiles/profile-1':{sample:'My own writing sample for tone.',preferences:'Use short sentences.',language:'en'}} });
    await tick();
    assert.equal(app.ids.get('voiceSample').value,'My own writing sample for tone.');
    assert.equal(app.ids.get('voicePreferences').value,'Use short sentences.');
    assert.equal(app.ids.get('advancedControls').hidden,false);
    assert.equal(app.requests.some(r=>r.url==='/ai-tools/jobs'),false);
    await app.ids.get('clearVoice').fire('click');
    assert.equal(app.ids.get('voiceSample').value,'');
    assert.equal(app.ids.get('voicePreferences').value,'');
});

for (const button of ['detectButton', 'humanizeButton', 'comboButton']) {
    test(`${button} saves API-compatible length before queueing`, async () => {
        const app = mount({ job: { id: 'length-test', status: 'failed', stage: 'failed', error: 'Fixture ends processing.' } });
        await tick(); app.ids.get('inputText').value = 'A test draft long enough to send for processing.';
        await app.consent(); await app.ids.get(button).fire('click');
        const save = app.requests.find(r => r.url === '/documents' && r.method === 'POST');
        const job = app.requests.find(r => r.url === '/ai-tools/jobs' && r.method === 'POST');
        assert.ok(save); assert.ok(job);
        assert.equal(save.body.settings.length, 'similar'); assert.equal(job.body.length, 'similar');
        assert.ok(app.requests.indexOf(save) < app.requests.indexOf(job));
    });
}
test('legacy preserve length is normalized before saving', async () => {
    const app = mount({ job: { id: 'legacy', status: 'failed', error: 'Fixture' } });
    await tick(); app.ids.get('inputText').value = 'A legacy draft with the old keep-length setting.';
    app.ids.get('length').value = 'preserve'; await app.consent(); await app.ids.get('detectButton').fire('click');
    assert.equal(app.requests.find(r => r.url === '/documents' && r.method === 'POST').body.settings.length, 'similar');
});
test('consent dialog blocks provider submission until checked and continues chosen action', async () => {
    const app = mount({ job: { id: 'consent', status: 'failed', error: 'Fixture' } });
    await tick(); app.ids.get('inputText').value = 'A draft requiring explicit processing consent.';
    await app.ids.get('detectButton').fire('click');
    assert.equal(app.ids.get('consentDialog').open, true);
    assert.equal(app.requests.some(r => r.url === '/ai-tools/jobs'), false);
    await app.ids.get('confirmConsent').fire('click');
    assert.equal(app.requests.some(r => r.url === '/ai-tools/jobs'), false);
    app.ids.get('consentCheckbox').checked = true; await app.ids.get('consentCheckbox').fire('change');
    assert.equal(app.ids.get('confirmConsent').disabled, false);
    await app.ids.get('confirmConsent').fire('click'); await tick();
    assert.equal(app.ids.get('consentDialog').open, false);
    assert.equal(app.requests.find(r => r.url === '/ai-tools/jobs').body.action, 'detect');
});
test('keep-length template uses the backend canonical value', () => {
    assert.match(template, /option value="similar"/);
    assert.doesNotMatch(template, /option value="preserve"/);
});

test('queued rewrite becomes visible in right preview after completion', async () => {
    let finish;
    const completion = new Promise(resolve => finish = resolve);
    const app = mount({job:{id:'preview',status:'queued',stage:'queued'},poll:()=>completion});
    await tick(); app.ids.get('inputText').value='Original draft for the preview test.'; await app.consent();
    const running=app.ids.get('humanizeButton').fire('click'); await tick();
    assert.equal(app.ids.get('outputProgress').hidden,false);
    finish({id:'preview',status:'completed',stage:'finished',result:{original_text:'Original draft for the preview test.',humanized_text:'The completed revision appears here.',preservation:{passed:true},notes:[]}});
    await running;
    assert.equal(app.ids.get('outputText').value,'The completed revision appears here.');
    assert.equal(app.ids.get('outputText').hidden,false);
    assert.equal(app.ids.get('outputEmpty').hidden,true);
    assert.equal(app.ids.get('outputProgress').hidden,true);
});
test('reopening a document retains completed rewrite before browser autosave', async () => {
    const original='The saved original draft.';
    const app=mount({search:'?document=existing',extraResponses:{'/documents/existing':{id:'existing',source_text:original,current_text:original,settings:{},revisions:[{input_text:original,result:{original_text:original,humanized_text:'The recovered completed rewrite.',preservation:{passed:true},notes:[]}}]}}});
    await tick(); await tick();
    assert.equal(app.ids.get('outputText').value,'The recovered completed rewrite.');
    assert.equal(app.ids.get('outputText').hidden,false);
    assert.equal(app.ids.get('outputEmpty').hidden,true);
});

test('local detection bypasses provider jobs and external consent', async () => {
    const app=mount({extraResponses:{'/ai-tools/local-patterns':{status:'completed',provider:'local',overall_score:null,local_patterns:{sentence_regularity_index:75,definition:'Not an authorship probability.'},sentences:[],message:'Local only.'}}});
    await tick(); app.ids.get('inputText').value='A sufficiently long draft for local review.';app.ids.get('detectionMode').value='local';
    await app.ids.get('detectButton').fire('click');
    assert.ok(app.requests.some(r=>r.url==='/ai-tools/local-patterns'));
    assert.equal(app.requests.some(r=>r.url==='/ai-tools/jobs'),false);
    assert.equal(app.ids.get('consentDialog').open,false);
    assert.match(app.ids.get('detectionContent').textContent,/75 \/ 100/);
});

test('auto detection uses local patterns without a key but explicit GPTZero keeps consent', async () => {
    const app=mount({extraResponses:{'/ai-tools/local-patterns':{status:'insufficient_text',provider:'local',overall_score:null,local_patterns:{sentence_regularity_index:null,definition:'No authorship verdict.'},sentences:[]}}});
    await tick();app.ids.get('inputText').value='A short draft for the mode selection test.';app.ids.get('detectionMode').value='auto';
    await app.ids.get('detectButton').fire('click');assert.ok(app.requests.some(r=>r.url==='/ai-tools/local-patterns'));
    app.ids.get('detectionMode').value='gptzero';await app.ids.get('detectButton').fire('click');assert.equal(app.ids.get('consentDialog').open,true);
});

test('local detection opens right report and left evidence without replacing revision', async () => {
    const original='A sample sentence for local review.';
    const app=mount({extraResponses:{'/ai-tools/local-patterns':{status:'completed',provider:'local',overall_score:null,local_patterns:{sentence_regularity_index:82,definition:'Sentence lengths, not authorship.'},sentences:[{text:original,start:0,end:original.length,highlight:true,score:null,reason:'Local length pattern only.'}],message:'Local analysis.',language_notice:'Not an AI verdict.'}}});
    await tick();app.ids.get('inputText').value=original;app.ids.get('outputText').value='Keep my existing rewrite.';app.ids.get('detectionMode').value='local';
    await app.ids.get('detectButton').fire('click');
    assert.equal(app.ids.get('detectionPreview').hidden,false);
    assert.match(app.ids.get('detectionPreview').textContent,/82 \/ 100/);
    assert.equal(app.ids.get('outputEmpty').hidden,true);
    assert.equal(app.ids.get('outputText').hidden,true);
    assert.equal(app.ids.get('outputText').value,'Keep my existing rewrite.');
    assert.equal(app.ids.get('evidenceArea').hidden,false);
    const sentence=app.ids.get('evidenceArea').children.find(e=>e.className?.includes('flagged'));assert.ok(sentence);
    await sentence.fire('click');assert.match(app.ids.get('evidenceArea').textContent,/Local length pattern only/);
    await app.all.find(e=>e.dataset.outputTab==='revision').fire('click');
    assert.equal(app.ids.get('detectionPreview').hidden,true);assert.equal(app.ids.get('outputText').hidden,false);
});

test('GPTZero detection shows returned probability and evidence without rewriting', async () => {
    const text='A sample submitted to a mocked detector.';
    const app=mount({job:{id:'detect-success',status:'completed',result:{status:'completed',overall_score:65,score_type:'ai_class_probability',provider:'gptzero',score_label:'GPTZero AI class probability',sentences:[{text,start:0,end:text.length,highlight:true,score:65,reason:'Provider evidence.'}]}}});
    await tick();app.ids.get('inputText').value=text;app.ids.get('detectionMode').value='gptzero';await app.consent();await app.ids.get('detectButton').fire('click');
    assert.match(app.ids.get('detectionPreview').textContent,/65%/);assert.equal(app.ids.get('detectionPreview').hidden,false);assert.equal(app.ids.get('evidenceArea').hidden,false);assert.equal(app.ids.get('outputText').value,'');
});
test('combo partial result displays rewrite while unavailable scans stay unscored', async () => {
    const text='An original draft for combo verification.';
    const unavailable={status:'unavailable',overall_score:null,provider:'gptzero',sentences:[],message:'Detector not configured.'};
    const app=mount({job:{id:'combo-partial',status:'partial',stage:'finished',result:{original_text:text,humanized_text:'A revised draft with clear wording.',original_detection:unavailable,verification:unavailable,original_score:null,humanized_score:null,score_change:null,comparable:false,preservation:{passed:true},notes:[]}}});
    await tick();app.ids.get('inputText').value=text;await app.consent();await app.ids.get('comboButton').fire('click');
    assert.equal(app.ids.get('outputText').value,'A revised draft with clear wording.');assert.equal(app.ids.get('outputText').hidden,false);assert.match(app.ids.get('comparisonContent').textContent,/No comparable/);assert.doesNotMatch(app.ids.get('detectionPreview').textContent,/\d+%/);
});
test('new document clears previous detection report', async () => {
    const app=mount({job:{id:'report',status:'completed',result:{status:'completed',overall_score:65,provider:'gptzero',sentences:[]}}});
    await tick();app.ids.get('inputText').value='A draft for a successful scan.';app.ids.get('detectionMode').value='gptzero';await app.consent();await app.ids.get('detectButton').fire('click');
    await app.ids.get('newDocument').fire('click');assert.equal(app.ids.get('detectionPreview').textContent,'');assert.equal(app.ids.get('detectionPreview').hidden,true);
});
