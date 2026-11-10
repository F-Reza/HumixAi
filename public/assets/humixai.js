(() => {
    'use strict';
    const $ = id => document.getElementById(id);
    const page = document.body.dataset.page;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const node = (tag, className, content) => {
        const element = document.createElement(tag);
        if (className) element.className = className;
        if (content !== undefined && content !== null) element.textContent = String(content);
        return element;
    };
    const clear = element => element?.replaceChildren();
    const show = (id, visible = true) => { if ($(id)) $(id).hidden = !visible; };
    const numeric = value => value !== null && value !== '' && value !== undefined && Number.isFinite(Number(value)) ? Number(value) : null;
    const display = value => typeof value === 'string' || typeof value === 'number' ? String(value) : value == null ? 'Not reported' : JSON.stringify(value);
    const words = text => (text.match(/[\p{L}\p{M}\p{N}]+(?:['’\-][\p{L}\p{M}\p{N}]+)*/gu) || []).length;
    const characters = text => Array.from(text).length;
    const dateLabel = value => {
        const date = new Date(value);
        return Number.isNaN(date.getTime()) ? 'Date not reported' : date.toLocaleString('en-GB', { timeZone: 'Asia/Dhaka', day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    };
    let toastTimer;
    function toast(message, error = false) {
        if (!$('toast')) return;
        $('toastText').textContent = message;
        $('toast').classList.toggle('error', error);
        show('toast');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => show('toast', false), 6500);
    }
    async function request(url, options = {}) {
        const headers = { Accept: 'application/json', 'X-CSRF-TOKEN': csrf, ...options.headers };
        let body = options.body;
        if (body && !(body instanceof FormData) && typeof body !== 'string') {
            body = JSON.stringify(body);
            headers['Content-Type'] = 'application/json';
        }
        const response = await fetch(url, { credentials: 'same-origin', ...options, headers, body });
        let json;
        try { json = await response.json(); } catch (_) { throw new Error(response.status === 419 ? 'Your session expired. Refresh the page and try again.' : 'The server could not return a readable response. Your draft is still here.'); }
        if (!response.ok || json.success === false) {
            const details = Object.values(json.errors || {}).flat().filter(item => typeof item === 'string');
            const error = new Error(details[0] || json.message || 'This request could not be completed.');
            error.status = response.status;
            error.payload = json;
            throw error;
        }
        return json.data === undefined ? json : json.data;
    }
    const post = (url, body) => request(url, { method: 'POST', body });
    const modal = id => { if ($(id) && !$(id).open) $(id).showModal(); };
    document.querySelectorAll('[data-close]').forEach(button => button.addEventListener('click', () => $(button.dataset.close)?.close()));
    $('dismissToast')?.addEventListener('click', () => show('toast', false));
    document.querySelectorAll('dialog').forEach(dialog => dialog.addEventListener('click', event => { if (event.target === dialog && event.offsetX >= 0 && event.offsetY >= 0 && (event.offsetX > dialog.clientWidth || event.offsetY > dialog.clientHeight)) dialog.close(); }));

    if (page === 'auth') {
        const form = $('authForm');
        form?.addEventListener('submit', async event => {
            event.preventDefault();
            const button = form.querySelector('[type="submit"]');
            button.disabled = true;
            show('authError', false);
            try {
                const values = Object.fromEntries(new FormData(form));
                values.remember = form.elements.remember?.checked || false;
                await post(form.action, values);
                let invitation = null;
                try { invitation = sessionStorage.getItem('humixai.pending-invitation'); } catch (_) {}
                location.assign(invitation ? `/?invitation=${encodeURIComponent(invitation)}` : '/');
            } catch (error) {
                $('authError').textContent = error.message;
                show('authError');
            } finally { button.disabled = false; }
        });
        return;
    }
    if (page !== 'studio') return;

    const state = {
        authenticated: document.body.dataset.authenticated === 'true', capabilities: null,
        documentId: null, documents: [], detection: null, detectionText: null,
        result: null, resultText: null, resultOutput: null, selection: null, selectionStart: null,
        originalForDiff: '', acceptedParagraphs: new Set(), busy: false, pendingAction: null,
        consent: false, saveTimer: null, savePromise: null, savedSignature: '', epoch: 0,
        activeJob: null, lastStages: [], outputEdited: false, currentScreen: 'studio', historyEnabled: true, documentLoading: false,
        consentContinuation: null, contentCheck: null, contentCheckText: null, contentCheckRevision: null,
        batchItems: [], batchJobs: [], teamId: null, teamDocumentId: null, teams: [],
    };
    const draftKey = 'humixai.anonymous-draft.v2';
    const settingIds = ['mode', 'language', 'strength', 'length', 'protectedTerms'];
    const stageLabels = { detection: 'Detection', protection: 'Protect text', original_scan: 'Original scan', generation: 'Rewrite', preservation: 'Check protection', recheck: 'Recheck revision', queued: 'Queued', preparing: 'Preparing', completed: 'Complete' };
    function settings() {
        return { mode: $('mode').value, language: $('language').value, strength: $('strength').value, length: $('length').value,
            protected_terms: $('protectedTerms').value.split(/[,\n]/).map(term => term.trim()).filter(Boolean).slice(0, 40) };
    }
    function applySettings(value = {}) {
        if (!value || typeof value !== 'object' || Array.isArray(value)) value = {};
        for (const key of ['mode', 'language', 'strength', 'length']) {
            if (typeof value[key] === 'string' && Array.from($(key).options).some(option => option.value === value[key])) $(key).value = value[key];
        }
        $('protectedTerms').value = Array.isArray(value.protected_terms) ? value.protected_terms.filter(term => typeof term === 'string').slice(0, 40).join(', ') : '';
    }
    function snapshot() {
        return { title: $('documentTitle').value.trim() || 'Untitled document', source_text: $('inputText').value, current_text: $('outputText').value || $('inputText').value, settings: settings() };
    }
    function localSave() {
        if (state.authenticated && state.documentId) return;
        try {
            const data = snapshot();
            // Voice samples and processing consent are intentionally never stored in drafts.
            data.settings = settings();
            localStorage.setItem(draftKey, JSON.stringify(data));
        } catch (_) { $('saveState').textContent = 'Browser storage unavailable'; }
    }
    function notice(message, error = false) {
        $('persistentNotice').textContent = message;
        $('persistentNotice').classList.toggle('error', error);
        show('persistentNotice', Boolean(message));
    }
    function onInput() {
        $('inputMeta').textContent = `${words($('inputText').value).toLocaleString()} words · ${characters($('inputText').value).toLocaleString()} characters`;
        show('sourceEmptyTools', !$('inputText').value.trim());
        if (state.selection) resetSelection();
        updateStaleness();
        scheduleSave();
    }
    function updateOutputMeta() {
        const text = $('outputText').value;
        $('outputMeta').textContent = text ? `${words(text).toLocaleString()} words · ${characters(text).toLocaleString()} characters` : 'Your voice, kept intact.';
        $('copyButton').disabled = !text;
        $('useRevisionButton').disabled = !text;
        show('outputEmpty', !text);
        const changes = document.querySelector('[data-output-tab="changes"]').classList.contains('active');
        show('outputText', Boolean(text) && !changes);
        show('diffArea', Boolean(text) && changes);
    }
    function updateStaleness() {
        const stale = state.detectionText !== null && $('inputText').value !== state.detectionText;
        show('staleNote', stale);
        if (state.detection) $('detectionStatus').textContent = stale ? 'Draft changed' : statusLabel(state.detection.status);
        if (state.result) renderComparison(state.result);
        const protectionStale = state.resultOutput !== null && $('outputText').value !== state.resultOutput;
        let staleProtection = $('staleProtection');
        if (!staleProtection && state.result) { staleProtection = node('p', 'stale-note', 'Revision edited after these checks. Review it again before accepting.'); staleProtection.id = 'staleProtection'; $('preservationContent').append(staleProtection); }
        if (staleProtection) staleProtection.hidden = !protectionStale;
        if (state.contentCheck) renderContentCheck(state.contentCheck, false);
    }
    function scheduleSave() {
        clearTimeout(state.saveTimer);
        if (!state.authenticated) { localSave(); return; }
        if (!state.historyEnabled) { $('saveState').textContent = 'Session draft · history off'; return; }
        if (!$('inputText').value.trim() && !state.documentId) { $('saveState').textContent = 'New document'; return; }
        $('saveState').textContent = 'Unsaved changes';
        state.saveTimer = setTimeout(() => saveDocument().catch(() => {}), 1000);
    }
    async function saveDocument(required = false) {
        clearTimeout(state.saveTimer);
        if (!state.authenticated) { localSave(); return null; }
        if (!state.historyEnabled) { $('saveState').textContent = 'Session draft · history off'; return null; }
        if (state.savePromise) { await state.savePromise; return saveDocument(required); }
        const data = snapshot();
        const signature = JSON.stringify(data);
        if (signature === state.savedSignature || !data.source_text.trim() && !state.documentId) return state.documentId;
        const epoch = state.epoch;
        const id = state.documentId;
        $('saveState').textContent = 'Saving…';
        state.savePromise = (async () => {
            try {
                const result = await request(id ? `/documents/${encodeURIComponent(id)}` : '/documents', { method: id ? 'PATCH' : 'POST', body: data });
                const saved = result.document || result;
                if (state.epoch === epoch) {
                    state.documentId = saved.id || saved.uuid || id;
                    state.savedSignature = signature;
                    $('saveState').textContent = 'All changes saved';
                    try { localStorage.removeItem(draftKey); } catch (_) {}
                    const index = state.documents.findIndex(doc => doc.id === state.documentId);
                    if (index >= 0) state.documents[index] = saved; else state.documents.unshift(saved);
                    renderDocumentList();
                }
                return saved;
            } catch (error) {
                if (state.epoch === epoch) $('saveState').textContent = 'Save failed — retry by editing';
                if (required) throw error;
                toast(`Your draft could not be saved. ${error.message}`, true);
            }
        })();
        try { await state.savePromise; } finally { state.savePromise = null; }
        if (required && state.savedSignature !== JSON.stringify(snapshot())) return saveDocument(true);
        return state.documentId;
    }
    async function loadDocuments() {
        if (!state.authenticated) { renderDocumentList(); return; }
        try {
            const data = await request('/documents');
            state.documents = data.documents || (Array.isArray(data) ? data : data.data || []);
            renderDocumentList();
        } catch (error) { toast(error.message, true); }
    }
    function emptyCollection(container, title, message, link) {
        clear(container);
        const card = node('div', 'empty-collection');
        card.append(node('h2', '', title), node('p', '', message));
        if (link) { const anchor = node('a', 'button button-secondary', 'Sign in to your workspace ↗'); anchor.href = '/login'; card.append(anchor); }
        container.append(card);
    }
    function renderDocumentList() {
        $('documentCount').textContent = state.documents.length;
        clear($('recentDocuments'));
        state.documents.slice(0, 5).forEach(doc => { const button = node('button', 'recent-document', doc.title || 'Untitled document'); button.addEventListener('click', () => openDocument(doc.id)); $('recentDocuments').append(button); });
        if (!state.documents.length) $('recentDocuments').append(node('p', 'sidebar-hint', state.authenticated ? 'Your saved drafts will appear here.' : 'Sign in to keep your drafts together.'));
        if (!state.authenticated) { emptyCollection($('documentCollection'), 'A home for your drafts.', 'Sign in to save documents and return to your writing whenever you like.', true); return; }
        if (!state.documents.length) { emptyCollection($('documentCollection'), 'Room for your next idea.', 'Your first saved document will appear here. Start with a draft in the writing studio.'); return; }
        clear($('documentCollection'));
        [...state.documents].sort((a, b) => new Date(b.updated_at) - new Date(a.updated_at)).forEach(doc => {
            const card = node('article', 'document-tile');
            const button = node('button', 'document-open');
            const preview = doc.current_text || doc.source_text;
            button.append(node('h2', '', doc.title || 'Untitled document'), node('p', '', preview ? preview.slice(0, 160) + (preview.length > 160 ? '…' : '') : `${doc.revisions_count || 0} saved revisions. Open this document to continue writing.`));
            button.addEventListener('click', () => openDocument(doc.id));
            const footer = node('footer');
            const remove = node('button', 'text-button', 'Delete');
            remove.addEventListener('click', async () => {
                if (!window.confirm(`Delete “${doc.title || 'Untitled document'}” and its saved revisions?`)) return;
                try { await request(`/documents/${encodeURIComponent(doc.id)}`, { method: 'DELETE' }); if (state.documentId === doc.id) await newDocument(false); await loadDocuments(); toast('Document deleted.'); } catch (error) { toast(error.message, true); }
            });
            footer.append(node('span', '', dateLabel(doc.updated_at)), remove);
            card.append(button, footer); $('documentCollection').append(card);
        });
    }
    function resetAnalysis() {
        state.detection = null; state.detectionText = null; state.result = null; state.resultText = null; state.resultOutput = null; state.originalForDiff = ''; state.acceptedParagraphs.clear(); state.outputEdited = false;
        $('outputText').value = ''; $('detectionStatus').textContent = 'Not scanned';
        clear($('detectionContent'));
        const placeholder = node('div', 'score-placeholder', '—'); placeholder.append(node('span', '', 'No result yet'));
        $('detectionContent').append(placeholder, node('p', 'insight-description', 'A signal to explore, with the source and score meaning kept visible.'));
        clear($('qualityContent')); $('qualityContent').append(node('p', 'insight-description empty-insight', 'Review local writing signals for this draft.'));
        clear($('preservationContent')); $('preservationContent').append(node('p', 'insight-description empty-insight', 'After a rewrite, protected text checks will appear here.'));
        show('staleNote', false); show('comparisonPanel', false); show('jobPanel', false); show('evidenceDot', false); notice(''); resetSelection(); sourceTab('edit'); outputTab('revision'); updateOutputMeta();
    }
    async function newDocument(save = true) {
        if (state.busy || state.documentLoading) { toast('Let the current document operation finish before switching.'); return; }
        if (save) { try { await saveDocument(true); } catch (error) { toast(`Save the current draft before starting another. ${error.message}`, true); return; } }
        clearTimeout(state.saveTimer); state.epoch++; state.documentId = null; state.savedSignature = '';
        $('documentTitle').value = 'Untitled document'; $('inputText').value = ''; $('saveState').textContent = state.authenticated ? 'New document' : 'Local draft';
        resetAnalysis(); onInput(); setScreen('studio'); $('inputText').focus();
    }
    async function openDocument(id) {
        if (state.busy || state.documentLoading) { toast('Let the current document operation finish before switching.'); return; }
        state.documentLoading = true;
        try {
            await saveDocument(true);
            const response = await request(`/documents/${encodeURIComponent(id)}`);
            const doc = response.document || response;
            clearTimeout(state.saveTimer); state.epoch++; state.documentId = doc.id; resetAnalysis();
            $('documentTitle').value = doc.title || 'Untitled document'; $('inputText').value = doc.source_text || doc.current_text || ''; applySettings(doc.settings || {});
            const revisions = Array.isArray(doc.revisions) ? doc.revisions : [];
            const matching = revisions.filter(revision => revision.input_text === $('inputText').value && revision.result && typeof revision.result === 'object');
            const rewrite = matching.find(revision => typeof revision.result.humanized_text === 'string');
            const analysis = matching.find(revision => revision.result.status && revision.result.overall_score !== undefined);
            const verifiedRevision = revisions.find(revision => revision.result?.humanized_text === $('inputText').value && revision.result?.verification);
            if (rewrite) renderHumanized(rewrite.result, $('inputText').value);
            if (analysis) renderDetection(analysis.result, $('inputText').value);
            else if (!rewrite?.result?.original_detection && verifiedRevision) renderDetection(verifiedRevision.result.verification, $('inputText').value);
            $('outputText').value = doc.current_text && doc.current_text !== $('inputText').value ? doc.current_text : '';
            state.originalForDiff = $('inputText').value;
            updateOutputMeta(); state.savedSignature = JSON.stringify(snapshot()); $('saveState').textContent = 'All changes saved';
            $('inputMeta').textContent = `${words($('inputText').value)} words · ${characters($('inputText').value)} characters`; show('sourceEmptyTools', !$('inputText').value.trim()); updateStaleness(); setScreen('studio');
        } catch (error) { toast(error.message, true); }
        finally { state.documentLoading = false; }
    }
    function setScreen(name) {
        state.currentScreen = name;
        document.querySelectorAll('.screen').forEach(screen => screen.hidden = screen.id !== `screen-${name}`);
        document.querySelectorAll('[data-screen]').forEach(button => button.classList.toggle('active', button.dataset.screen === name));
        $('screenName').textContent = { studio: 'Writing studio', documents: 'My documents', history: 'Revision history', settings: 'Preferences', batch: 'Batch review', teams: 'Team workspace' }[name];
        $('sidebar').classList.remove('open'); show('sidebarScrim', false);
        if (name === 'documents') loadDocuments();
        if (name === 'history') loadHistory();
        if (name === 'batch') loadBatchJobs();
        if (name === 'teams') loadTeams();
    }
    async function loadHistory() {
        if (!state.authenticated) { emptyCollection($('revisionList'), 'Keep your document journey.', 'Sign in to save versions of your drafts and review completed analyses.', true); return; }
        if (!state.documentId) { emptyCollection($('revisionList'), 'A fresh start.', 'Write and save a document, then run an analysis or improvement to create its history.'); return; }
        try {
            await saveDocument(true);
            const data = await request(`/documents/${encodeURIComponent(state.documentId)}/revisions`);
            const revisions = data.revisions || (Array.isArray(data) ? data : data.data || []);
            $('historySubtitle').textContent = `Saved versions of “${$('documentTitle').value}”.`;
            if (!revisions.length) { emptyCollection($('revisionList'), 'The story is just starting.', 'Completed analyses and saved changes appear here when history is enabled.'); return; }
            clear($('revisionList'));
            revisions.forEach((revision, index) => {
                const card = node('article', 'revision-card'); const header = node('div', 'section-row');
                const title = node('div'); title.append(node('h3', '', `${display(revision.action || 'Saved revision').replaceAll('_', ' ')} · ${dateLabel(revision.created_at)}`), node('small', '', revision.text_hash ? `Document fingerprint ${revision.text_hash.slice(0, 14)}…` : `Saved version ${revisions.length - index}`));
                const restore = node('button', 'button button-secondary', 'Restore to studio');
                restore.addEventListener('click', () => { if (state.busy) return; $('inputText').value = revision.input_text || revision.source_text || revision.text || ''; $('outputText').value = revision.output_text || ''; resetAnalysis(); $('inputText').value = revision.input_text || revision.source_text || revision.text || ''; if (revision.result?.humanized_text) renderHumanized(revision.result, $('inputText').value); else if (revision.result?.status) renderDetection(revision.result, $('inputText').value); state.originalForDiff = $('inputText').value; if (revision.output_text) $('outputText').value = revision.output_text; updateOutputMeta(); onInput(); setScreen('studio'); toast('Restored as the current draft. Earlier revisions remain in history.'); });
                header.append(title, restore); card.append(header, node('p', '', (revision.output_text || revision.current_text || revision.input_text || revision.source_text || revision.text || '').slice(0, 1200))); $('revisionList').append(card);
            });
        } catch (error) { emptyCollection($('revisionList'), 'History could not be loaded.', error.message); }
    }
    function resetSelection() { state.selection = null; state.selectionStart = null; show('selectionBanner', false); }
    function captureSelection() {
        const input = $('inputText');
        if (input.selectionEnd <= input.selectionStart) return;
        const value = input.value.slice(input.selectionStart, input.selectionEnd);
        if (value.trim()) { state.selection = value; state.selectionStart = characters(input.value.slice(0, input.selectionStart)); $('selectionLabel').textContent = `Rewriting selected passage · ${words(value)} words`; show('selectionBanner'); }
    }
    function sourceTab(tab) {
        document.querySelectorAll('[data-source-tab]').forEach(button => button.classList.toggle('active', button.dataset.sourceTab === tab));
        show('inputText', tab === 'edit'); show('evidenceArea', tab === 'evidence'); show('sourceEmptyTools', tab === 'edit' && !$('inputText').value.trim());
        if (tab === 'evidence') renderEvidence();
    }
    function outputTab(tab) {
        document.querySelectorAll('[data-output-tab]').forEach(button => button.classList.toggle('active', button.dataset.outputTab === tab));
        if (tab === 'changes') renderDiff(); updateOutputMeta();
    }
    function statusLabel(status) { return ({ completed: 'Scan complete', unavailable: 'Not available', insufficient_text: 'More text needed', unsupported_language: 'Language limited', error: 'Scan unavailable' })[status] || 'No AI score'; }
    function addMeta(container, entries) {
        const list = node('div', 'meta-list');
        for (const [label, value] of entries) if (value !== undefined && value !== null && value !== '') list.append(node('span', '', label), node('strong', '', display(value)));
        container.append(list);
    }
    function renderDetection(data, text) {
        if (!data || typeof data !== 'object') return;
        state.detection = data; state.detectionText = text;
        clear($('detectionContent'));
        const score = numeric(data.overall_score);
        const valid = data.status === 'completed' && score !== null;
        if (valid) {
            const scoreNode = node('div', 'score-value', `${Math.round(score * 10) / 10}%`);
            scoreNode.append(node('span', '', display(data.classification || data.score_label || 'AI signal')));
            $('detectionContent').append(scoreNode, node('div', 'score-type', data.score_label || 'Provider score'));
            const type = data.score_type || 'Provider definition';
            $('detectionContent').append(node('p', 'score-detail', `${display(type).replaceAll('_', ' ')}. ${/probability|confidence/i.test(type) ? 'This is classifier confidence, not the percentage of words written by AI.' : 'Interpret this result using the provider’s definition; it does not prove authorship.'}`));
        } else {
            const placeholder = node('div', 'score-placeholder', '—'); placeholder.append(node('span', '', statusLabel(data.status)));
            $('detectionContent').append(placeholder, node('p', 'insight-description', data.message || 'A connected detector has not returned an authorship score for this text.'));
        }
        const provider = data.provider && data.provider !== 'local' ? data.provider : null;
        addMeta($('detectionContent'), [['Provider', provider], ['Model', data.model_version], ['Language', data.language], ['Coverage', data.analyzed_characters != null && data.character_count != null ? `${data.analyzed_characters} / ${data.character_count} characters` : null], ['Scanned (Dhaka)', data.scanned_at ? dateLabel(data.scanned_at) : null]]);
        if (data.class_probabilities) addMeta($('detectionContent'), Object.entries(data.class_probabilities).filter(([, value]) => numeric(value) !== null).map(([name, value]) => [`${name} probability`, `${value}%`]));
        if (data.confidence != null) addMeta($('detectionContent'), [['Reported confidence', data.confidence]]);
        show('evidenceDot', Array.isArray(data.sentences) && data.sentences.some(sentence => sentence.highlight));
        if (data.quality) renderQuality(data.quality);
        renderEvidence(); updateStaleness();
    }
    function renderEvidence() {
        const container = $('evidenceArea'); clear(container);
        if (!state.detection || state.detectionText === null) { container.append(node('p', 'empty-evidence', 'Run detection to review sentence evidence here.')); return; }
        const text = state.detectionText; const codepoints = Array.from(text); let cursor = 0;
        const note = node('div', 'evidence-explanation'); note.hidden = true;
        const sentences = Array.isArray(state.detection.sentences) ? state.detection.sentences : [];
        for (const sentence of sentences) {
            const content = sentence.text || sentence.sentence || '';
            let start = Number.isInteger(sentence.start) ? sentence.start : -1;
            let end = Number.isInteger(sentence.end) ? sentence.end : -1;
            if (start < cursor || end <= start || codepoints.slice(start, end).join('') !== content) {
                const index = text.indexOf(content, codepoints.slice(0, cursor).join('').length);
                if (!content || index < 0) continue;
                start = characters(text.slice(0, index)); end = start + characters(content);
            }
            if (start < cursor || end > codepoints.length) continue;
            container.append(document.createTextNode(codepoints.slice(cursor, start).join('')));
            const highlight = Boolean(sentence.highlight);
            const span = node('button', `sentence-evidence${highlight ? ' flagged' : ''}`, content);
            span.type = 'button'; span.setAttribute('aria-label', `${highlight ? 'Highlighted' : 'Analyzed'} sentence: ${content}`);
            span.addEventListener('click', () => { note.replaceChildren(node('strong', '', sentence.score == null ? 'Sentence evidence' : `Sentence signal: ${sentence.score}%`), node('p', '', sentence.reason || 'No sentence-level explanation was supplied by the provider.')); note.hidden = false; });
            container.append(span); cursor = end;
        }
        container.append(document.createTextNode(codepoints.slice(cursor).join('')));
        if (!sentences.length) container.append(node('div', 'evidence-explanation', 'The provider did not return sentence highlights for this scan.'));
        else container.append(node('div', 'evidence-explanation', 'Highlights are review signals. Select a sentence to see the supplied explanation.'));
        container.append(note);
    }
    function renderQuality(quality, label = 'Original draft') {
        clear($('qualityContent'));
        const grid = node('div', 'signal-grid');
        for (const [name, value] of [['Words', quality.word_count], ['Sentences', quality.sentence_count], ['Words / sentence', quality.average_sentence_words], ['Reading time', quality.reading_time_seconds != null ? `${quality.reading_time_seconds}s` : null]]) {
            if (value === undefined || value === null) continue;
            const item = node('div', '', name); item.prepend(node('strong', '', value)); grid.append(item);
        }
        $('qualityContent').append(grid);
        const facts = [];
        if (quality.long_sentence_count != null) facts.push(`${quality.long_sentence_count} sentences over 30 words`);
        if (quality.sentence_length_standard_deviation != null) facts.push(`Sentence length variation: ${quality.sentence_length_standard_deviation} words`);
        $('qualityContent').append(node('p', 'quality-note', `${label}${facts.length ? ' · ' + facts.join(' · ') : ''}`));
        if (Array.isArray(quality.repeated_words) && quality.repeated_words.length) $('qualityContent').append(node('p', 'score-detail', `Repeated terms: ${quality.repeated_words.slice(0, 4).map(item => `${item.word} (${item.count})`).join(', ')}`));
    }
    function renderPreservation(data) {
        clear($('preservationContent'));
        if (!data) { $('preservationContent').append(node('p', 'insight-description', 'No preservation check was returned. Review the revision manually.')); return; }
        const passed = data.passed === true;
        $('preservationContent').append(node('p', passed ? 'protection-good' : 'protection-review', passed ? '✓ Protected text checks passed.' : 'Review the protected text findings.'));
        if (data.protected_count != null) $('preservationContent').append(node('p', 'score-detail', `${data.protected_count} protected passages checked.`));
        const violations = data.violations || [];
        if (violations.length) { const list = node('ul', 'finding-list'); violations.forEach(item => list.append(node('li', '', typeof item === 'string' ? item : item.message || item.type || display(item)))); $('preservationContent').append(list); }
        $('preservationContent').append(node('p', 'score-detail', 'Review facts, names, nuance and any changed claims before accepting.'));
    }
    function renderHumanized(result, submittedText) {
        state.result = result; state.resultText = result.original_text || submittedText; state.resultOutput = result.humanized_text || ''; state.originalForDiff = state.resultText; state.outputEdited = false; state.acceptedParagraphs.clear();
        $('outputText').value = result.humanized_text || '';
            if (result.original_detection) renderDetection(result.original_detection, state.resultText);
        if (result.quality_after) renderQuality(result.quality_after, 'Revised draft');
        renderPreservation(result.preservation); renderComparison(result); updateOutputMeta();
        const notes = Array.isArray(result.notes) ? result.notes.filter(item => /unavailable|experimental|cannot be compared|first exact occurrence/i.test(item)) : [];
        if (notes.length) notice(notes.join(' '));
        if ($('inputText').value !== state.resultText) notice('Your original changed while this job ran. The revision belongs to the submitted draft; compare it carefully.');
        outputTab('revision');
    }
    function renderComparison(result) {
        show('comparisonPanel'); clear($('comparisonContent'));
        const exact = $('inputText').value === state.resultText && $('outputText').value === state.resultOutput;
        const before = numeric(result.original_score), after = numeric(result.humanized_score), change = numeric(result.score_change);
        if (result.comparable === true && exact && before !== null && after !== null && change !== null) {
            const row = node('div', 'comparison-scores');
            for (const [name, value] of [['Original', `${before}%`], ['Revision', `${after}%`], ['Change', `${change > 0 ? '+' : ''}${change} pts`]]) { const item = node('div', '', name); item.append(node('strong', '', value)); row.append(item); }
            $('comparisonContent').append(row, node('p', 'score-detail', 'Same provider, model and score definition. A lower result does not establish human authorship.'));
        } else {
            $('comparisonContent').append(node('p', 'comparison-explanation', !exact ? 'The draft has changed since these results. Run Analyze & improve again to compare the exact versions.' : 'No comparable before-and-after scores are available. Your revision and protection checks remain available for review.'));
        }
    }
    function tokens(text) { return text.match(/\s+|[^\s]+/gu) || []; }
    function appendDiff(container, before, after) {
        if (before === after) { container.append(document.createTextNode(after)); return; }
        const a = tokens(before), b = tokens(after);
        if (a.length * b.length > 420000 || a.length > 1500 || b.length > 1500) { container.append(node('del', 'diff-delete', before), document.createTextNode('\n'), node('ins', 'diff-insert', after)); return; }
        const width = b.length + 1; const dp = new Uint16Array((a.length + 1) * width);
        for (let i = a.length - 1; i >= 0; i--) for (let j = b.length - 1; j >= 0; j--) dp[i * width + j] = a[i] === b[j] ? dp[(i + 1) * width + j + 1] + 1 : Math.max(dp[(i + 1) * width + j], dp[i * width + j + 1]);
        let i = 0, j = 0; let buffer = '', kind = '';
        const flush = () => { if (!buffer) return; container.append(kind === 'same' ? document.createTextNode(buffer) : node(kind === 'added' ? 'ins' : 'del', kind === 'added' ? 'diff-insert' : 'diff-delete', buffer)); buffer = ''; };
        while (i < a.length || j < b.length) {
            let next, value;
            if (i < a.length && j < b.length && a[i] === b[j]) { next = 'same'; value = a[i++]; j++; }
            else if (j < b.length && (i >= a.length || dp[i * width + j + 1] >= dp[(i + 1) * width + j])) { next = 'added'; value = b[j++]; }
            else { next = 'removed'; value = a[i++]; }
            if (next !== kind) { flush(); kind = next; } buffer += value;
        }
        flush();
    }
    function renderDiff() {
        clear($('diffArea'));
        const before = state.originalForDiff || $('inputText').value, after = $('outputText').value;
        const legend = node('div', 'diff-legend'); legend.append(node('span', 'diff-insert', 'Added'), node('span', 'diff-delete', 'Removed')); $('diffArea').append(legend);
        const a = before.split(/\n\s*\n/), b = after.split(/\n\s*\n/);
        const paragraphReview = a.length === b.length && a.length <= 100;
        if (!paragraphReview) { const block = node('div', 'diff-paragraph'); appendDiff(block, before, after); $('diffArea').append(block, node('p', 'score-detail', 'The paragraph structure changed. Review or edit the complete revision.')); return; }
        b.forEach((paragraph, index) => {
            const block = node('div', 'diff-paragraph'); appendDiff(block, a[index], paragraph);
            if (a[index] !== paragraph) {
                const actions = node('div', 'paragraph-actions');
                if (state.acceptedParagraphs.has(index)) actions.append(node('span', 'score-detail', '✓ Paragraph reviewed'));
                else {
                    const accept = node('button', 'text-button', '✓ Keep this change'); const reject = node('button', 'text-button', '↶ Restore paragraph');
                    accept.addEventListener('click', () => { state.acceptedParagraphs.add(index); renderDiff(); });
                    reject.addEventListener('click', () => { const paragraphs = $('outputText').value.split(/\n\s*\n/); paragraphs[index] = a[index]; $('outputText').value = paragraphs.join('\n\n'); state.outputEdited = true; state.acceptedParagraphs.delete(index); updateOutputMeta(); updateStaleness(); renderDiff(); scheduleSave(); toast('Original paragraph restored. Verification applies only to the unedited revision.'); });
                    actions.append(accept, reject);
                }
                block.append(actions);
            }
            $('diffArea').append(block);
        });
    }
    function setBusy(value) {
        state.busy = value;
        $('outputText').readOnly = value;
        $('outputText').setAttribute('aria-busy', String(value));
        document.querySelectorAll('[data-action]').forEach(button => button.disabled = value);
        $('newDocument').disabled = value; $('collectionNew').disabled = value; $('importButton').disabled = value;
        $('useRevisionButton').disabled = value || !$('outputText').value;
    }
    function renderJob(job) {
        show('jobPanel');
        const status = job.status || 'queued';
        const rawStage = typeof job.stage === 'string' ? job.stage : job.stage?.stage || 'queued';
        const [stage, observedStatus] = rawStage.split(':');
        const terminal = ['completed', 'partial', 'failed', 'error', 'cancelled'].includes(status);
        show('jobSpinner', !terminal);
        $('jobLabel').textContent = job.stage_message || job.message || stageLabels[stage] || stage.replaceAll('_', ' ');
        $('jobStatus').textContent = status.replaceAll('_', ' ');
        const supplied = job.stages || job.result?.stages || job.progress;
        if (Array.isArray(supplied)) state.lastStages = supplied;
        const stageMap = new Map();
        state.lastStages.forEach(item => { if (item && typeof item === 'object') stageMap.set(item.stage || item.id, item); });
        if (!terminal && stage !== 'queued') stageMap.set(stage, { stage, status: observedStatus || 'running' });
        if (terminal && state.pendingAction === 'detect' && job.result?.status) stageMap.set('detection', { stage: 'detection', status: job.result.status });
        if (terminal && Array.isArray(job.result?.completed_operations)) job.result.completed_operations.forEach(id => stageMap.set(id, { stage: id, status: 'completed' }));
        state.lastStages = [...stageMap.values()];
        clear($('jobStages'));
        const planned = state.pendingAction === 'detect' ? ['detection'] : state.pendingAction === 'humanize' ? ['protection', 'generation', 'preservation'] : ['protection', 'original_scan', 'generation', 'preservation', 'recheck'];
        planned.forEach(id => { const item = stageMap.get(id); const li = node('li', item?.status === 'completed' ? 'complete' : item?.status === 'running' ? 'running' : ['error', 'unavailable', 'failed'].includes(item?.status) ? 'error' : '', stageLabels[id] || id); if (item?.message) li.title = item.message; $('jobStages').append(li); });
    }
    async function runAction(action) {
        if (state.busy || state.documentLoading) { if (state.documentLoading) toast('Wait for the saved document to open before starting a job.'); return; }
        const text = $('inputText').value;
        if (!text.trim()) { $('inputText').focus(); toast('Start with a draft before running an AI action.'); return; }
        const limit = state.capabilities?.limits?.max_characters;
        if (limit && characters(text) > limit) { toast(`This draft exceeds the ${Number(limit).toLocaleString()} character limit. Choose a shorter draft.`, true); return; }
        if (!state.authenticated) { localSave(); modal('signinDialog'); return; }
        state.pendingAction = action;
        state.consentContinuation = null;
        if (!state.consent) { modal('consentDialog'); return; }
        setBusy(true); notice(''); state.lastStages = []; const epoch = state.epoch;
        try {
            await saveDocument(true);
            const preferences = settings();
            const payload = { text, ...preferences, language: preferences.language === 'mixed' ? 'auto' : preferences.language, length: preferences.length === 'preserve' ? 'similar' : preferences.length, voice_sample: $('voiceSample').value.slice(0, 3000) || undefined, external_processing_consent: true, document_id: state.historyEnabled ? state.documentId || undefined : undefined };
            if (action !== 'detect' && state.selection && text.includes(state.selection)) { payload.selection = state.selection; payload.selection_start = state.selectionStart; }
            const job = await post('/ai-tools/jobs', { action, ...payload });
            if (epoch !== state.epoch) return;
            state.activeJob = job.id; renderJob(job);
            clear($('jobResultInfo'));
            let current = job; let attempts = 0;
            while (!['completed', 'partial', 'failed', 'error', 'cancelled'].includes(current.status)) {
                if (++attempts > 300) { notice('This job is taking longer than expected. It may still be processing; open revision history later to review a completed result.'); return; }
                await new Promise(resolve => setTimeout(resolve, 1100));
                current = await request(`/ai-tools/jobs/${encodeURIComponent(job.id)}`);
                if (epoch !== state.epoch) return;
                renderJob(current);
            }
            const result = current.result?.data || current.result;
            if (epoch !== state.epoch) return;
            const failed = ['failed', 'error', 'cancelled'].includes(current.status);
            if (!failed && result && typeof result === 'object') {
                if (action === 'detect' && typeof result.status === 'string') renderDetection(result, text);
                else if (['completed', 'partial'].includes(current.status) && typeof result.humanized_text === 'string' && result.humanized_text.trim()) { renderHumanized(result, text); scheduleSave(); }
                if (result.stages) renderJob({ ...current, stages: result.stages });
            }
            renderJobDetails(result, text, failed);
            if (failed) {
                const details = current.error || current.message || result?.message || 'The job could not finish. Your original draft is unchanged.';
                notice(typeof details === 'string' ? details : details.message || 'The job could not finish.', true);
                const originalScan = result?.original_detection || current.error?.original_detection;
                if (originalScan) renderDetection(originalScan, text);
            } else {
                toast(current.status === 'partial' ? 'Your draft is ready. Review the checks that could not finish.' : action === 'detect' ? 'Scan finished. Review the result and its coverage.' : 'Your revision is ready for a thoughtful review.');
            }
        } catch (error) {
            notice(error.message, true);
            if (error.status === 401) modal('signinDialog');
            if (error.payload?.data?.original_detection) renderDetection(error.payload.data.original_detection, text);
            renderJob({ status: 'failed', stage: 'error', message: error.message });
        } finally { setBusy(false); state.activeJob = null; }
    }
    function renderJobDetails(result, text, failed) {
        if (!result || typeof result !== 'object') return;
        let container = $('jobResultInfo');
        if (!container) { container = node('div', 'job-result-info'); container.id = 'jobResultInfo'; $('jobPanel').append(container); }
        clear(container);
        if (failed) {
            container.append(node('p', 'quality-note', 'The attempted rewrite did not replace your current revision.'));
            if (result.original_detection?.status === 'completed') container.append(node('p', 'quality-note', 'The original scan completed and is available below. Its completed operation counts toward your allowance.'));
            const completed = Array.isArray(result.completed_operations) ? result.completed_operations : [];
            if (completed.length) container.append(node('p', 'score-detail', `Completed operations: ${completed.map(operation => stageLabels[operation] || display(operation)).join(', ')}.`));
            if (Array.isArray(result.violations) && result.violations.length) { const list = node('ul', 'finding-list'); result.violations.forEach(finding => list.append(node('li', '', typeof finding === 'string' ? finding : finding.message || display(finding)))); container.append(node('strong', 'score-detail', 'Findings from the failed attempt'), list); }
        }
        if (result.usage) {
            container.append(node('p', 'score-detail', `Daily allowance: ${result.usage.used_words ?? result.usage.used ?? 0} words used · ${result.usage.reserved_words ?? result.usage.reserved ?? 0} reserved · ${result.usage.remaining ?? 'Not reported'} remaining.`));
        }
        container.append(node('p', 'score-detail', 'Allowance counts input words for each completed scan or rewrite. Analyze & improve can use three completed operations; its temporary reservation also covers output-length variation. Unused reservations are released.'));
    }
    async function reviewStyle() {
        const text = $('inputText').value;
        if (!text.trim()) { toast('Add a draft to review its writing style.'); return; }
        $('styleButton').disabled = true;
        try { const data = await post('/ai-tools/style', { text, language: $('language').value === 'mixed' ? 'auto' : $('language').value }); renderQuality(data.quality || data); toast('Local writing signals updated. No text was sent to an AI provider.'); }
        catch (error) { toast(error.message, true); }
        finally { $('styleButton').disabled = false; }
    }
    async function loadCapabilities() {
        try {
            const data = await request('/ai-tools/status'); state.capabilities = data;
            if (data.user && typeof data.user.history_enabled === 'boolean') state.historyEnabled = data.user.history_enabled;
            const detector = data.detector || {}, rewriter = data.rewriter || {};
            const connected = Boolean(detector.configured || rewriter.configured);
            $('providerStatus').classList.toggle('connected', connected);
            $('providerStatusText').textContent = connected ? 'AI services configured' : 'Local style review available';
            clear($('connectionDetails'));
            $('connectionDetails').append(node('div', 'notice', data.external_processing_notice || `AI detection: ${detector.configured ? detector.provider || 'Connected' : 'Not configured'}. Rewriting: ${rewriter.configured ? rewriter.provider || 'Connected' : 'Not configured'}.`));
            if (data.external_processing_notice) $('consentDescription').textContent = data.external_processing_notice;
            clear($('capabilityList'));
            const imports = data.imports || {};
            const rows = [['AI detection', detector.configured], ['AI rewriting', rewriter.configured], ['Local writing review', true], ['TXT / DOCX import', imports.formats?.includes('docx') || imports.docx], ['PDF extraction', imports.pdf_available || imports.pdf], ['Public URL import', imports.url === true], ['Web similarity', data.plagiarism?.configured === true], ['Documents & history', state.authenticated]];
            rows.forEach(([name, available]) => { const row = node('div', 'capability-row'); row.append(node('span', '', name), node('strong', available ? '' : 'unavailable', available ? 'Available' : 'Not available')); $('capabilityList').append(row); });
            if (data.limits?.max_characters) $('capabilityList').append(node('p', 'muted', `Maximum draft: ${Number(data.limits.max_characters).toLocaleString()} characters.`));
            if (Array.isArray(imports.formats)) $('fileInput').accept = imports.formats.map(format => `.${format}`).join(',');
            $('importUrlButton').disabled = imports.url !== true;
            $('urlImportAvailability').textContent = imports.url === true ? 'Public URL import is available after sign-in. No JavaScript rendering or signed-in pages.' : 'Public URL import is not available on this server.';
            const similarityAvailable = data.plagiarism?.configured === true;
            $('plagiarismOption').disabled = !similarityAvailable;
            $('plagiarismOption').textContent = similarityAvailable ? 'Plagiarism / web similarity' : 'Web similarity · not configured';
            restoreCheckoutDraft(data.user?.id);
            if (data.language_notice) $('capabilityList').append(node('p', 'muted', data.language_notice));
        } catch (_) {
            $('providerStatusText').textContent = 'Connection status unavailable';
            clear($('capabilityList')); $('capabilityList').append(node('p', 'muted', 'Connection details could not be loaded. Reload the page to try again.'));
        }
    }
    async function importFile(file) {
        if (!file) return;
        const max = state.capabilities?.limits?.max_file_kb;
        if (max && file.size > max * 1024) { toast(`Choose a file smaller than ${max} KB.`, true); return; }
        $('importButton').disabled = true; toast('Extracting a preview of your document…');
        try {
            let result;
            if (/\.txt$/i.test(file.name)) result = { text: await file.text(), filename: file.name, format: 'txt', warnings: [] };
            else { const form = new FormData(); form.append('file', file); result = await post('/imports', form); }
            $('importFilename').textContent = `${result.filename || file.name} · ${words(result.text || '')} words · Review extraction before using it.`;
            $('importPreview').value = result.text || '';
            $('importWarnings').textContent = (result.warnings || []).map(display).join(' '); show('importWarnings', Boolean(result.warnings?.length));
            $('importDialog').dataset.filename = result.filename || file.name; $('importDialog').dataset.title = ''; modal('importDialog');
        } catch (error) { toast(error.message, true); }
        finally { $('importButton').disabled = false; $('fileInput').value = ''; }
    }
    function updateUrlHost() {
        try {
            const url = new URL($('importUrl').value);
            if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) throw new Error();
            $('urlHostPreview').textContent = `The server will fetch public page text from ${url.hostname}. You'll review the extracted text next.`;
        } catch (_) { $('urlHostPreview').textContent = 'Enter a public HTTP or HTTPS address without embedded sign-in credentials.'; }
    }
    async function importUrl(event) {
        event.preventDefault();
        if (!state.authenticated) { $('urlImportDialog').close(); modal('signinDialog'); return; }
        if (state.capabilities?.imports?.url !== true) { toast('Public URL import is not configured on this server.', true); return; }
        $('fetchUrlButton').disabled = true; show('urlImportError', false);
        try {
            const result = await post('/imports/url', { url: $('importUrl').value.trim() });
            let host = result.source_url;
            try { host = new URL(result.source_url).hostname; } catch (_) {}
            $('importFilename').textContent = `${result.title || 'Public page'} · ${host || 'Source host not reported'} · ${words(result.text || '')} words`;
            $('importPreview').value = result.text || '';
            $('importWarnings').textContent = (Array.isArray(result.warnings) ? result.warnings : []).map(display).join(' '); show('importWarnings', Boolean(result.warnings?.length));
            $('importDialog').dataset.filename = result.title || host || 'Imported page'; $('importDialog').dataset.title = result.title || host || 'Imported page';
            $('urlImportDialog').close(); modal('importDialog');
        } catch (error) { $('urlImportError').textContent = error.message; show('urlImportError'); }
        finally { $('fetchUrlButton').disabled = false; }
    }
    function download(name, content, type) {
        const url = URL.createObjectURL(new Blob([content], { type })); const a = node('a'); a.href = url; a.download = name; document.body.append(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000);
    }
    function reportData() { return { title: $('documentTitle').value, generated_at: new Date().toISOString(), original: $('inputText').value, revision: $('outputText').value || null, detection: state.detection, detection_stale: state.detectionText !== null && state.detectionText !== $('inputText').value, result: state.result, revision_edited_after_verification: state.resultOutput !== null && state.resultOutput !== $('outputText').value, note: 'AI detection is a review signal, not proof of authorship. Writing quality and protected-text checks are separate.' }; }
    async function exportDocument(format) {
        show('exportDropdown', false); $('exportButton').setAttribute('aria-expanded', 'false');
        const title = ($('documentTitle').value || 'HumixAi document').replace(/[<>:"/\\|?*\u0000-\u001F]/g, '-');
        if (format === 'txt') { download(`${title}.txt`, $('outputText').value || $('inputText').value, 'text/plain;charset=utf-8'); return; }
        if (format === 'json') { download(`${title}-report.json`, JSON.stringify(reportData(), null, 2), 'application/json;charset=utf-8'); return; }
        if (format === 'print') { printReport(); return; }
        if (!state.authenticated) { modal('signinDialog'); return; }
        try {
            if (!state.historyEnabled) { toast('DOCX export needs a saved document with history enabled. TXT, JSON and print use your current draft.'); return; }
            const savedId = await saveDocument(true);
            if (!savedId) { toast('Write and save a draft before exporting DOCX.'); return; }
            const response = await fetch(`/documents/${encodeURIComponent(savedId)}/export?format=${encodeURIComponent(format)}`, { credentials: 'same-origin', headers: { Accept: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' } });
            if (!response.ok || !response.headers.get('content-type')?.includes('wordprocessingml')) { const error = await response.json().catch(() => ({})); throw new Error(error.message || Object.values(error.errors || {}).flat()[0] || 'This document could not be exported.'); }
            const url = URL.createObjectURL(await response.blob()); const link = node('a'); link.href = url; link.download = `${title}.docx`; document.body.append(link); link.click(); link.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000);
        } catch (error) { toast(error.message, true); }
    }
    function printReport() {
        document.querySelector('.report-print')?.remove();
        const report = reportData(); const container = node('article', 'report-print');
        container.append(node('h1', '', report.title || 'HumixAi report'), node('p', 'print-meta', `HumixAi Studio · ${dateLabel(report.generated_at)} (Asia/Dhaka)`), node('p', '', report.note));
        if (report.detection) { const score = numeric(report.detection.overall_score); container.append(node('h2', '', 'Detection result'), node('p', '', `${report.detection.score_label || 'AI detection'}: ${score === null ? 'No score returned' : score + '%'} · ${statusLabel(report.detection.status)}${report.detection_stale ? ' · OUTDATED: original draft changed' : ''}`), node('p', 'print-meta', `Provider: ${report.detection.provider || 'Not reported'} · Model: ${report.detection.model_version || 'Not reported'} · Coverage: ${report.detection.analyzed_characters ?? 'Not reported'} / ${report.detection.character_count ?? 'Not reported'} characters`)); }
        container.append(node('h2', '', 'Original draft'), node('pre', '', report.original));
        if (report.revision) container.append(node('h2', '', 'Revised draft'), node('pre', '', report.revision));
        const comparable = report.result?.comparable === true && report.result.original_text === report.original && report.result.humanized_text === report.revision;
        if (comparable && numeric(report.result.original_score) !== null && numeric(report.result.humanized_score) !== null) container.append(node('h2', '', 'Comparable before and after'), node('p', '', `Original: ${report.result.original_score}% · Revised: ${report.result.humanized_score}% · Change: ${report.result.score_change} percentage points. These results use the same provider, model and score definition.`));
        if (report.result?.preservation) container.append(node('h2', '', 'Protected text checks'), node('p', '', report.result.preservation.passed ? 'Protected passages passed exact-text checks. Review meaning and facts manually.' : 'Review required.'), node('pre', '', JSON.stringify(report.result.preservation, null, 2)));
        if (report.revision_edited_after_verification) container.append(node('p', '', 'The revision was edited after verification. Earlier scores do not apply to this exact draft.'));
        document.body.append(container); window.print();
    }
    async function copy(text, message = 'Copied to clipboard.') {
        if (!text) return;
        try { await navigator.clipboard.writeText(text); toast(message); } catch (_) { toast('Clipboard access is unavailable. Select and copy the text directly.', true); }
    }
    async function openShare() {
        if (!state.authenticated) { modal('signinDialog'); return; }
        try { if (!state.historyEnabled) { toast('Sharing needs a saved document with history enabled. Your session draft has not been shared.'); return; } const savedId = await saveDocument(true); if (!savedId) { toast('Write and save a draft before sharing.'); return; } $('shareUrl').value = ''; $('shareDialog').dataset.documentId = savedId; modal('shareDialog'); } catch (error) { toast(error.message, true); }
    }
    async function createShare() {
        $('createShare').disabled = true;
        try { if (!state.historyEnabled) throw new Error('Enable document history and save the draft before creating a share link.'); const savedId = await saveDocument(true); if (!savedId) throw new Error('There is no saved document to share.'); const result = await post(`/documents/${encodeURIComponent(savedId)}/share`, {}); $('shareDialog').dataset.documentId = savedId; $('shareUrl').value = result.url || result.share_url || ''; toast('Read-only report link created.'); } catch (error) { toast(error.message, true); } finally { $('createShare').disabled = false; }
    }
    async function openAccount() {
        modal('accountDialog'); clear($('accountContent')); $('accountContent').append(node('p', 'muted', 'Loading account…'));
        loadBilling();
        try {
            const data = await request('/account'); const user = data.user || data; const usage = data.usage || {};
            clear($('accountContent')); $('accountContent').append(node('p', 'muted', `${user.name || ''} · ${user.email || ''}`));
            addMeta($('accountContent'), [['Daily words used', usage.used_words], ['Words reserved by jobs', usage.reserved_words], ['Daily allowance', usage.limit || data.daily_word_limit], ['Remaining', usage.remaining], ['Plan', user.plan]]);
            const row = node('label', 'checkbox-row'); const checkbox = node('input'); checkbox.type = 'checkbox'; checkbox.checked = user.history_enabled !== false;
            row.append(checkbox, node('span', '', 'Keep revision history for future drafts and analyses.')); $('accountContent').append(row);
            checkbox.addEventListener('change', async () => { checkbox.disabled = true; try { await request('/account', { method: 'PATCH', body: { history_enabled: checkbox.checked } }); state.historyEnabled = checkbox.checked; scheduleSave(); toast('History preference updated.'); } catch (error) { checkbox.checked = !checkbox.checked; toast(error.message, true); } finally { checkbox.disabled = false; } });
            $('accountContent').append(node('p', 'muted', 'Allowance counts input words for every completed processing operation. A combined analysis can include an original scan, rewrite and revised scan. Reserved words are held for pending jobs; unused reservations are released.'));
            loadTokens();
        } catch (error) { clear($('accountContent')); $('accountContent').append(node('p', 'muted', error.message)); }
    }
    async function loadTokens() {
        try {
            const data = await request('/account/tokens'); const list = data.tokens || (Array.isArray(data) ? data : []); clear($('tokenList'));
            if (!list.length) $('tokenList').append(node('p', 'muted', 'No API tokens yet.'));
            list.forEach(token => { const row = node('div', 'token-row'); const description = node('div', '', token.name); description.append(node('small', '', `Expires ${token.expires_at ? dateLabel(token.expires_at) : 'not reported'}`)); const revoke = node('button', 'text-button', 'Revoke'); revoke.addEventListener('click', async () => { try { await request(`/account/tokens/${encodeURIComponent(token.id)}`, { method: 'DELETE' }); loadTokens(); toast('Token revoked.'); } catch (error) { toast(error.message, true); } }); row.append(description, revoke); $('tokenList').append(row); });
        } catch (error) { clear($('tokenList')); $('tokenList').append(node('p', 'muted', error.message)); }
    }
    function saveCheckoutDraft() {
        try { sessionStorage.setItem('humixai.checkout-draft', JSON.stringify({ ...snapshot(), user_id: state.capabilities?.user?.id, document_id: state.documentId, history_enabled: state.historyEnabled, saved_signature: state.savedSignature })); return true; } catch (_) { return false; }
    }
    function restoreCheckoutDraft(userId) {
        if (!new URLSearchParams(location.search).has('billing')) return;
        try {
            const data = JSON.parse(sessionStorage.getItem('humixai.checkout-draft') || 'null');
            if (!data || !userId || data.user_id !== userId) return;
            sessionStorage.removeItem('humixai.checkout-draft');
            if (typeof data.source_text !== 'string' || typeof data.current_text !== 'string') return;
            $('inputText').value = data.source_text; $('outputText').value = data.current_text !== data.source_text ? data.current_text : ''; $('documentTitle').value = typeof data.title === 'string' ? data.title : 'Untitled document';
            state.documentId = typeof data.document_id === 'string' ? data.document_id : null; applySettings(data.settings); state.originalForDiff = data.source_text;
            $('inputMeta').textContent = `${words(data.source_text)} words · ${characters(data.source_text)} characters`; updateOutputMeta(); show('sourceEmptyTools', !data.source_text.trim());
            if (state.historyEnabled && state.documentId) { state.savedSignature = typeof data.saved_signature === 'string' ? data.saved_signature : ''; if (state.savedSignature !== JSON.stringify(snapshot())) scheduleSave(); else $('saveState').textContent = 'Saved draft restored'; } else $('saveState').textContent = 'Session draft restored';
        } catch (_) {}
    }
    function navigateBilling(url) {
        let target;
        try { target = new URL(url); } catch (_) { throw new Error('A valid secure billing address was not returned.'); }
        if (target.protocol !== 'https:' || !['checkout.stripe.com', 'billing.stripe.com'].includes(target.hostname)) throw new Error('The billing address was not a recognized secure Stripe page.');
        if (!saveCheckoutDraft() && !state.historyEnabled && $('inputText').value.trim()) throw new Error('Your browser could not preserve this session draft. Copy or export it before leaving for billing.');
        location.assign(target.href);
    }
    async function loadBilling() {
        const container = $('billingContent'); clear(container); container.append(node('p', 'muted', 'Checking subscription availability…'));
        try {
            const data = await request('/billing/status'); clear(container);
            addMeta(container, [['Current plan', data.plan], ['Subscription status', data.subscription_status]]);
            if (!data.configured) container.append(node('p', 'muted', 'Subscriptions are not configured. Your current allowance is available.'));
            if (data.pending_checkout) container.append(node('p', 'muted', 'An earlier checkout is pending. Review that session or manage your subscription before starting another.'));
            const plans = Array.isArray(data.plans) ? data.plans : [];
            if (data.configured === true) {
                plans.filter(plan => plan.available === true && plan.id !== data.plan).forEach(plan => {
                    const card = node('div', 'billing-plan'); card.append(node('h4', '', plan.name || plan.id), node('p', 'muted', `${Number(plan.daily_word_limit || 0).toLocaleString()} daily allowance words, counted per completed operation.`));
                    const row = node('label', 'checkbox-row'); const checkbox = node('input'); checkbox.type = 'checkbox'; row.append(checkbox, node('span', '', 'I want to view the subscription price and recurring terms in secure checkout.')); card.append(row);
                    const button = node('button', 'button button-secondary', 'View secure checkout ↗'); button.disabled = true; checkbox.addEventListener('change', () => button.disabled = !checkbox.checked);
                    button.addEventListener('click', async () => { if (!checkbox.checked) return; button.disabled = true; try { await saveDocument(true); const checkout = await post('/billing/checkout', { plan: plan.id, acknowledge_checkout: true }); navigateBilling(checkout.url); } catch (error) { toast(error.message, true); button.disabled = false; } }); card.append(button); container.append(card);
                });
                container.append(node('p', 'muted', data.notice || 'Stripe shows the price, currency and recurring terms before you approve payment. A redirect alone does not activate a plan.'));
            }
            if (data.can_manage === true) { const manage = node('button', 'button button-secondary', 'Manage subscription securely ↗'); manage.addEventListener('click', async () => { manage.disabled = true; try { await saveDocument(true); const portal = await post('/billing/portal', {}); navigateBilling(portal.url); } catch (error) { toast(error.message, true); manage.disabled = false; } }); container.append(manage); }
        } catch (error) { clear(container); container.append(node('p', 'muted', `Subscription details are unavailable. ${error.message}`)); }
    }

    function requireExternal(continuation) {
        if (!state.authenticated) { localSave(); modal('signinDialog'); return false; }
        if (!state.consent) { state.consentContinuation = continuation; modal('consentDialog'); return false; }
        return true;
    }
    function contentDescription() {
        const type = $('reviewType').value;
        show('guidelineInput', type === 'guidelines');
        $('reviewDescription').textContent = ({ grammar: 'Suggestions help you edit; they do not certify correctness.', guidelines: 'Review the draft against your own style rules. This is not an official publisher certification.', meaning: 'Compare the studio original and revision for changes in facts, nuance and intent. This is an assisted review, not a guarantee.', citations: 'Extract bibliography information using the connected provider. This does not verify citations or check plagiarism.', plagiarism: 'Review textual similarity with provider web sources. A match may be a legitimate quotation or common phrase. Similarity is separate from AI detection.' })[type];
    }
    async function runContentCheck() {
        const text = $('inputText').value, revised = $('outputText').value, type = $('reviewType').value;
        if (!text.trim()) { toast('Add a studio draft before running content checks.'); return; }
        if (type === 'plagiarism' && state.capabilities?.plagiarism?.configured !== true) { toast('Web similarity checking is not configured on this workspace.'); return; }
        if (type === 'meaning' && !revised.trim()) { toast('A revision is needed to compare meaning.'); return; }
        if (type === 'guidelines' && !$('reviewGuidelines').value.trim()) { toast('Add the guidelines you want to check.'); return; }
        if (!requireExternal(runContentCheck)) return;
        $('runContentCheck').disabled = true; clear($('contentCheckResult')); $('contentCheckResult').append(node('p', 'muted', 'Reading your draft…'));
        try {
            const endpoint = type === 'citations' ? '/ai-tools/citations' : type === 'plagiarism' ? '/ai-tools/plagiarism' : '/ai-tools/review';
            const result = await post(endpoint, { text, type: ['citations', 'plagiarism'].includes(type) ? undefined : type, revised_text: type === 'meaning' ? revised : undefined, guidelines: type === 'guidelines' ? $('reviewGuidelines').value : undefined, external_processing_consent: true });
            state.contentCheck = result; state.contentCheckText = text; state.contentCheckRevision = type === 'meaning' ? revised : null; renderContentCheck(result);
        } catch (error) { clear($('contentCheckResult')); $('contentCheckResult').append(node('div', 'notice error', error.message)); }
        finally { $('runContentCheck').disabled = false; }
    }
    function renderContentCheck(result, scroll = true) {
        const container = $('contentCheckResult'); clear(container);
        const stale = state.contentCheckText !== $('inputText').value || state.contentCheckRevision !== null && state.contentCheckRevision !== $('outputText').value;
        if (stale) container.append(node('div', 'notice', 'The draft changed after this check. Run it again to review the current version.'));
        container.append(node('h3', '', result.feedback_label || display(result.review_type || 'Content review').replaceAll('_', ' ')), node('p', 'review-summary', result.summary || result.message || 'No summary was returned.'));
        const issues = Array.isArray(result.issues) ? result.issues : [];
        if (result.score_type === 'similarity' && result.status === 'completed' && numeric(result.similarity_score) !== null) container.append(node('p', 'score-type', `${result.similarity_score}% textual similarity · Provider web coverage, not AI authorship or a plagiarism verdict.`));
        (Array.isArray(result.matches) ? result.matches : []).forEach(match => {
            const card = node('article', 'review-issue'); let url;
            try { const parsed = new URL(match.source_url); if (['http:', 'https:'].includes(parsed.protocol) && !parsed.username && !parsed.password) url = parsed.href; } catch (_) {}
            if (url) { const link = node('a', 'source-match-link', match.title || new URL(url).hostname); link.href = url; link.target = '_blank'; link.rel = 'noopener noreferrer'; card.append(link); } else card.append(node('strong', '', match.title || 'Provider source'));
            if (numeric(match.similarity) !== null) card.append(node('p', 'muted', `${match.similarity}% source similarity as reported by the provider.`));
            (Array.isArray(match.excerpts) ? match.excerpts : []).forEach(excerpt => card.append(node('blockquote', '', display(excerpt)))); container.append(card);
        });
        issues.forEach(issue => { const card = node('article', 'review-issue'); if (issue.category) card.append(node('span', 'small-tag', issue.category)); if (issue.excerpt) card.append(node('blockquote', '', issue.excerpt)); if (issue.message) card.append(node('p', '', issue.message)); if (issue.suggestion) card.append(node('p', 'review-suggestion', `Suggestion: ${issue.suggestion}`)); container.append(card); });
        if (result.evidence?.data !== undefined) { const details = node('details', 'evidence-json'); details.append(node('summary', '', 'View provider bibliography data'), node('pre', '', JSON.stringify(result.evidence.data, null, 2).slice(0, 60000))); container.append(details); }
        addMeta(container, [['Provider', result.provider], ['Model', result.model], ['Reviewed (Dhaka)', result.reviewed_at ? dateLabel(result.reviewed_at) : null], ['Status', result.status]]);
        if (result.limitations) container.append(node('p', 'muted', Array.isArray(result.limitations) ? result.limitations.join(' ') : display(result.limitations)));
        if (result.usage) container.append(node('p', 'muted', `Daily allowance: ${result.usage.used_words ?? result.usage.used ?? 'Not reported'} words used · ${result.usage.remaining ?? 'Not reported'} remaining.`));
        const exportResult = node('button', 'text-button', 'Download this review (.json)'); exportResult.addEventListener('click', () => download('humixai-content-review.json', JSON.stringify(result, null, 2), 'application/json')); container.append(exportResult);
        if (scroll) container.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
    function renderBatchItems() {
        clear($('batchItems'));
        if (!state.batchItems.length) $('batchItems').append(node('p', 'muted', 'Add documents to preview their extracted text before processing.'));
        state.batchItems.forEach((item, index) => {
            const details = node('details', 'batch-item'); const summary = node('summary'); summary.append(node('strong', '', item.filename), node('span', '', `${words(item.text)} words`)); details.append(summary);
            const preview = node('textarea', 'import-preview'); preview.value = item.text; preview.setAttribute('aria-label', `Preview ${item.filename}`); preview.addEventListener('input', () => { item.text = preview.value; summary.lastElementChild.textContent = `${words(item.text)} words`; }); details.append(preview);
            if (item.warnings?.length) details.append(node('p', 'muted', item.warnings.join(' ')));
            const remove = node('button', 'text-button', 'Remove from batch'); remove.addEventListener('click', () => { state.batchItems.splice(index, 1); renderBatchItems(); }); details.append(remove); $('batchItems').append(details);
        });
        $('runBatch').disabled = !state.batchItems.length;
    }
    async function importBatch(files) {
        $('batchImportButton').disabled = true;
        try {
            for (const file of Array.from(files).slice(0, 10 - state.batchItems.length)) {
                if (/\.txt$/i.test(file.name)) state.batchItems.push({ filename: file.name, text: await file.text(), warnings: [] });
                else { const form = new FormData(); form.append('file', file); const imported = await post('/imports', form); state.batchItems.push(imported); }
            }
            renderBatchItems(); toast('Review each extracted document before queueing. A batch holds up to 10 drafts.');
        } catch (error) { renderBatchItems(); toast(error.message, true); }
        finally { $('batchImportButton').disabled = false; $('batchFiles').value = ''; }
    }
    async function runBatch() {
        if (!requireExternal(runBatch)) return;
        if (!state.batchItems.length) return;
        $('runBatch').disabled = true;
        try {
            const preferences = settings();
            const data = await post('/ai-tools/batch', { items: state.batchItems.map(item => ({ text: item.text })), action: $('batchAction').value, ...preferences, language: preferences.language === 'mixed' ? 'auto' : preferences.language, length: preferences.length === 'preserve' ? 'similar' : preferences.length, external_processing_consent: true });
            state.batchJobs = data.jobs || []; await loadBatchJobs(); toast(`${data.count || state.batchJobs.length} documents queued. Refresh status to follow their progress.`);
        } catch (error) { toast(error.message, true); }
        finally { $('runBatch').disabled = !state.batchItems.length; }
    }
    async function loadBatchJobs() {
        if (!state.authenticated) { emptyCollection($('batchJobs'), 'A workspace for every draft.', 'Sign in to queue multiple documents and follow their progress.', true); return; }
        try {
            const data = await request('/ai-tools/jobs'); const jobs = data.jobs || (Array.isArray(data) ? data : data.data || []); state.batchJobs = jobs;
            if (!jobs.length) { emptyCollection($('batchJobs'), 'A clear queue.', 'Queued documents and their actual processing status will appear here.'); return; }
            clear($('batchJobs'));
            jobs.forEach(job => {
                const card = node('article', 'revision-card'); const row = node('div', 'section-row');
                row.append(node('h3', '', `${display(job.action || 'Document review').replaceAll('_', ' ')} · ${dateLabel(job.created_at)}`), node('span', 'small-tag', job.status)); card.append(row, node('p', 'muted', job.stage_message || stageLabels[job.stage] || job.stage || 'Waiting for processing'));
                const view = node('button', 'text-button', 'View job result ↗'); view.addEventListener('click', async () => { try { const full = await request(`/ai-tools/jobs/${encodeURIComponent(job.id)}`); const result = full.result?.data || full.result; if (!result) { toast(full.message || 'This job has not returned a result yet.'); return; } const detail = node('details', 'evidence-json'); detail.open = true; detail.append(node('summary', '', 'Job result'), node('pre', '', JSON.stringify(result, null, 2).slice(0, 100000))); view.replaceWith(detail); const exportResult = node('button', 'text-button', 'Download result (.json)'); exportResult.addEventListener('click', () => download(`humixai-job-${job.id}.json`, JSON.stringify(result, null, 2), 'application/json')); card.append(exportResult); } catch (error) { toast(error.message, true); } }); card.append(view); $('batchJobs').append(card);
            });
        } catch (error) { emptyCollection($('batchJobs'), 'Jobs could not be loaded.', error.message); }
    }
    async function loadTeams() {
        if (!state.authenticated) { clear($('teamList')); $('teamList').append(node('p', 'muted', 'Sign in to create or join a team.')); return; }
        try {
            const data = await request('/teams'); state.teams = data.teams || []; clear($('teamList'));
            if (!state.teams.length) $('teamList').append(node('p', 'muted', 'Create your first team or join with an invitation.'));
            state.teams.forEach(team => { const button = node('button', `team-list-button${state.teamId === team.id ? ' active' : ''}`); button.append(node('strong', '', team.name), node('span', '', team.role)); button.addEventListener('click', () => openTeam(team.id)); $('teamList').append(button); });
        } catch (error) { clear($('teamList')); $('teamList').append(node('p', 'muted', error.message)); }
    }
    async function openTeam(id) {
        state.teamId = id; show('teamDocumentPanel', false);
        try {
            const [data, docs] = await Promise.all([request(`/teams/${encodeURIComponent(id)}`), request(`/teams/${encodeURIComponent(id)}/documents`)]);
            const target = $('teamDetail'); clear(target); target.append(node('h2', '', data.team.name), node('p', 'muted', `Your role: ${data.role}. Reviewers can read and comment. Editors can also update shared drafts.`));
            const members = node('div', 'team-members');
            (data.members || []).forEach(member => { const row = node('div', 'capability-row'); const description = node('span', '', `${member.user?.name || 'Team member'} · ${member.role}`); row.append(description); if (data.role === 'owner' && member.role !== 'owner') { const remove = node('button', 'text-button', 'Remove'); remove.addEventListener('click', async () => { if (!window.confirm('Remove this team member and their shared document links?')) return; try { await request(`/teams/${encodeURIComponent(id)}/members/${encodeURIComponent(member.user_id)}`, { method: 'DELETE' }); openTeam(id); } catch (error) { toast(error.message, true); } }); row.append(remove); } members.append(row); }); target.append(members);
            if (data.role === 'owner') {
                const invitation = node('form', 'team-invitation-form'); invitation.append(node('h3', '', 'Invite someone to think with you'));
                const role = node('select'); role.setAttribute('aria-label', 'Invitation role'); [['reviewer', 'Reviewer · read & comment'], ['editor', 'Editor · edit & comment']].forEach(([value, label]) => { const option = node('option', '', label); option.value = value; role.append(option); });
                const email = node('input'); email.type = 'email'; email.placeholder = 'Restrict to an email (optional)'; email.setAttribute('aria-label', 'Optional invitation email');
                const button = node('button', 'button button-secondary', 'Create invitation link'); const result = node('div'); invitation.append(role, email, button, result);
                invitation.addEventListener('submit', async event => { event.preventDefault(); button.disabled = true; try { const invite = await post(`/teams/${encodeURIComponent(id)}/invitations`, { role: role.value, email: email.value || undefined }); clear(result); const link = node('input'); link.readOnly = true; link.value = invite.url; link.setAttribute('aria-label', 'Team invitation link'); const copyLink = node('button', 'text-button', 'Copy invitation'); copyLink.type = 'button'; copyLink.addEventListener('click', () => copy(invite.url)); result.append(node('p', 'muted', `Single-use invitation · expires ${dateLabel(invite.expires_at)}. Share this link yourself.`), link, copyLink); } catch (error) { toast(error.message, true); } finally { button.disabled = false; } }); target.append(invitation);
            }
            const documentHeader = node('div', 'section-row team-documents-heading'); documentHeader.append(node('h3', '', 'Shared documents'));
            const attach = node('button', 'text-button', 'Share current studio draft ↗'); attach.addEventListener('click', async () => { try { const docId = await saveDocument(true); if (!docId) { toast('Save a studio draft with history enabled before sharing it with a team.'); return; } await post(`/teams/${encodeURIComponent(id)}/documents`, { document_id: docId }); openTeam(id); toast('This document is now available to your team.'); } catch (error) { toast(error.message, true); } }); documentHeader.append(attach); target.append(documentHeader);
            if (!docs.documents?.length) target.append(node('p', 'muted', 'No documents shared with this team yet.'));
            (docs.documents || []).forEach(link => { const row = node('div', 'team-document-row'); const open = node('button', 'text-button', link.document?.title || 'Untitled shared draft'); open.addEventListener('click', () => openTeamDocument(id, link.document_id)); row.append(open, node('span', 'muted', `${link.comments_count || 0} comments`)); if (data.role === 'owner' || data.team.owner_user_id === state.capabilities?.user?.id || link.attached_by_user_id === state.capabilities?.user?.id) { const detach = node('button', 'text-button', 'Remove link'); detach.addEventListener('click', async () => { try { await request(`/teams/${encodeURIComponent(id)}/documents/${encodeURIComponent(link.document_id)}`, { method: 'DELETE' }); openTeam(id); } catch (error) { toast(error.message, true); } }); row.append(detach); } target.append(row); });
            loadTeams();
        } catch (error) { clear($('teamDetail')); $('teamDetail').append(node('p', 'muted', error.message)); }
    }
    async function openTeamDocument(teamId, documentId) {
        try {
            const data = await request(`/teams/${encodeURIComponent(teamId)}/documents/${encodeURIComponent(documentId)}`); state.teamDocumentId = documentId; state.teamId = teamId;
            $('teamDocumentTitle').textContent = data.document.title; $('teamDocumentRole').textContent = data.role; $('teamDocumentText').value = data.document.current_text || data.document.source_text || '';
            const canEdit = data.role === 'owner' || data.role === 'editor'; $('teamDocumentText').readOnly = !canEdit; show('saveTeamDocument', canEdit); show('teamDocumentPanel'); clear($('teamComments'));
            (data.comments || []).forEach(comment => { const card = node('article', 'team-comment'); card.append(node('strong', '', comment.user?.name || 'Team member'), node('small', '', dateLabel(comment.created_at)), node('p', '', comment.body)); $('teamComments').append(card); });
            if (!data.comments?.length) $('teamComments').append(node('p', 'muted', 'Start the conversation with a thoughtful comment.'));
            $('teamDocumentPanel').scrollIntoView({ behavior: 'smooth', block: 'start' });
        } catch (error) { toast(error.message, true); }
    }

    // UI bindings keep all user/provider text in textContent, text nodes, or form values.
    $('inputText').addEventListener('input', onInput);
    $('inputText').addEventListener('mouseup', captureSelection); $('inputText').addEventListener('keyup', captureSelection);
    $('documentTitle').addEventListener('input', scheduleSave);
    $('outputText').addEventListener('input', () => { state.outputEdited = true; updateOutputMeta(); updateStaleness(); scheduleSave(); });
    settingIds.forEach(id => $(id).addEventListener('change', scheduleSave));
    document.querySelectorAll('[data-screen]').forEach(button => button.addEventListener('click', () => setScreen(button.dataset.screen)));
    document.querySelectorAll('[data-action]').forEach(button => button.addEventListener('click', () => runAction(button.dataset.action)));
    document.querySelectorAll('[data-source-tab]').forEach(button => button.addEventListener('click', () => sourceTab(button.dataset.sourceTab)));
    document.querySelectorAll('[data-output-tab]').forEach(button => button.addEventListener('click', () => outputTab(button.dataset.outputTab)));
    document.querySelectorAll('[data-export]').forEach(button => button.addEventListener('click', () => exportDocument(button.dataset.export)));
    $('newDocument').addEventListener('click', () => newDocument()); $('collectionNew').addEventListener('click', () => newDocument());
    $('clearSelection').addEventListener('click', resetSelection);
    $('advancedButton').addEventListener('click', () => { const open = $('advancedControls').hidden; show('advancedControls', open); $('advancedButton').setAttribute('aria-expanded', String(open)); });
    $('clearVoice').addEventListener('click', () => { $('voiceSample').value = ''; toast('Voice sample cleared from this session.'); });
    $('menuButton').addEventListener('click', () => { $('sidebar').classList.add('open'); show('sidebarScrim'); });
    $('sidebarScrim').addEventListener('click', () => { $('sidebar').classList.remove('open'); show('sidebarScrim', false); });
    $('principlesButton').addEventListener('click', event => { event.preventDefault(); modal('principlesDialog'); });
    $('providerStatus').addEventListener('click', () => setScreen('settings'));
    $('styleButton').addEventListener('click', reviewStyle);
    $('sampleButton').addEventListener('click', () => { $('inputText').value = 'Good writing makes a complicated idea easier to understand. A useful first draft gives your thoughts a place to start, even when the sentences are still rough.\n\nThe next step is to read it with fresh eyes. Keep the details that matter, remove words that get in the way, and give each paragraph one clear point. The aim is to help your reader follow the thought in your own voice.'; onInput(); toast('Sample draft loaded. No authorship label is assumed.'); });
    $('consentCheckbox').addEventListener('change', () => $('confirmConsent').disabled = !$('consentCheckbox').checked);
    $('confirmConsent').addEventListener('click', () => { if (!$('consentCheckbox').checked) return; state.consent = true; $('externalConsent').checked = true; $('consentDialog').close(); const continuation = state.consentContinuation; state.consentContinuation = null; if (continuation) continuation(); else if (state.pendingAction) runAction(state.pendingAction); });
    $('externalConsent').addEventListener('change', () => { state.consent = $('externalConsent').checked; $('consentCheckbox').checked = state.consent; $('confirmConsent').disabled = !state.consent; });
    $('importButton').addEventListener('click', () => modal('importChoiceDialog'));
    $('chooseFileButton').addEventListener('click', () => { $('importChoiceDialog').close(); $('fileInput').click(); });
    $('importUrlButton').addEventListener('click', () => { $('importChoiceDialog').close(); if (!state.authenticated) { localSave(); modal('signinDialog'); return; } updateUrlHost(); modal('urlImportDialog'); });
    $('importUrl').addEventListener('input', updateUrlHost); $('urlImportForm').addEventListener('submit', importUrl);
    $('fileInput').addEventListener('change', () => importFile($('fileInput').files[0]));
    $('confirmImport').addEventListener('click', async () => { try { await saveDocument(true); } catch (error) { toast(`The current draft could not be saved. ${error.message}`, true); return; } state.epoch++; state.documentId = null; state.savedSignature = ''; resetAnalysis(); $('inputText').value = $('importPreview').value; $('documentTitle').value = ($('importDialog').dataset.title || $('importDialog').dataset.filename.replace(/\.[^.]+$/, '')).slice(0, 160); $('importDialog').close(); sourceTab('edit'); onInput(); toast('Imported text added as a new draft.'); });
    $('copyButton').addEventListener('click', () => copy($('outputText').value));
    $('useRevisionButton').addEventListener('click', () => { if (!$('outputText').value) return; const revision = $('outputText').value; const verification = state.result?.verification; const exact = revision === state.resultOutput; $('inputText').value = revision; $('outputText').value = ''; state.result = null; state.resultText = null; state.resultOutput = null; state.originalForDiff = ''; show('comparisonPanel', false); if (verification && exact) renderDetection(verification, revision); else { state.detectionText = state.detectionText ?? null; } updateOutputMeta(); sourceTab('edit'); resetSelection(); onInput(); toast('Revision is now your working draft.'); });
    $('exportButton').addEventListener('click', () => { const open = $('exportDropdown').hidden; show('exportDropdown', open); $('exportButton').setAttribute('aria-expanded', String(open)); });
    document.addEventListener('click', event => { if (!event.target.closest('.export-menu')) { show('exportDropdown', false); $('exportButton').setAttribute('aria-expanded', 'false'); } });
    $('shareButton').addEventListener('click', openShare); $('createShare').addEventListener('click', createShare); $('copyShare').addEventListener('click', () => copy($('shareUrl').value, 'Read-only report link copied.'));
    $('revokeShare').addEventListener('click', async () => { try { const id = $('shareDialog').dataset.documentId; if (!id) throw new Error('Open a saved document’s share controls first.'); await request(`/documents/${encodeURIComponent(id)}/share`, { method: 'DELETE' }); $('shareUrl').value = ''; toast('All shared links for this document were revoked.'); } catch (error) { toast(error.message, true); } });
    $('accountButton')?.addEventListener('click', openAccount); $('settingsAccount')?.addEventListener('click', openAccount); $('loadTokens').addEventListener('click', loadTokens);
    $('tokenForm').addEventListener('submit', async event => { event.preventDefault(); const button = event.target.querySelector('button'); button.disabled = true; try { const result = await post('/account/tokens', { name: $('tokenName').value, scopes: ['detect', 'humanize', 'jobs', 'documents'] }); $('newToken').textContent = `Copy this token now; it is shown once: ${result.plain_text_token || result.token || ''}`; show('newToken'); $('tokenName').value = ''; loadTokens(); } catch (error) { toast(error.message, true); } finally { button.disabled = false; } });
    $('accountDialog').addEventListener('close', () => { $('newToken').textContent = ''; show('newToken', false); $('deletePassword').value = ''; $('confirmDeleteAccount').checked = false; });
    $('deleteAccountForm').addEventListener('submit', async event => { event.preventDefault(); if (!$('confirmDeleteAccount').checked) return; const button = event.target.querySelector('button'); button.disabled = true; try { await request('/account', { method: 'DELETE', body: { password: $('deletePassword').value } }); try { localStorage.removeItem(draftKey); } catch (_) {} location.assign('/'); } catch (error) { toast(error.message, true); } finally { button.disabled = false; } });
    document.querySelector('.logout-form')?.addEventListener('submit', async event => { event.preventDefault(); try { await saveDocument(true); await post('/logout', {}); location.assign('/'); } catch (error) { toast(error.message, true); } });
    $('clearLocalDraft').addEventListener('click', () => { try { localStorage.removeItem(draftKey); } catch (_) {} toast('The saved browser draft was cleared. The current editor and saved account documents remain available.'); });
    $('contentChecksButton').addEventListener('click', () => { contentDescription(); modal('contentChecksDialog'); });
    $('reviewType').addEventListener('change', contentDescription); $('runContentCheck').addEventListener('click', runContentCheck);
    $('batchImportButton').addEventListener('click', () => $('batchFiles').click()); $('batchFiles').addEventListener('change', () => importBatch($('batchFiles').files));
    $('runBatch').addEventListener('click', runBatch); $('refreshBatchJobs').addEventListener('click', loadBatchJobs);
    $('createTeamForm').addEventListener('submit', async event => { event.preventDefault(); if (!state.authenticated) { modal('signinDialog'); return; } const button = event.target.querySelector('button'); button.disabled = true; try { const data = await post('/teams', { name: $('teamName').value }); $('teamName').value = ''; await loadTeams(); if (data.team?.id || data.id) openTeam(data.team?.id || data.id); } catch (error) { toast(error.message, true); } finally { button.disabled = false; } });
    $('joinTeamForm').addEventListener('submit', async event => { event.preventDefault(); if (!state.authenticated) { modal('signinDialog'); return; } let token = $('inviteToken').value.trim(); try { if (token.includes('invitation=')) token = new URL(token, location.origin).searchParams.get('invitation') || token; const data = await post('/teams/join', { token }); $('inviteToken').value = ''; try { sessionStorage.removeItem('humixai.pending-invitation'); } catch (_) {} await loadTeams(); if (data.team?.id || data.team_id) openTeam(data.team?.id || data.team_id); toast('You joined the team.'); } catch (error) { toast(error.message, true); } });
    $('saveTeamDocument').addEventListener('click', async () => { if (!state.teamId || !state.teamDocumentId) return; $('saveTeamDocument').disabled = true; try { await request(`/teams/${encodeURIComponent(state.teamId)}/documents/${encodeURIComponent(state.teamDocumentId)}`, { method: 'PATCH', body: { current_text: $('teamDocumentText').value } }); toast('Team draft saved.'); } catch (error) { toast(error.message, true); } finally { $('saveTeamDocument').disabled = false; } });
    $('teamCommentForm').addEventListener('submit', async event => { event.preventDefault(); if (!state.teamId || !state.teamDocumentId) return; const button = event.target.querySelector('button'); button.disabled = true; try { await post(`/teams/${encodeURIComponent(state.teamId)}/documents/${encodeURIComponent(state.teamDocumentId)}/comments`, { body: $('teamCommentText').value }); $('teamCommentText').value = ''; await openTeamDocument(state.teamId, state.teamDocumentId); } catch (error) { toast(error.message, true); } finally { button.disabled = false; } });
    window.addEventListener('beforeunload', event => { if (state.busy || state.authenticated && state.historyEnabled && JSON.stringify(snapshot()) !== state.savedSignature && $('inputText').value.trim()) { event.preventDefault(); event.returnValue = ''; } });
    try {
        const draft = JSON.parse(localStorage.getItem(draftKey) || 'null');
        if (typeof draft?.source_text === 'string') { $('inputText').value = draft.source_text; $('documentTitle').value = typeof draft.title === 'string' ? draft.title : 'Untitled document'; applySettings(draft.settings); if (typeof draft.current_text === 'string' && draft.current_text !== draft.source_text) { $('outputText').value = draft.current_text; state.originalForDiff = draft.source_text; } }
    } catch (_) {}
    $('inputMeta').textContent = `${words($('inputText').value)} words · ${characters($('inputText').value)} characters`; show('sourceEmptyTools', !$('inputText').value.trim()); updateOutputMeta();
    loadCapabilities(); loadDocuments();
    if (state.authenticated) request('/account').then(data => { state.historyEnabled = data.user?.history_enabled !== false; if (!state.historyEnabled) $('saveState').textContent = 'Session draft · history off'; else if ($('inputText').value.trim() && !new URLSearchParams(location.search).get('document')) scheduleSave(); }).catch(() => {});
    const requestedDocument = new URLSearchParams(location.search).get('document'); if (requestedDocument && state.authenticated) openDocument(requestedDocument);
    const invitation = new URLSearchParams(location.search).get('invitation');
    if (invitation && invitation.length <= 200) { try { sessionStorage.setItem('humixai.pending-invitation', invitation); } catch (_) {} $('inviteToken').value = invitation; setScreen('teams'); toast('Review and join the team with the invitation form.'); }
    const billingReturn = new URLSearchParams(location.search).get('billing');
    if (billingReturn === 'success') notice('Payment submitted; awaiting verified subscription update. Your current allowance comes from the server.');
    else if (billingReturn === 'cancel' || billingReturn === 'cancelled') notice('Checkout was closed. Your current subscription and allowance are shown in your account.');
})();
