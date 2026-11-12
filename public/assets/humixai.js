(() => {
    'use strict';
    const t = (key, values) => window.HumixI18n?.t(key, values) ?? String(key).replace(/:([A-Za-z][A-Za-z0-9_]*)/g, (match, name) => Object.hasOwn(values || {}, name) ? String(values[name]) : match);
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
    const display = value => typeof value === 'string' || typeof value === 'number' ? String(value) : value == null ? t('Not reported') : JSON.stringify(value);
    const words = text => (text.match(/[\p{L}\p{M}\p{N}]+(?:['’\-][\p{L}\p{M}\p{N}]+)*/gu) || []).length;
    const characters = text => Array.from(text).length;
    const dateLabel = value => {
        const date = new Date(value);
        return Number.isNaN(date.getTime()) ? t('Date not reported') : date.toLocaleString(document.documentElement?.lang || 'en-GB', { timeZone: 'Asia/Dhaka', day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
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
        try { json = await response.json(); } catch (_) { throw new Error(response.status === 419 ? t('Your session expired. Refresh the page and try again.') : t('The server could not return a readable response. Your draft is still here.')); }
        if (!response.ok || json.success === false) {
            const details = Object.values(json.errors || {}).flat().filter(item => typeof item === 'string');
            const error = new Error(details[0] || json.message || t('This request could not be completed.'));
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
        authenticated: document.body.dataset.authenticated === 'true', capabilities: null, loggingOut: false,
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
    const stageLabels = { detection: t('Detection'), protection: t('Protect text'), original_scan: t('Original scan'), generation: t('Rewrite'), preservation: t('Check protection'), recheck: t('Recheck revision'), queued: t('Queued'), preparing: t('Preparing'), completed: t('Complete') };
    function settings() {
        return { mode: $('mode').value, language: $('language').value, strength: $('strength').value, length: $('length').value === 'preserve' ? 'similar' : $('length').value,
            protected_terms: $('protectedTerms').value.split(/[,\n]/).map(term => term.trim()).filter(Boolean).slice(0, 40) };
    }
    function applySettings(value = {}) {
        if (!value || typeof value !== 'object' || Array.isArray(value)) value = {};
        for (const key of ['mode', 'language', 'strength', 'length']) {
            const selected = key === 'length' && value[key] === 'preserve' ? 'similar' : value[key];
            if (typeof selected === 'string' && Array.from($(key).options).some(option => option.value === selected)) $(key).value = selected;
        }
        $('protectedTerms').value = Array.isArray(value.protected_terms) ? value.protected_terms.filter(term => typeof term === 'string').slice(0, 40).join(', ') : '';
    }
    function snapshot() {
        return { title: $('documentTitle').value.trim() || t('Untitled document'), source_text: $('inputText').value, current_text: $('outputText').value || $('inputText').value, settings: settings() };
    }
    function localSave() {
        if (state.authenticated && state.documentId) return;
        try {
            const data = snapshot();
            // Voice samples and processing consent are intentionally never stored in drafts.
            data.settings = settings();
            localStorage.setItem(draftKey, JSON.stringify(data));
        } catch (_) { $('saveState').textContent = t('Browser storage unavailable'); }
    }
    function notice(message, error = false) {
        $('persistentNotice').textContent = message;
        $('persistentNotice').classList.toggle('error', error);
        show('persistentNotice', Boolean(message));
    }
    function onInput() {
        $('inputMeta').textContent = t(":value1 words · :value2 characters", { value1: (words($('inputText').value).toLocaleString()), value2: (characters($('inputText').value).toLocaleString()) });
        show('sourceEmptyTools', !$('inputText').value.trim());
        if (state.selection) resetSelection();
        updateStaleness();
        scheduleSave();
    }
    function updateOutputMeta() {
        const text = $('outputText').value;
        $('outputMeta').textContent = text ? t(":value1 words · :value2 characters", { value1: (words(text).toLocaleString()), value2: (characters(text).toLocaleString()) }) : t('Your voice, kept intact.');
        $('copyButton').disabled = !text;
        $('useRevisionButton').disabled = !text;
        const detectionView = document.querySelector('[data-output-tab="detection"]')?.classList.contains('active');
        show('detectionPreview', Boolean(detectionView));
        show('outputEmpty', !text && !detectionView);
        const changes = document.querySelector('[data-output-tab="changes"]').classList.contains('active');
        show('outputText', Boolean(text) && !changes && !detectionView);
        show('diffArea', Boolean(text) && changes && !detectionView);
    }
    function updateStaleness() {
        const stale = state.detectionText !== null && $('inputText').value !== state.detectionText;
        show('staleNote', stale); show('detectionPreviewStale', stale);
        if (state.detection) $('detectionStatus').textContent = stale ? t('Draft changed') : (state.detection.local_patterns ? t('Local writing patterns') : statusLabel(state.detection.status));
        if (state.result) renderComparison(state.result);
        const protectionStale = state.resultOutput !== null && $('outputText').value !== state.resultOutput;
        let staleProtection = $('staleProtection');
        if (!staleProtection && state.result) { staleProtection = node('p', 'stale-note', t('Revision edited after these checks. Review it again before accepting.')); staleProtection.id = 'staleProtection'; $('preservationContent').append(staleProtection); }
        if (staleProtection) staleProtection.hidden = !protectionStale;
        if (state.contentCheck) renderContentCheck(state.contentCheck, false);
    }
    function scheduleSave() {
        clearTimeout(state.saveTimer);
        if (!state.authenticated) { localSave(); return; }
        if (!state.historyEnabled) { $('saveState').textContent = t('Session draft · history off'); return; }
        if (!$('inputText').value.trim() && !state.documentId) { $('saveState').textContent = t('New document'); return; }
        $('saveState').textContent = t('Unsaved changes');
        state.saveTimer = setTimeout(() => saveDocument().catch(() => {}), 1000);
    }
    async function saveDocument(required = false) {
        clearTimeout(state.saveTimer);
        if (!state.authenticated) { localSave(); return null; }
        if (!state.historyEnabled) { $('saveState').textContent = t('Session draft · history off'); return null; }
        if (state.savePromise) { await state.savePromise; return saveDocument(required); }
        const data = snapshot();
        const signature = JSON.stringify(data);
        if (signature === state.savedSignature || !data.source_text.trim() && !state.documentId) return state.documentId;
        const epoch = state.epoch;
        const id = state.documentId;
        $('saveState').textContent = t('Saving…');
        state.savePromise = (async () => {
            try {
                const result = await request(id ? `/documents/${encodeURIComponent(id)}` : '/documents', { method: id ? 'PATCH' : 'POST', body: data });
                const saved = result.document || result;
                if (state.epoch === epoch) {
                    state.documentId = saved.id || saved.uuid || id;
                    state.savedSignature = signature;
                    $('saveState').textContent = t('All changes saved');
                    try { localStorage.removeItem(draftKey); } catch (_) {}
                    const index = state.documents.findIndex(doc => doc.id === state.documentId);
                    if (index >= 0) state.documents[index] = saved; else state.documents.unshift(saved);
                    renderDocumentList();
                }
                return saved;
            } catch (error) {
                if (state.epoch === epoch) $('saveState').textContent = t('Save failed — retry by editing');
                if (required) throw error;
                toast(t("Your draft could not be saved. :value1", { value1: (error.message) }), true);
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
        if (link) { const anchor = node('a', 'button button-secondary', t('Sign in to your workspace ↗')); anchor.href = '/login'; card.append(anchor); }
        container.append(card);
    }
    function renderDocumentList() {
        $('documentCount').textContent = state.documents.length;
        clear($('recentDocuments'));
        state.documents.slice(0, 5).forEach(doc => { const button = node('button', 'recent-document', doc.title || t('Untitled document')); button.addEventListener('click', () => openDocument(doc.id)); $('recentDocuments').append(button); });
        if (!state.documents.length) $('recentDocuments').append(node('p', 'sidebar-hint', state.authenticated ? t('Your saved drafts will appear here.') : t('Sign in to keep your drafts together.')));
        if (!state.authenticated) { emptyCollection($('documentCollection'), t('A home for your drafts.'), t('Sign in to save documents and return to your writing whenever you like.'), true); return; }
        if (!state.documents.length) { emptyCollection($('documentCollection'), t('Room for your next idea.'), t('Your first saved document will appear here. Start with a draft in the writing studio.')); return; }
        clear($('documentCollection'));
        [...state.documents].sort((a, b) => new Date(b.updated_at) - new Date(a.updated_at)).forEach(doc => {
            const card = node('article', 'document-tile');
            const button = node('button', 'document-open');
            const preview = doc.current_text || doc.source_text;
            button.append(node('h2', '', doc.title || t('Untitled document')), node('p', '', preview ? preview.slice(0, 160) + (preview.length > 160 ? '…' : '') : t(":value1 saved revisions. Open this document to continue writing.", { value1: (doc.revisions_count || 0) })));
            button.addEventListener('click', () => openDocument(doc.id));
            const footer = node('footer');
            const remove = node('button', 'text-button', t('Delete'));
            remove.addEventListener('click', async () => {
                if (!window.confirm(t("Delete “:value1” and its saved revisions?", { value1: (doc.title || 'Untitled document') }))) return;
                try { await request(`/documents/${encodeURIComponent(doc.id)}`, { method: 'DELETE' }); if (state.documentId === doc.id) await newDocument(false); await loadDocuments(); toast(t('Document deleted.')); } catch (error) { toast(error.message, true); }
            });
            footer.append(node('span', '', dateLabel(doc.updated_at)), remove);
            card.append(button, footer); $('documentCollection').append(card);
        });
    }
    function resetAnalysis() {
        state.detection = null; state.detectionText = null; state.result = null; state.resultText = null; state.resultOutput = null; state.originalForDiff = ''; state.acceptedParagraphs.clear(); state.outputEdited = false;
        $('outputText').value = ''; $('detectionStatus').textContent = t('Not scanned');
        clear($('detectionPreview')); show('detectionPreview', false); show('outputProgress', false);
        clear($('detectionContent'));
        const placeholder = node('div', 'score-placeholder', '—'); placeholder.append(node('span', '', t('No result yet')));
        $('detectionContent').append(placeholder, node('p', 'insight-description', t('A signal to explore, with the source and score meaning kept visible.')));
        clear($('qualityContent')); $('qualityContent').append(node('p', 'insight-description empty-insight', t('Review local writing signals for this draft.')));
        clear($('preservationContent')); $('preservationContent').append(node('p', 'insight-description empty-insight', t('After a rewrite, protected text checks will appear here.')));
        show('staleNote', false); show('comparisonPanel', false); show('jobPanel', false); show('evidenceDot', false); notice(''); resetSelection(); sourceTab('edit'); outputTab('revision'); updateOutputMeta();
    }
    async function newDocument(save = true) {
        if (state.busy || state.documentLoading) { toast(t('Let the current document operation finish before switching.')); return; }
        if (save) { try { await saveDocument(true); } catch (error) { toast(t("Save the current draft before starting another. :value1", { value1: (error.message) }), true); return; } }
        clearTimeout(state.saveTimer); state.epoch++; state.documentId = null; state.savedSignature = '';
        $('documentTitle').value = t('Untitled document'); $('inputText').value = ''; $('saveState').textContent = state.authenticated ? t('New document') : t('Local draft');
        resetAnalysis(); onInput(); setScreen('studio'); $('inputText').focus();
    }
    async function openDocument(id) {
        if (state.busy || state.documentLoading) { toast(t('Let the current document operation finish before switching.')); return; }
        state.documentLoading = true;
        try {
            await saveDocument(true);
            const response = await request(`/documents/${encodeURIComponent(id)}`);
            const doc = response.document || response;
            clearTimeout(state.saveTimer); state.epoch++; state.documentId = doc.id; resetAnalysis();
            $('documentTitle').value = doc.title || t('Untitled document'); $('inputText').value = doc.source_text || doc.current_text || ''; applySettings(doc.settings || {});
            const revisions = Array.isArray(doc.revisions) ? doc.revisions : [];
            const matching = revisions.filter(revision => revision.input_text === $('inputText').value && revision.result && typeof revision.result === 'object');
            const rewrite = matching.find(revision => typeof revision.result.humanized_text === 'string');
            const analysis = matching.find(revision => revision.result.status && revision.result.overall_score !== undefined);
            const verifiedRevision = revisions.find(revision => revision.result?.humanized_text === $('inputText').value && revision.result?.verification);
            if (rewrite) renderHumanized(rewrite.result, $('inputText').value);
            if (analysis) renderDetection(analysis.result, $('inputText').value);
            else if (!rewrite?.result?.original_detection && verifiedRevision) renderDetection(verifiedRevision.result.verification, $('inputText').value);
            $('outputText').value = doc.current_text && doc.current_text !== $('inputText').value ? doc.current_text : (rewrite?.result?.humanized_text || '');
            state.originalForDiff = $('inputText').value;
            updateOutputMeta(); state.savedSignature = JSON.stringify(snapshot()); $('saveState').textContent = t('All changes saved');
            $('inputMeta').textContent = t(":value1 words · :value2 characters", { value1: (words($('inputText').value)), value2: (characters($('inputText').value)) }); show('sourceEmptyTools', !$('inputText').value.trim()); updateStaleness(); setScreen('studio');
        } catch (error) { toast(error.message, true); }
        finally { state.documentLoading = false; }
    }
    function setScreen(name) {
        state.currentScreen = name;
        document.querySelectorAll('.screen').forEach(screen => screen.hidden = screen.id !== `screen-${name}`);
        document.querySelectorAll('[data-screen]').forEach(button => button.classList.toggle('active', button.dataset.screen === name));
        $('screenName').textContent = { studio: t('Writing studio'), documents: t('My documents'), history: t('Revision history'), settings: t('Preferences'), batch: t('Batch review'), teams: t('Team workspace') }[name];
        $('sidebar').classList.remove('open'); show('sidebarScrim', false);
        if (name === 'documents') loadDocuments();
        if (name === 'history') loadHistory();
        if (name === 'batch') loadBatchJobs();
        if (name === 'teams') loadTeams();
    }
    async function loadHistory() {
        if (!state.authenticated) { emptyCollection($('revisionList'), t('Keep your document journey.'), t('Sign in to save versions of your drafts and review completed analyses.'), true); return; }
        if (!state.documentId) { emptyCollection($('revisionList'), t('A fresh start.'), t('Write and save a document, then run an analysis or improvement to create its history.')); return; }
        try {
            await saveDocument(true);
            const data = await request(`/documents/${encodeURIComponent(state.documentId)}/revisions`);
            const revisions = data.revisions || (Array.isArray(data) ? data : data.data || []);
            $('historySubtitle').textContent = t("Saved versions of “:value1”.", { value1: ($('documentTitle').value) });
            if (!revisions.length) { emptyCollection($('revisionList'), t('The story is just starting.'), t('Completed analyses and saved changes appear here when history is enabled.')); return; }
            clear($('revisionList'));
            revisions.forEach((revision, index) => {
                const card = node('article', 'revision-card'); const header = node('div', 'section-row');
                const title = node('div'); title.append(node('h3', '', `${display(revision.action || 'Saved revision').replaceAll('_', ' ')} · ${dateLabel(revision.created_at)}`), node('small', '', revision.text_hash ? t("Document fingerprint :value1…", { value1: (revision.text_hash.slice(0, 14)) }) : t("Saved version :value1", { value1: (revisions.length - index) })));
                const restore = node('button', 'button button-secondary', t('Restore to studio'));
                restore.addEventListener('click', () => { if (state.busy) return; $('inputText').value = revision.input_text || revision.source_text || revision.text || ''; $('outputText').value = revision.output_text || ''; resetAnalysis(); $('inputText').value = revision.input_text || revision.source_text || revision.text || ''; if (revision.result?.humanized_text) renderHumanized(revision.result, $('inputText').value); else if (revision.result?.status) renderDetection(revision.result, $('inputText').value); state.originalForDiff = $('inputText').value; if (revision.output_text) $('outputText').value = revision.output_text; updateOutputMeta(); onInput(); setScreen('studio'); toast(t('Restored as the current draft. Earlier revisions remain in history.')); });
                header.append(title, restore); card.append(header, node('p', '', (revision.output_text || revision.current_text || revision.input_text || revision.source_text || revision.text || '').slice(0, 1200))); $('revisionList').append(card);
            });
        } catch (error) { emptyCollection($('revisionList'), t('History could not be loaded.'), error.message); }
    }
    function resetSelection() { state.selection = null; state.selectionStart = null; show('selectionBanner', false); }
    function captureSelection() {
        const input = $('inputText');
        if (input.selectionEnd <= input.selectionStart) return;
        const value = input.value.slice(input.selectionStart, input.selectionEnd);
        if (value.trim()) { state.selection = value; state.selectionStart = characters(input.value.slice(0, input.selectionStart)); $('selectionLabel').textContent = t("Rewriting selected passage · :value1 words", { value1: (words(value)) }); show('selectionBanner'); }
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
    function statusLabel(status) { return ({ completed: t('Scan complete'), unavailable: t('Not available'), insufficient_text: t('More text needed'), unsupported_language: t('Language limited'), error: t('Scan unavailable') })[status] || t('No AI score'); }
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
        if (data.local_patterns) {
            $('detectionContent').append(node('p','insight-description',data.message));
        } else if (valid) {
            const scoreNode = node('div', 'score-value', `${Math.round(score * 10) / 10}%`);
            scoreNode.append(node('span', '', display(data.classification || data.score_label || t('AI signal'))));
            $('detectionContent').append(scoreNode, node('div', 'score-type', data.score_label || t('Provider score')));
            const type = data.score_type || t('Provider definition');
            $('detectionContent').append(node('p', 'score-detail', `${display(type).replaceAll('_', ' ')}. ${/probability|confidence/i.test(type) ? 'This is classifier confidence, not the percentage of words written by AI.' : 'Interpret this result using the provider’s definition; it does not prove authorship.'}`));
        } else {
            const placeholder = node('div', 'score-placeholder', '—'); placeholder.append(node('span', '', statusLabel(data.status)));
            $('detectionContent').append(placeholder, node('p', 'insight-description', data.message || t('A connected detector has not returned an authorship score for this text.')));
        }
        if (data.local_patterns) {
            const p = data.local_patterns;
            $('detectionStatus').textContent = t('Local writing patterns');
            $('detectionContent').append(node('h3', '', t('Local writing patterns')),node('p','score-detail',data.language_notice),node('p','score-detail',p.definition));
            if (numeric(p.sentence_regularity_index) !== null) $('detectionContent').append(node('div','score-value',`${p.sentence_regularity_index} / 100`));
        }
        const provider = data.provider && data.provider !== 'local' ? data.provider : null;
        if (data.language_notice) $('detectionContent').append(node('p', 'muted', t(data.language_notice)));
        addMeta($('detectionContent'), [[t('Provider'), provider], [t('Model'), data.model_version], [t('Language'), data.language], [t('Coverage'), data.analyzed_characters != null && data.character_count != null ? t(":value1 / :value2 characters", { value1: (data.analyzed_characters), value2: (data.character_count) }) : null], [t('Scanned (Dhaka)'), data.scanned_at ? dateLabel(data.scanned_at) : null]]);
        if (data.class_probabilities) addMeta($('detectionContent'), Object.entries(data.class_probabilities).filter(([, value]) => numeric(value) !== null).map(([name, value]) => [`${name} probability`, `${value}%`]));
        if (data.confidence != null) addMeta($('detectionContent'), [[t('Reported confidence'), data.confidence]]);
        show('evidenceDot', Array.isArray(data.sentences) && data.sentences.some(sentence => sentence.highlight));
        if (data.quality) renderQuality(data.quality);
        renderDetectionPreview(data); renderEvidence(); updateStaleness();
    }
    function renderDetectionPreview(data) {
        const panel = $('detectionPreview'); if (!panel) return; clear(panel);
        panel.append(node('h3','',t('Detection result')), node('p','',data.message || ''));
        if (data.local_patterns) {
            panel.append(node('h4','',t('Local writing patterns')));
            const value = numeric(data.local_patterns.sentence_regularity_index);
            if (value !== null) panel.append(node('div','score-value',`${value} / 100`));
            panel.append(node('p','',data.local_patterns.definition || ''));
        } else if (data.status === 'completed' && numeric(data.overall_score) !== null) {
            panel.append(node('div','score-value',`${data.overall_score}%`),node('p','',data.score_label || ''));
        }
        if (data.language_notice) panel.append(node('p','',data.language_notice));
        panel.append(node('p','',t('Detection does not rewrite your text. Choose Humanize to create a revision.')));
        const stale = node('p','stale-note',t('Draft changed')); stale.id='detectionPreviewStale'; stale.hidden=true; panel.append(stale);
    }
    function renderEvidence() {
        const container = $('evidenceArea'); clear(container);
        if (!state.detection || state.detectionText === null) { container.append(node('p', 'empty-evidence', t('Run detection to review sentence evidence here.'))); return; }
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
            span.addEventListener('click', () => { note.replaceChildren(node('strong', '', sentence.score == null ? t('Sentence evidence') : t("Sentence signal: :value1%", { value1: (sentence.score) })), node('p', '', sentence.reason || t('No sentence-level explanation was supplied by the provider.'))); note.hidden = false; });
            container.append(span); cursor = end;
        }
        container.append(document.createTextNode(codepoints.slice(cursor).join('')));
        if (!sentences.length) container.append(node('div', 'evidence-explanation', t('The provider did not return sentence highlights for this scan.')));
        else container.append(node('div', 'evidence-explanation', t('Highlights are review signals. Select a sentence to see the supplied explanation.')));
        container.append(note);
    }
    function renderQuality(quality, label = t('Original draft')) {
        clear($('qualityContent'));
        const grid = node('div', 'signal-grid');
        for (const [name, value] of [[t('Words'), quality.word_count], [t('Sentences'), quality.sentence_count], [t('Words / sentence'), quality.average_sentence_words], [t('Reading time'), quality.reading_time_seconds != null ? `${quality.reading_time_seconds}s` : null]]) {
            if (value === undefined || value === null) continue;
            const item = node('div', '', name); item.prepend(node('strong', '', value)); grid.append(item);
        }
        $('qualityContent').append(grid);
        const facts = [];
        if (quality.long_sentence_count != null) facts.push(t(":value1 sentences over 30 words", { value1: (quality.long_sentence_count) }));
        if (quality.sentence_length_standard_deviation != null) facts.push(t("Sentence length variation: :value1 words", { value1: (quality.sentence_length_standard_deviation) }));
        $('qualityContent').append(node('p', 'quality-note', `${label}${facts.length ? ' · ' + facts.join(' · ') : ''}`));
        if (Array.isArray(quality.repeated_words) && quality.repeated_words.length) $('qualityContent').append(node('p', 'score-detail', t("Repeated terms: :value1", { value1: (quality.repeated_words.slice(0, 4).map(item => `${item.word} (${item.count})`).join(', ')) })));
    }
    function renderPreservation(data) {
        clear($('preservationContent'));
        if (!data) { $('preservationContent').append(node('p', 'insight-description', t('No preservation check was returned. Review the revision manually.'))); return; }
        const passed = data.passed === true;
        $('preservationContent').append(node('p', passed ? 'protection-good' : 'protection-review', passed ? t('✓ Protected text checks passed.') : t('Review the protected text findings.')));
        if (data.protected_count != null) $('preservationContent').append(node('p', 'score-detail', t(":value1 protected passages checked.", { value1: (data.protected_count) })));
        const violations = data.violations || [];
        if (violations.length) { const list = node('ul', 'finding-list'); violations.forEach(item => list.append(node('li', '', typeof item === 'string' ? item : item.message || item.type || display(item)))); $('preservationContent').append(list); }
        $('preservationContent').append(node('p', 'score-detail', t('Review facts, names, nuance and any changed claims before accepting.')));
    }
    function renderHumanized(result, submittedText) {
        state.result = result; state.resultText = result.original_text || submittedText; state.resultOutput = result.humanized_text || ''; state.originalForDiff = state.resultText; state.outputEdited = false; state.acceptedParagraphs.clear();
        $('outputText').value = result.humanized_text || '';
            if (result.original_detection) renderDetection(result.original_detection, state.resultText);
        if (result.quality_after) renderQuality(result.quality_after, t('Revised draft'));
        renderPreservation(result.preservation); renderComparison(result); updateOutputMeta();
        const notes = Array.isArray(result.notes) ? result.notes.filter(item => /unavailable|experimental|cannot be compared|first exact occurrence/i.test(item)) : [];
        if (notes.length) notice(notes.join(' '));
        if ($('inputText').value !== state.resultText) notice(t('Your original changed while this job ran. The revision belongs to the submitted draft; compare it carefully.'));
        outputTab('revision');
    }
    function renderComparison(result) {
        show('comparisonPanel'); clear($('comparisonContent'));
        const exact = $('inputText').value === state.resultText && $('outputText').value === state.resultOutput;
        const before = numeric(result.original_score), after = numeric(result.humanized_score), change = numeric(result.score_change);
        if (result.comparable === true && exact && before !== null && after !== null && change !== null) {
            const row = node('div', 'comparison-scores');
            for (const [name, value] of [[t('Original'), `${before}%`], [t('Revision'), `${after}%`], [t('Change'), `${change > 0 ? '+' : ''}${change} pts`]]) { const item = node('div', '', name); item.append(node('strong', '', value)); row.append(item); }
            $('comparisonContent').append(row, node('p', 'score-detail', t('Same provider, model and score definition. A lower result does not establish human authorship.')));
        } else {
            $('comparisonContent').append(node('p', 'comparison-explanation', !exact ? t('The draft has changed since these results. Run Analyze & improve again to compare the exact versions.') : t('No comparable before-and-after scores are available. Your revision and protection checks remain available for review.')));
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
        const legend = node('div', 'diff-legend'); legend.append(node('span', 'diff-insert', t('Added')), node('span', 'diff-delete', t('Removed'))); $('diffArea').append(legend);
        const a = before.split(/\n\s*\n/), b = after.split(/\n\s*\n/);
        const paragraphReview = a.length === b.length && a.length <= 100;
        if (!paragraphReview) { const block = node('div', 'diff-paragraph'); appendDiff(block, before, after); $('diffArea').append(block, node('p', 'score-detail', t('The paragraph structure changed. Review or edit the complete revision.'))); return; }
        b.forEach((paragraph, index) => {
            const block = node('div', 'diff-paragraph'); appendDiff(block, a[index], paragraph);
            if (a[index] !== paragraph) {
                const actions = node('div', 'paragraph-actions');
                if (state.acceptedParagraphs.has(index)) actions.append(node('span', 'score-detail', t('✓ Paragraph reviewed')));
                else {
                    const accept = node('button', 'text-button', t('✓ Keep this change')); const reject = node('button', 'text-button', t('↶ Restore paragraph'));
                    accept.addEventListener('click', () => { state.acceptedParagraphs.add(index); renderDiff(); });
                    reject.addEventListener('click', () => { const paragraphs = $('outputText').value.split(/\n\s*\n/); paragraphs[index] = a[index]; $('outputText').value = paragraphs.join('\n\n'); state.outputEdited = true; state.acceptedParagraphs.delete(index); updateOutputMeta(); updateStaleness(); renderDiff(); scheduleSave(); toast(t('Original paragraph restored. Verification applies only to the unedited revision.')); });
                    actions.append(accept, reject);
                }
                block.append(actions);
            }
            $('diffArea').append(block);
        });
    }
    function setBusy(value) {
        state.busy = value;
        if (!value) show('outputProgress', false);
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
        if ($('outputProgress')) {
            $('outputProgress').textContent = stageLabels[stage] || t('Queued');
            show('outputProgress', !terminal && state.pendingAction !== 'detect');
        }
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
    async function runLocalPatterns() {
        if (state.busy || state.documentLoading) return;
        const text = $('inputText').value;
        if (!text.trim()) return;
        setBusy(true); notice('');
        try { const result = await post('/ai-tools/local-patterns', {text, language:$('language').value}); renderDetection(result,text); sourceTab('evidence'); outputTab('detection'); show('jobPanel',false); }
        catch(error) { notice(error.message,true); }
        finally { setBusy(false); }
    }
    async function runAction(action) {
        if (state.busy || state.documentLoading) { if (state.documentLoading) toast(t('Wait for the saved document to open before starting a job.')); return; }
        const detectionMode = $('detectionMode')?.value || 'gptzero';
        if (action === 'detect' && (detectionMode === 'local' || (detectionMode === 'auto' && state.capabilities && !state.capabilities.detector?.configured))) return runLocalPatterns();
        const text = $('inputText').value;
        if (!text.trim()) { $('inputText').focus(); toast(t('Start with a draft before running an AI action.')); return; }
        const limit = state.capabilities?.limits?.max_characters;
        if (limit && characters(text) > limit) { toast(t("This draft exceeds the :value1 character limit. Choose a shorter draft.", { value1: (Number(limit).toLocaleString()) }), true); return; }
        if (!state.authenticated) { localSave(); modal('signinDialog'); return; }
        state.pendingAction = action;
        state.consentContinuation = null;
        if (!state.consent) { modal('consentDialog'); return; }
        setBusy(true); notice(''); state.lastStages = []; const epoch = state.epoch;
        try {
            await saveDocument(true);
            const preferences = settings();
            const payload = { text, ...preferences, language: preferences.language === 'mixed' ? 'auto' : preferences.language, length: preferences.length === 'preserve' ? 'similar' : preferences.length, voice_sample: $('voiceSample').value.slice(0, 3000) || undefined, voice_preferences: $('voicePreferences')?.value.slice(0, 1000) || undefined, external_processing_consent: true, document_id: state.historyEnabled ? state.documentId || undefined : undefined };
            if (action !== 'detect' && state.selection && text.includes(state.selection)) { payload.selection = state.selection; payload.selection_start = state.selectionStart; }
            const job = await post('/ai-tools/jobs', { action, ...payload });
            if (epoch !== state.epoch) return;
            state.activeJob = job.id; renderJob(job);
            clear($('jobResultInfo'));
            let current = job; let attempts = 0;
            while (!['completed', 'partial', 'failed', 'error', 'cancelled'].includes(current.status)) {
                if (++attempts > 300) { notice(t('This job is taking longer than expected. It may still be processing; open revision history later to review a completed result.')); return; }
                await new Promise(resolve => setTimeout(resolve, 1100));
                current = await request(`/ai-tools/jobs/${encodeURIComponent(job.id)}`);
                if (epoch !== state.epoch) return;
                renderJob(current);
            }
            const result = current.result?.data || current.result;
            if (epoch !== state.epoch) return;
            const failed = ['failed', 'error', 'cancelled'].includes(current.status);
            if (!failed && result && typeof result === 'object') {
                if (action === 'detect' && typeof result.status === 'string') { renderDetection(result, text); sourceTab('evidence'); outputTab('detection'); }
                else if (['completed', 'partial'].includes(current.status) && typeof result.humanized_text === 'string' && result.humanized_text.trim()) { renderHumanized(result, text); scheduleSave(); }
                if (result.stages) renderJob({ ...current, stages: result.stages });
            }
            renderJobDetails(result, text, failed);
            if (failed) {
                const details = current.error || current.message || result?.message || t('The job could not finish. Your original draft is unchanged.');
                notice(typeof details === 'string' ? details : details.message || t('The job could not finish.'), true);
                const originalScan = result?.original_detection || current.error?.original_detection;
                if (originalScan) renderDetection(originalScan, text);
            } else {
                toast(current.status === 'partial' ? t('Your draft is ready. Review the checks that could not finish.') : action === 'detect' ? t('Scan finished. Review the result and its coverage.') : t('Your revision is ready for a thoughtful review.'));
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
            container.append(node('p', 'quality-note', t('The attempted rewrite did not replace your current revision.')));
            if (result.original_detection?.status === 'completed') container.append(node('p', 'quality-note', t('The original scan completed and is available below. Its completed operation counts toward your allowance.')));
            const completed = Array.isArray(result.completed_operations) ? result.completed_operations : [];
            if (completed.length) container.append(node('p', 'score-detail', t("Completed operations: :value1.", { value1: (completed.map(operation => stageLabels[operation] || display(operation)).join(', ')) })));
            if (Array.isArray(result.violations) && result.violations.length) { const list = node('ul', 'finding-list'); result.violations.forEach(finding => list.append(node('li', '', typeof finding === 'string' ? finding : finding.message || display(finding)))); container.append(node('strong', 'score-detail', t('Findings from the failed attempt')), list); }
        }
        if (result.usage) {
            container.append(node('p', 'score-detail', t("Daily allowance: :value1 words used · :value2 reserved · :value3 remaining.", { value1: (result.usage.used_words ?? result.usage.used ?? 0), value2: (result.usage.reserved_words ?? result.usage.reserved ?? 0), value3: (result.usage.remaining ?? 'Not reported') })));
        }
        container.append(node('p', 'score-detail', t('Allowance counts input words for each completed scan or rewrite. Analyze & improve can use three completed operations; its temporary reservation also covers output-length variation. Unused reservations are released.')));
    }
    async function reviewStyle() {
        const text = $('inputText').value;
        if (!text.trim()) { toast(t('Add a draft to review its writing style.')); return; }
        $('styleButton').disabled = true;
        try { const data = await post('/ai-tools/style', { text, language: $('language').value === 'mixed' ? 'auto' : $('language').value }); renderQuality(data.quality || data); toast(t('Local writing signals updated. No text was sent to an AI provider.')); }
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
            $('providerStatusText').textContent = connected ? t('AI services configured') : t('Local style review available');
            clear($('connectionDetails'));
            $('connectionDetails').append(node('div', 'notice', data.external_processing_notice || t("AI detection: :value1. Rewriting: :value2.", { value1: (detector.configured ? detector.provider || 'Connected' : 'Not configured'), value2: (rewriter.configured ? rewriter.provider || 'Connected' : 'Not configured') })));
            if (data.external_processing_notice) $('consentDescription').textContent = data.external_processing_notice;
            clear($('capabilityList'));
            const imports = data.imports || {};
            const rows = [[t('AI detection'), detector.configured], [t('AI rewriting'), rewriter.configured], [t('Local writing review'), true], [t('TXT / DOCX import'), imports.formats?.includes('docx') || imports.docx], [t('PDF extraction'), imports.pdf_available || imports.pdf], [t('Public URL import'), imports.url === true], [t('Web similarity'), data.plagiarism?.configured === true], [t('Documents & history'), state.authenticated]];
            rows.forEach(([name, available]) => { const row = node('div', 'capability-row'); row.append(node('span', '', name), node('strong', available ? '' : 'unavailable', available ? t('Available') : t('Not available'))); $('capabilityList').append(row); });
            if (data.limits?.max_characters) $('capabilityList').append(node('p', 'muted', t("Maximum draft: :value1 characters.", { value1: (Number(data.limits.max_characters).toLocaleString()) })));
            if (Array.isArray(imports.formats)) $('fileInput').accept = imports.formats.map(format => `.${format}`).join(',');
            $('importUrlButton').disabled = imports.url !== true;
            $('urlImportAvailability').textContent = imports.url === true ? t('Public URL import is available after sign-in. No JavaScript rendering or signed-in pages.') : t('Public URL import is not available on this server.');
            const similarityAvailable = data.plagiarism?.configured === true;
            $('plagiarismOption').disabled = !similarityAvailable;
            $('plagiarismOption').textContent = similarityAvailable ? t('Plagiarism / web similarity') : t('Web similarity · not configured');
            restoreCheckoutDraft(data.user?.id);
            if (data.language_notice) $('capabilityList').append(node('p', 'muted', data.language_notice));
        } catch (_) {
            $('providerStatusText').textContent = t('Connection status unavailable');
            clear($('capabilityList')); $('capabilityList').append(node('p', 'muted', t('Connection details could not be loaded. Reload the page to try again.')));
        }
    }
    async function importFile(file) {
        if (!file) return;
        const max = state.capabilities?.limits?.max_file_kb;
        if (max && file.size > max * 1024) { toast(t("Choose a file smaller than :value1 KB.", { value1: (max) }), true); return; }
        $('importButton').disabled = true; toast(t('Extracting a preview of your document…'));
        try {
            let result;
            if (/\.txt$/i.test(file.name)) result = { text: await file.text(), filename: file.name, format: 'txt', warnings: [] };
            else { const form = new FormData(); form.append('file', file); form.append('language', $('language').value); result = await post('/imports', form); }
            $('importFilename').textContent = t(":value1 · :value2 words · Review extraction before using it.", { value1: (result.filename || file.name), value2: (words(result.text || '')) });
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
            $('urlHostPreview').textContent = t("The server will fetch public page text from :value1. You'll review the extracted text next.", { value1: (url.hostname) });
        } catch (_) { $('urlHostPreview').textContent = t('Enter a public HTTP or HTTPS address without embedded sign-in credentials.'); }
    }
    async function importUrl(event) {
        event.preventDefault();
        if (!state.authenticated) { $('urlImportDialog').close(); modal('signinDialog'); return; }
        if (state.capabilities?.imports?.url !== true) { toast(t('Public URL import is not configured on this server.'), true); return; }
        $('fetchUrlButton').disabled = true; show('urlImportError', false);
        try {
            const result = await post('/imports/url', { url: $('importUrl').value.trim() });
            let host = result.source_url;
            try { host = new URL(result.source_url).hostname; } catch (_) {}
            $('importFilename').textContent = t(":value1 · :value2 · :value3 words", { value1: (result.title || 'Public page'), value2: (host || 'Source host not reported'), value3: (words(result.text || '')) });
            $('importPreview').value = result.text || '';
            $('importWarnings').textContent = (Array.isArray(result.warnings) ? result.warnings : []).map(display).join(' '); show('importWarnings', Boolean(result.warnings?.length));
            $('importDialog').dataset.filename = result.title || host || t('Imported page'); $('importDialog').dataset.title = result.title || host || t('Imported page');
            $('urlImportDialog').close(); modal('importDialog');
        } catch (error) { $('urlImportError').textContent = error.message; show('urlImportError'); }
        finally { $('fetchUrlButton').disabled = false; }
    }
    function download(name, content, type) {
        const url = URL.createObjectURL(new Blob([content], { type })); const a = node('a'); a.href = url; a.download = name; document.body.append(a); a.click(); a.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000);
    }
    function reportData() { return { title: $('documentTitle').value, generated_at: new Date().toISOString(), original: $('inputText').value, revision: $('outputText').value || null, detection: state.detection, detection_stale: state.detectionText !== null && state.detectionText !== $('inputText').value, result: state.result, revision_edited_after_verification: state.resultOutput !== null && state.resultOutput !== $('outputText').value, note: t('AI detection is a review signal, not proof of authorship. Writing quality and protected-text checks are separate.') }; }
    async function exportDocument(format) {
        show('exportDropdown', false); $('exportButton').setAttribute('aria-expanded', 'false');
        const title = ($('documentTitle').value || t('HumixAi document')).replace(/[<>:"/\\|?*\u0000-\u001F]/g, '-');
        if (format === 'txt') { download(`${title}.txt`, $('outputText').value || $('inputText').value, 'text/plain;charset=utf-8'); return; }
        if (format === 'json') { download(`${title}-report.json`, JSON.stringify(reportData(), null, 2), 'application/json;charset=utf-8'); return; }
        if (format === 'print') { printReport(); return; }
        if (!state.authenticated) { modal('signinDialog'); return; }
        try {
            if (!state.historyEnabled) { toast(t('DOCX export needs a saved document with history enabled. TXT, JSON and print use your current draft.')); return; }
            const savedId = await saveDocument(true);
            if (!savedId) { toast(t('Write and save a draft before exporting DOCX.')); return; }
            const response = await fetch(`/documents/${encodeURIComponent(savedId)}/export?format=${encodeURIComponent(format)}`, { credentials: 'same-origin', headers: { Accept: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' } });
            if (!response.ok || !response.headers.get('content-type')?.includes('wordprocessingml')) { const error = await response.json().catch(() => ({})); throw new Error(error.message || Object.values(error.errors || {}).flat()[0] || t('This document could not be exported.')); }
            const url = URL.createObjectURL(await response.blob()); const link = node('a'); link.href = url; link.download = `${title}.docx`; document.body.append(link); link.click(); link.remove(); setTimeout(() => URL.revokeObjectURL(url), 1000);
        } catch (error) { toast(error.message, true); }
    }
    function printReport() {
        document.querySelector('.report-print')?.remove();
        const report = reportData(); const container = node('article', 'report-print');
        container.append(node('h1', '', report.title || t('HumixAi report')), node('p', 'print-meta', t("HumixAi Studio · :value1 (Asia/Dhaka)", { value1: (dateLabel(report.generated_at)) })), node('p', '', report.note));
        if (report.detection) { const score = numeric(report.detection.overall_score); container.append(node('h2', '', t('Detection result')), node('p', '', `${report.detection.score_label || 'AI detection'}: ${score === null ? 'No score returned' : score + '%'} · ${statusLabel(report.detection.status)}${report.detection_stale ? ' · OUTDATED: original draft changed' : ''}`), node('p', 'print-meta', t("Provider: :value1 · Model: :value2 · Coverage: :value3 / :value4 characters", { value1: (report.detection.provider || 'Not reported'), value2: (report.detection.model_version || 'Not reported'), value3: (report.detection.analyzed_characters ?? 'Not reported'), value4: (report.detection.character_count ?? 'Not reported') }))); }
        container.append(node('h2', '', t('Original draft')), node('pre', '', report.original));
        if (report.revision) container.append(node('h2', '', t('Revised draft')), node('pre', '', report.revision));
        const comparable = report.result?.comparable === true && report.result.original_text === report.original && report.result.humanized_text === report.revision;
        if (comparable && numeric(report.result.original_score) !== null && numeric(report.result.humanized_score) !== null) container.append(node('h2', '', t('Comparable before and after')), node('p', '', t("Original: :value1% · Revised: :value2% · Change: :value3 percentage points. These results use the same provider, model and score definition.", { value1: (report.result.original_score), value2: (report.result.humanized_score), value3: (report.result.score_change) })));
        if (report.result?.preservation) container.append(node('h2', '', t('Protected text checks')), node('p', '', report.result.preservation.passed ? t('Protected passages passed exact-text checks. Review meaning and facts manually.') : t('Review required.')), node('pre', '', JSON.stringify(report.result.preservation, null, 2)));
        if (report.revision_edited_after_verification) container.append(node('p', '', t('The revision was edited after verification. Earlier scores do not apply to this exact draft.')));
        document.body.append(container); window.print();
    }
    async function copy(text, message = t('Copied to clipboard.')) {
        if (!text) return;
        try { await navigator.clipboard.writeText(text); toast(message); } catch (_) { toast(t('Clipboard access is unavailable. Select and copy the text directly.'), true); }
    }
    async function openShare() {
        if (!state.authenticated) { modal('signinDialog'); return; }
        try { if (!state.historyEnabled) { toast(t('Sharing needs a saved document with history enabled. Your session draft has not been shared.')); return; } const savedId = await saveDocument(true); if (!savedId) { toast(t('Write and save a draft before sharing.')); return; } $('shareUrl').value = ''; $('shareDialog').dataset.documentId = savedId; modal('shareDialog'); } catch (error) { toast(error.message, true); }
    }
    async function createShare() {
        $('createShare').disabled = true;
        try { if (!state.historyEnabled) throw new Error(t('Enable document history and save the draft before creating a share link.')); const savedId = await saveDocument(true); if (!savedId) throw new Error(t('There is no saved document to share.')); const result = await post(`/documents/${encodeURIComponent(savedId)}/share`, {}); $('shareDialog').dataset.documentId = savedId; $('shareUrl').value = result.url || result.share_url || ''; toast(t('Read-only report link created.')); } catch (error) { toast(error.message, true); } finally { $('createShare').disabled = false; }
    }
    async function openAccount() {
        modal('accountDialog'); clear($('accountContent')); $('accountContent').append(node('p', 'muted', t('Loading account…')));
        loadBilling();
        try {
            const data = await request('/account'); const user = data.user || data; const usage = data.usage || {};
            clear($('accountContent')); $('accountContent').append(node('p', 'muted', `${user.name || ''} · ${user.email || ''}`));
            addMeta($('accountContent'), [[t('Daily words used'), usage.used_words], [t('Words reserved by jobs'), usage.reserved_words], [t('Daily allowance'), usage.limit || data.daily_word_limit], [t('Remaining'), usage.remaining], [t('Plan'), user.plan]]);
            const row = node('label', 'checkbox-row'); const checkbox = node('input'); checkbox.type = 'checkbox'; checkbox.checked = user.history_enabled !== false;
            row.append(checkbox, node('span', '', t('Keep revision history for future drafts and analyses.'))); $('accountContent').append(row);
            checkbox.addEventListener('change', async () => { checkbox.disabled = true; try { await request('/account', { method: 'PATCH', body: { history_enabled: checkbox.checked } }); state.historyEnabled = checkbox.checked; scheduleSave(); toast(t('History preference updated.')); } catch (error) { checkbox.checked = !checkbox.checked; toast(error.message, true); } finally { checkbox.disabled = false; } });
            $('accountContent').append(node('p', 'muted', t('Allowance counts input words for every completed processing operation. A combined analysis can include an original scan, rewrite and revised scan. Reserved words are held for pending jobs; unused reservations are released.')));
            loadTokens();
        } catch (error) { clear($('accountContent')); $('accountContent').append(node('p', 'muted', error.message)); }
    }
    async function loadTokens() {
        try {
            const data = await request('/account/tokens'); const list = data.tokens || (Array.isArray(data) ? data : []); clear($('tokenList'));
            if (!list.length) $('tokenList').append(node('p', 'muted', t('No API tokens yet.')));
            list.forEach(token => { const row = node('div', 'token-row'); const description = node('div', '', token.name); description.append(node('small', '', t("Expires :value1", { value1: (token.expires_at ? dateLabel(token.expires_at) : 'not reported') }))); const revoke = node('button', 'text-button', t('Revoke')); revoke.addEventListener('click', async () => { try { await request(`/account/tokens/${encodeURIComponent(token.id)}`, { method: 'DELETE' }); loadTokens(); toast(t('Token revoked.')); } catch (error) { toast(error.message, true); } }); row.append(description, revoke); $('tokenList').append(row); });
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
            $('inputText').value = data.source_text; $('outputText').value = data.current_text !== data.source_text ? data.current_text : ''; $('documentTitle').value = typeof data.title === 'string' ? data.title : t('Untitled document');
            state.documentId = typeof data.document_id === 'string' ? data.document_id : null; applySettings(data.settings); state.originalForDiff = data.source_text;
            $('inputMeta').textContent = t(":value1 words · :value2 characters", { value1: (words(data.source_text)), value2: (characters(data.source_text)) }); updateOutputMeta(); show('sourceEmptyTools', !data.source_text.trim());
            if (state.historyEnabled && state.documentId) { state.savedSignature = typeof data.saved_signature === 'string' ? data.saved_signature : ''; if (state.savedSignature !== JSON.stringify(snapshot())) scheduleSave(); else $('saveState').textContent = t('Saved draft restored'); } else $('saveState').textContent = t('Session draft restored');
        } catch (_) {}
    }
    function navigateBilling(url) {
        let target;
        try { target = new URL(url); } catch (_) { throw new Error(t('A valid secure billing address was not returned.')); }
        if (target.protocol !== 'https:' || !['checkout.stripe.com', 'billing.stripe.com'].includes(target.hostname)) throw new Error(t('The billing address was not a recognized secure Stripe page.'));
        if (!saveCheckoutDraft() && !state.historyEnabled && $('inputText').value.trim()) throw new Error(t('Your browser could not preserve this session draft. Copy or export it before leaving for billing.'));
        location.assign(target.href);
    }
    async function loadBilling() {
        const container = $('billingContent'); clear(container); container.append(node('p', 'muted', t('Checking subscription availability…')));
        try {
            const data = await request('/billing/status'); clear(container);
            addMeta(container, [[t('Current plan'), data.plan], [t('Subscription status'), data.subscription_status]]);
            if (!data.configured) container.append(node('p', 'muted', t('Subscriptions are not configured. Your current allowance is available.')));
            if (data.pending_checkout) container.append(node('p', 'muted', t('An earlier checkout is pending. Review that session or manage your subscription before starting another.')));
            const plans = Array.isArray(data.plans) ? data.plans : [];
            if (data.configured === true) {
                plans.filter(plan => plan.available === true && plan.id !== data.plan).forEach(plan => {
                    const card = node('div', 'billing-plan'); card.append(node('h4', '', plan.name || plan.id), node('p', 'muted', t(":value1 daily allowance words, counted per completed operation.", { value1: (Number(plan.daily_word_limit || 0).toLocaleString()) })));
                    const row = node('label', 'checkbox-row'); const checkbox = node('input'); checkbox.type = 'checkbox'; row.append(checkbox, node('span', '', t('I want to view the subscription price and recurring terms in secure checkout.'))); card.append(row);
                    const button = node('button', 'button button-secondary', t('View secure checkout ↗')); button.disabled = true; checkbox.addEventListener('change', () => button.disabled = !checkbox.checked);
                    button.addEventListener('click', async () => { if (!checkbox.checked) return; button.disabled = true; try { await saveDocument(true); const checkout = await post('/billing/checkout', { plan: plan.id, acknowledge_checkout: true }); navigateBilling(checkout.url); } catch (error) { toast(error.message, true); button.disabled = false; } }); card.append(button); container.append(card);
                });
                container.append(node('p', 'muted', data.notice || t('Stripe shows the price, currency and recurring terms before you approve payment. A redirect alone does not activate a plan.')));
            }
            if (data.can_manage === true) { const manage = node('button', 'button button-secondary', t('Manage subscription securely ↗')); manage.addEventListener('click', async () => { manage.disabled = true; try { await saveDocument(true); const portal = await post('/billing/portal', {}); navigateBilling(portal.url); } catch (error) { toast(error.message, true); manage.disabled = false; } }); container.append(manage); }
        } catch (error) { clear(container); container.append(node('p', 'muted', t("Subscription details are unavailable. :value1", { value1: (error.message) }))); }
    }

    function requireExternal(continuation) {
        if (!state.authenticated) { localSave(); modal('signinDialog'); return false; }
        if (!state.consent) { state.consentContinuation = continuation; modal('consentDialog'); return false; }
        return true;
    }
    function contentDescription() {
        const type = $('reviewType').value;
        show('guidelineInput', type === 'guidelines');
        $('reviewDescription').textContent = ({ grammar: t('Suggestions help you edit; they do not certify correctness.'), guidelines: t('Review the draft against your own style rules. This is not an official publisher certification.'), meaning: t('Compare the studio original and revision for changes in facts, nuance and intent. This is an assisted review, not a guarantee.'), citations: t('Extract bibliography information using the connected provider. This does not verify citations or check plagiarism.'), plagiarism: t('Review textual similarity with provider web sources. A match may be a legitimate quotation or common phrase. Similarity is separate from AI detection.') })[type];
    }
    async function runContentCheck() {
        const text = $('inputText').value, revised = $('outputText').value, type = $('reviewType').value;
        if (!text.trim()) { toast(t('Add a studio draft before running content checks.')); return; }
        if (type === 'plagiarism' && state.capabilities?.plagiarism?.configured !== true) { toast(t('Web similarity checking is not configured on this workspace.')); return; }
        if (type === 'meaning' && !revised.trim()) { toast(t('A revision is needed to compare meaning.')); return; }
        if (type === 'guidelines' && !$('reviewGuidelines').value.trim()) { toast(t('Add the guidelines you want to check.')); return; }
        if (!requireExternal(runContentCheck)) return;
        $('runContentCheck').disabled = true; clear($('contentCheckResult')); $('contentCheckResult').append(node('p', 'muted', t('Reading your draft…')));
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
        if (stale) container.append(node('div', 'notice', t('The draft changed after this check. Run it again to review the current version.')));
        container.append(node('h3', '', result.feedback_label || display(result.review_type || t('Content review')).replaceAll('_', ' ')), node('p', 'review-summary', result.summary || result.message || t('No summary was returned.')));
        const issues = Array.isArray(result.issues) ? result.issues : [];
        if (result.score_type === 'similarity' && result.status === 'completed' && numeric(result.similarity_score) !== null) container.append(node('p', 'score-type', t(":value1% textual similarity · Provider web coverage, not AI authorship or a plagiarism verdict.", { value1: (result.similarity_score) })));
        (Array.isArray(result.matches) ? result.matches : []).forEach(match => {
            const card = node('article', 'review-issue'); let url;
            try { const parsed = new URL(match.source_url); if (['http:', 'https:'].includes(parsed.protocol) && !parsed.username && !parsed.password) url = parsed.href; } catch (_) {}
            if (url) { const link = node('a', 'source-match-link', match.title || new URL(url).hostname); link.href = url; link.target = '_blank'; link.rel = 'noopener noreferrer'; card.append(link); } else card.append(node('strong', '', match.title || t('Provider source')));
            if (numeric(match.similarity) !== null) card.append(node('p', 'muted', t(":value1% source similarity as reported by the provider.", { value1: (match.similarity) })));
            (Array.isArray(match.excerpts) ? match.excerpts : []).forEach(excerpt => card.append(node('blockquote', '', display(excerpt)))); container.append(card);
        });
        issues.forEach(issue => { const card = node('article', 'review-issue'); if (issue.category) card.append(node('span', 'small-tag', issue.category)); if (issue.excerpt) card.append(node('blockquote', '', issue.excerpt)); if (issue.message) card.append(node('p', '', issue.message)); if (issue.suggestion) card.append(node('p', 'review-suggestion', t("Suggestion: :value1", { value1: (issue.suggestion) }))); container.append(card); });
        if (result.evidence?.data !== undefined) { const details = node('details', 'evidence-json'); details.append(node('summary', '', t('View provider bibliography data')), node('pre', '', JSON.stringify(result.evidence.data, null, 2).slice(0, 60000))); container.append(details); }
        addMeta(container, [[t('Provider'), result.provider], [t('Model'), result.model], [t('Reviewed (Dhaka)'), result.reviewed_at ? dateLabel(result.reviewed_at) : null], [t('Status'), result.status]]);
        if (result.limitations) container.append(node('p', 'muted', Array.isArray(result.limitations) ? result.limitations.join(' ') : display(result.limitations)));
        if (result.usage) container.append(node('p', 'muted', t("Daily allowance: :value1 words used · :value2 remaining.", { value1: (result.usage.used_words ?? result.usage.used ?? 'Not reported'), value2: (result.usage.remaining ?? 'Not reported') })));
        const exportResult = node('button', 'text-button', t('Download this review (.json)')); exportResult.addEventListener('click', () => download('humixai-content-review.json', JSON.stringify(result, null, 2), 'application/json')); container.append(exportResult);
        if (scroll) container.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }
    function renderBatchItems() {
        clear($('batchItems'));
        if (!state.batchItems.length) $('batchItems').append(node('p', 'muted', t('Add documents to preview their extracted text before processing.')));
        state.batchItems.forEach((item, index) => {
            const details = node('details', 'batch-item'); const summary = node('summary'); summary.append(node('strong', '', item.filename), node('span', '', `${words(item.text)} words`)); details.append(summary);
            const preview = node('textarea', 'import-preview'); preview.value = item.text; preview.setAttribute('aria-label', t("Preview :value1", { value1: (item.filename) })); preview.addEventListener('input', () => { item.text = preview.value; summary.lastElementChild.textContent = `${words(item.text)} words`; }); details.append(preview);
            if (item.warnings?.length) details.append(node('p', 'muted', item.warnings.join(' ')));
            const remove = node('button', 'text-button', t('Remove from batch')); remove.addEventListener('click', () => { state.batchItems.splice(index, 1); renderBatchItems(); }); details.append(remove); $('batchItems').append(details);
        });
        $('runBatch').disabled = !state.batchItems.length;
    }
    async function importBatch(files) {
        $('batchImportButton').disabled = true;
        try {
            for (const file of Array.from(files).slice(0, 10 - state.batchItems.length)) {
                if (/\.txt$/i.test(file.name)) state.batchItems.push({ filename: file.name, text: await file.text(), warnings: [] });
                else { const form = new FormData(); form.append('file', file); form.append('language', $('language').value); const imported = await post('/imports', form); state.batchItems.push(imported); }
            }
            renderBatchItems(); toast(t('Review each extracted document before queueing. A batch holds up to 10 drafts.'));
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
            state.batchJobs = data.jobs || []; await loadBatchJobs(); toast(t(":value1 documents queued. Refresh status to follow their progress.", { value1: (data.count || state.batchJobs.length) }));
        } catch (error) { toast(error.message, true); }
        finally { $('runBatch').disabled = !state.batchItems.length; }
    }
    async function loadBatchJobs() {
        if (!state.authenticated) { emptyCollection($('batchJobs'), t('A workspace for every draft.'), t('Sign in to queue multiple documents and follow their progress.'), true); return; }
        try {
            const data = await request('/ai-tools/jobs'); const jobs = data.jobs || (Array.isArray(data) ? data : data.data || []); state.batchJobs = jobs;
            if (!jobs.length) { emptyCollection($('batchJobs'), t('A clear queue.'), t('Queued documents and their actual processing status will appear here.')); return; }
            clear($('batchJobs'));
            jobs.forEach(job => {
                const card = node('article', 'revision-card'); const row = node('div', 'section-row');
                row.append(node('h3', '', `${display(job.action || 'Document review').replaceAll('_', ' ')} · ${dateLabel(job.created_at)}`), node('span', 'small-tag', job.status)); card.append(row, node('p', 'muted', job.stage_message || stageLabels[job.stage] || job.stage || t('Waiting for processing')));
                const view = node('button', 'text-button', t('View job result ↗')); view.addEventListener('click', async () => { try { const full = await request(`/ai-tools/jobs/${encodeURIComponent(job.id)}`); const result = full.result?.data || full.result; if (!result) { toast(full.message || t('This job has not returned a result yet.')); return; } const detail = node('details', 'evidence-json'); detail.open = true; detail.append(node('summary', '', t('Job result')), node('pre', '', JSON.stringify(result, null, 2).slice(0, 100000))); view.replaceWith(detail); const exportResult = node('button', 'text-button', t('Download result (.json)')); exportResult.addEventListener('click', () => download(`humixai-job-${job.id}.json`, JSON.stringify(result, null, 2), 'application/json')); card.append(exportResult); } catch (error) { toast(error.message, true); } }); card.append(view); $('batchJobs').append(card);
            });
        } catch (error) { emptyCollection($('batchJobs'), t('Jobs could not be loaded.'), error.message); }
    }
    async function loadTeams() {
        if (!state.authenticated) { clear($('teamList')); $('teamList').append(node('p', 'muted', t('Sign in to create or join a team.'))); return; }
        try {
            const data = await request('/teams'); state.teams = data.teams || []; clear($('teamList'));
            if (!state.teams.length) $('teamList').append(node('p', 'muted', t('Create your first team or join with an invitation.')));
            state.teams.forEach(team => { const button = node('button', `team-list-button${state.teamId === team.id ? ' active' : ''}`); button.append(node('strong', '', team.name), node('span', '', team.role)); button.addEventListener('click', () => openTeam(team.id)); $('teamList').append(button); });
        } catch (error) { clear($('teamList')); $('teamList').append(node('p', 'muted', error.message)); }
    }
    async function openTeam(id) {
        state.teamId = id; show('teamDocumentPanel', false);
        try {
            const [data, docs] = await Promise.all([request(`/teams/${encodeURIComponent(id)}`), request(`/teams/${encodeURIComponent(id)}/documents`)]);
            const target = $('teamDetail'); clear(target); target.append(node('h2', '', data.team.name), node('p', 'muted', t("Your role: :value1. Reviewers can read and comment. Editors can also update shared drafts.", { value1: (data.role) })));
            const members = node('div', 'team-members');
            (data.members || []).forEach(member => { const row = node('div', 'capability-row'); const description = node('span', '', `${member.user?.name || 'Team member'} · ${member.role}`); row.append(description); if (data.role === 'owner' && member.role !== 'owner') { const remove = node('button', 'text-button', t('Remove')); remove.addEventListener('click', async () => { if (!window.confirm(t('Remove this team member and their shared document links?'))) return; try { await request(`/teams/${encodeURIComponent(id)}/members/${encodeURIComponent(member.user_id)}`, { method: 'DELETE' }); openTeam(id); } catch (error) { toast(error.message, true); } }); row.append(remove); } members.append(row); }); target.append(members);
            if (data.role === 'owner') {
                const invitation = node('form', 'team-invitation-form'); invitation.append(node('h3', '', t('Invite someone to think with you')));
                const role = node('select'); role.setAttribute('aria-label', t('Invitation role')); [['reviewer', t('Reviewer · read & comment')], ['editor', t('Editor · edit & comment')]].forEach(([value, label]) => { const option = node('option', '', label); option.value = value; role.append(option); });
                const email = node('input'); email.type = 'email'; email.placeholder = t('Restrict to an email (optional)'); email.setAttribute('aria-label', t('Optional invitation email'));
                const button = node('button', 'button button-secondary', t('Create invitation link')); const result = node('div'); invitation.append(role, email, button, result);
                invitation.addEventListener('submit', async event => { event.preventDefault(); button.disabled = true; try { const invite = await post(`/teams/${encodeURIComponent(id)}/invitations`, { role: role.value, email: email.value || undefined }); clear(result); const link = node('input'); link.readOnly = true; link.value = invite.url; link.setAttribute('aria-label', t('Team invitation link')); const copyLink = node('button', 'text-button', t('Copy invitation')); copyLink.type = 'button'; copyLink.addEventListener('click', () => copy(invite.url)); result.append(node('p', 'muted', t("Single-use invitation · expires :value1. Share this link yourself.", { value1: (dateLabel(invite.expires_at)) })), link, copyLink); } catch (error) { toast(error.message, true); } finally { button.disabled = false; } }); target.append(invitation);
            }
            const documentHeader = node('div', 'section-row team-documents-heading'); documentHeader.append(node('h3', '', t('Shared documents')));
            const attach = node('button', 'text-button', t('Share current studio draft ↗')); attach.addEventListener('click', async () => { try { const docId = await saveDocument(true); if (!docId) { toast(t('Save a studio draft with history enabled before sharing it with a team.')); return; } await post(`/teams/${encodeURIComponent(id)}/documents`, { document_id: docId }); openTeam(id); toast(t('This document is now available to your team.')); } catch (error) { toast(error.message, true); } }); documentHeader.append(attach); target.append(documentHeader);
            if (!docs.documents?.length) target.append(node('p', 'muted', t('No documents shared with this team yet.')));
            (docs.documents || []).forEach(link => { const row = node('div', 'team-document-row'); const open = node('button', 'text-button', link.document?.title || t('Untitled shared draft')); open.addEventListener('click', () => openTeamDocument(id, link.document_id)); row.append(open, node('span', 'muted', `${link.comments_count || 0} comments`)); if (data.role === 'owner' || data.team.owner_user_id === state.capabilities?.user?.id || link.attached_by_user_id === state.capabilities?.user?.id) { const detach = node('button', 'text-button', t('Remove link')); detach.addEventListener('click', async () => { try { await request(`/teams/${encodeURIComponent(id)}/documents/${encodeURIComponent(link.document_id)}`, { method: 'DELETE' }); openTeam(id); } catch (error) { toast(error.message, true); } }); row.append(detach); } target.append(row); });
            loadTeams();
        } catch (error) { clear($('teamDetail')); $('teamDetail').append(node('p', 'muted', error.message)); }
    }
    async function openTeamDocument(teamId, documentId) {
        try {
            const data = await request(`/teams/${encodeURIComponent(teamId)}/documents/${encodeURIComponent(documentId)}`); state.teamDocumentId = documentId; state.teamId = teamId;
            $('teamDocumentTitle').textContent = data.document.title; $('teamDocumentRole').textContent = data.role; $('teamDocumentText').value = data.document.current_text || data.document.source_text || '';
            const canEdit = data.role === 'owner' || data.role === 'editor'; $('teamDocumentText').readOnly = !canEdit; show('saveTeamDocument', canEdit); show('teamDocumentPanel'); clear($('teamComments'));
            (data.comments || []).forEach(comment => { const card = node('article', 'team-comment'); card.append(node('strong', '', comment.user?.name || t('Team member')), node('small', '', dateLabel(comment.created_at)), node('p', '', comment.body)); $('teamComments').append(card); });
            if (!data.comments?.length) $('teamComments').append(node('p', 'muted', t('Start the conversation with a thoughtful comment.')));
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
    $('openWritingRecord')?.addEventListener('click', async () => { try { const id=await saveDocument(true); location.assign('/writing-records/new'+(id?'?document='+encodeURIComponent(id):'')); } catch(error) { toast(error.message,true); } });
    $('clearVoice').addEventListener('click', () => { $('voiceSample').value = ''; if ($('voicePreferences')) $('voicePreferences').value = ''; toast(t('Voice sample cleared from this session.')); });
    $('menuButton').addEventListener('click', () => { $('sidebar').classList.add('open'); show('sidebarScrim'); });
    $('sidebarScrim').addEventListener('click', () => { $('sidebar').classList.remove('open'); show('sidebarScrim', false); });
    $('principlesButton').addEventListener('click', event => { event.preventDefault(); modal('principlesDialog'); });
    $('providerStatus').addEventListener('click', () => setScreen('settings'));
    $('styleButton').addEventListener('click', reviewStyle);
    $('sampleButton').addEventListener('click', () => { $('inputText').value = t('Good writing makes a complicated idea easier to understand. A useful first draft gives your thoughts a place to start, even when the sentences are still rough.\n\nThe next step is to read it with fresh eyes. Keep the details that matter, remove words that get in the way, and give each paragraph one clear point. The aim is to help your reader follow the thought in your own voice.'); onInput(); toast(t('Sample draft loaded. No authorship label is assumed.')); });
    $('consentCheckbox').addEventListener('change', () => $('confirmConsent').disabled = !$('consentCheckbox').checked);
    $('confirmConsent').addEventListener('click', () => { if (!$('consentCheckbox').checked) return; state.consent = true; $('externalConsent').checked = true; $('consentDialog').close(); const continuation = state.consentContinuation; state.consentContinuation = null; if (continuation) continuation(); else if (state.pendingAction) runAction(state.pendingAction); });
    $('externalConsent').addEventListener('change', () => { state.consent = $('externalConsent').checked; $('consentCheckbox').checked = state.consent; $('confirmConsent').disabled = !state.consent; });
    $('importButton').addEventListener('click', () => modal('importChoiceDialog'));
    $('chooseFileButton').addEventListener('click', () => { $('importChoiceDialog').close(); $('fileInput').click(); });
    $('importUrlButton').addEventListener('click', () => { $('importChoiceDialog').close(); if (!state.authenticated) { localSave(); modal('signinDialog'); return; } updateUrlHost(); modal('urlImportDialog'); });
    $('importUrl').addEventListener('input', updateUrlHost); $('urlImportForm').addEventListener('submit', importUrl);
    $('fileInput').addEventListener('change', () => importFile($('fileInput').files[0]));
    $('confirmImport').addEventListener('click', async () => { try { await saveDocument(true); } catch (error) { toast(t("The current draft could not be saved. :value1", { value1: (error.message) }), true); return; } state.epoch++; state.documentId = null; state.savedSignature = ''; resetAnalysis(); $('inputText').value = $('importPreview').value; $('documentTitle').value = ($('importDialog').dataset.title || $('importDialog').dataset.filename.replace(/\.[^.]+$/, '')).slice(0, 160); $('importDialog').close(); sourceTab('edit'); onInput(); toast(t('Imported text added as a new draft.')); });
    $('copyButton').addEventListener('click', () => copy($('outputText').value));
    $('useRevisionButton').addEventListener('click', () => { if (!$('outputText').value) return; const revision = $('outputText').value; const verification = state.result?.verification; const exact = revision === state.resultOutput; $('inputText').value = revision; $('outputText').value = ''; state.result = null; state.resultText = null; state.resultOutput = null; state.originalForDiff = ''; show('comparisonPanel', false); if (verification && exact) renderDetection(verification, revision); else { state.detectionText = state.detectionText ?? null; } updateOutputMeta(); sourceTab('edit'); resetSelection(); onInput(); toast(t('Revision is now your working draft.')); });
    $('exportButton').addEventListener('click', () => { const open = $('exportDropdown').hidden; show('exportDropdown', open); $('exportButton').setAttribute('aria-expanded', String(open)); });
    document.addEventListener('click', event => { if (!event.target.closest('.export-menu')) { show('exportDropdown', false); $('exportButton').setAttribute('aria-expanded', 'false'); } });
    $('shareButton').addEventListener('click', openShare); $('createShare').addEventListener('click', createShare); $('copyShare').addEventListener('click', () => copy($('shareUrl').value, t('Read-only report link copied.')));
    $('revokeShare').addEventListener('click', async () => { try { const id = $('shareDialog').dataset.documentId; if (!id) throw new Error(t('Open a saved document’s share controls first.')); await request(`/documents/${encodeURIComponent(id)}/share`, { method: 'DELETE' }); $('shareUrl').value = ''; toast(t('All shared links for this document were revoked.')); } catch (error) { toast(error.message, true); } });
    $('accountButton')?.addEventListener('click', openAccount); $('settingsAccount')?.addEventListener('click', openAccount); $('loadTokens').addEventListener('click', loadTokens);
    $('tokenForm').addEventListener('submit', async event => { event.preventDefault(); const button = event.target.querySelector('button'); button.disabled = true; try { const result = await post('/account/tokens', { name: $('tokenName').value, scopes: ['detect', 'humanize', 'jobs', 'documents'] }); $('newToken').textContent = `Copy this token now; it is shown once: ${result.plain_text_token || result.token || ''}`; show('newToken'); $('tokenName').value = ''; loadTokens(); } catch (error) { toast(error.message, true); } finally { button.disabled = false; } });
    $('accountDialog').addEventListener('close', () => { $('newToken').textContent = ''; show('newToken', false); $('deletePassword').value = ''; $('confirmDeleteAccount').checked = false; });
    $('deleteAccountForm').addEventListener('submit', async event => { event.preventDefault(); if (!$('confirmDeleteAccount').checked) return; const button = event.target.querySelector('button'); button.disabled = true; try { await request('/account', { method: 'DELETE', body: { password: $('deletePassword').value } }); try { localStorage.removeItem(draftKey); } catch (_) {} location.assign('/'); } catch (error) { toast(error.message, true); } finally { button.disabled = false; } });
    document.querySelector('.logout-form')?.addEventListener('submit', async event => {
        event.preventDefault();
        if (state.loggingOut) return;
        state.loggingOut = true;
        clearTimeout(state.saveTimer);
        try {
            // Signing out must not depend on an autosave or provider job completing.
            await post('/logout', {});
            state.authenticated = false;
            location.assign('/login');
        } catch (error) {
            state.loggingOut = false;
            toast(error.message, true);
        }
    });
    $('clearLocalDraft').addEventListener('click', () => { try { localStorage.removeItem(draftKey); } catch (_) {} toast(t('The saved browser draft was cleared. The current editor and saved account documents remain available.')); });
    $('contentChecksButton').addEventListener('click', () => { contentDescription(); modal('contentChecksDialog'); });
    $('reviewType').addEventListener('change', contentDescription); $('runContentCheck').addEventListener('click', runContentCheck);
    $('batchImportButton').addEventListener('click', () => $('batchFiles').click()); $('batchFiles').addEventListener('change', () => importBatch($('batchFiles').files));
    $('runBatch').addEventListener('click', runBatch); $('refreshBatchJobs').addEventListener('click', loadBatchJobs);
    $('createTeamForm').addEventListener('submit', async event => { event.preventDefault(); if (!state.authenticated) { modal('signinDialog'); return; } const button = event.target.querySelector('button'); button.disabled = true; try { const data = await post('/teams', { name: $('teamName').value }); $('teamName').value = ''; await loadTeams(); if (data.team?.id || data.id) openTeam(data.team?.id || data.id); } catch (error) { toast(error.message, true); } finally { button.disabled = false; } });
    $('joinTeamForm').addEventListener('submit', async event => { event.preventDefault(); if (!state.authenticated) { modal('signinDialog'); return; } let token = $('inviteToken').value.trim(); try { if (token.includes('invitation=')) token = new URL(token, location.origin).searchParams.get('invitation') || token; const data = await post('/teams/join', { token }); $('inviteToken').value = ''; try { sessionStorage.removeItem('humixai.pending-invitation'); } catch (_) {} await loadTeams(); if (data.team?.id || data.team_id) openTeam(data.team?.id || data.team_id); toast(t('You joined the team.')); } catch (error) { toast(error.message, true); } });
    $('saveTeamDocument').addEventListener('click', async () => { if (!state.teamId || !state.teamDocumentId) return; $('saveTeamDocument').disabled = true; try { await request(`/teams/${encodeURIComponent(state.teamId)}/documents/${encodeURIComponent(state.teamDocumentId)}`, { method: 'PATCH', body: { current_text: $('teamDocumentText').value } }); toast(t('Team draft saved.')); } catch (error) { toast(error.message, true); } finally { $('saveTeamDocument').disabled = false; } });
    $('teamCommentForm').addEventListener('submit', async event => { event.preventDefault(); if (!state.teamId || !state.teamDocumentId) return; const button = event.target.querySelector('button'); button.disabled = true; try { await post(`/teams/${encodeURIComponent(state.teamId)}/documents/${encodeURIComponent(state.teamDocumentId)}/comments`, { body: $('teamCommentText').value }); $('teamCommentText').value = ''; await openTeamDocument(state.teamId, state.teamDocumentId); } catch (error) { toast(error.message, true); } finally { button.disabled = false; } });
    window.addEventListener('beforeunload', event => { if (!state.loggingOut && (state.busy || $('inputText').value.trim() && (!state.historyEnabled || JSON.stringify(snapshot()) !== state.savedSignature))) { event.preventDefault(); event.returnValue = ''; } });
    try {
        const draft = JSON.parse(localStorage.getItem(draftKey) || 'null');
        if (typeof draft?.source_text === 'string') { $('inputText').value = draft.source_text; $('documentTitle').value = typeof draft.title === 'string' ? draft.title : t('Untitled document'); applySettings(draft.settings); if (typeof draft.current_text === 'string' && draft.current_text !== draft.source_text) { $('outputText').value = draft.current_text; state.originalForDiff = draft.source_text; } }
    } catch (_) {}
    $('inputMeta').textContent = t(":value1 words · :value2 characters", { value1: (words($('inputText').value)), value2: (characters($('inputText').value)) }); show('sourceEmptyTools', !$('inputText').value.trim()); updateOutputMeta();
    loadCapabilities(); loadDocuments();
    if (state.authenticated) request('/account').then(data => { state.historyEnabled = data.user?.history_enabled !== false; if (!state.historyEnabled) $('saveState').textContent = t('Session draft · history off'); else if ($('inputText').value.trim() && !new URLSearchParams(location.search).get('document')) scheduleSave(); }).catch(() => {});
    const requestedDocument = new URLSearchParams(location.search).get('document'); if (requestedDocument && state.authenticated) openDocument(requestedDocument);
    const invitation = new URLSearchParams(location.search).get('invitation');
    if (invitation && invitation.length <= 200) { try { sessionStorage.setItem('humixai.pending-invitation', invitation); } catch (_) {} $('inviteToken').value = invitation; setScreen('teams'); toast(t('Review and join the team with the invitation form.')); }
    const requestedVoice = new URLSearchParams(location.search).get('voice');
    if (requestedVoice && state.authenticated) request('/voice-profiles/'+encodeURIComponent(requestedVoice)).then(profile => {
        $('voiceSample').value = profile.sample; $('voicePreferences').value = profile.preferences || '';
        show('advancedControls'); $('advancedButton').setAttribute('aria-expanded', 'true');
        toast(t('Voice profile loaded for review. Nothing has been sent to an AI provider.'));
    }).catch(error => toast(error.message, true));
    const billingReturn = new URLSearchParams(location.search).get('billing');
    if (billingReturn === 'success') notice(t('Payment submitted; awaiting verified subscription update. Your current allowance comes from the server.'));
    else if (billingReturn === 'cancel' || billingReturn === 'cancelled') notice(t('Checkout was closed. Your current subscription and allowance are shown in your account.'));
})();
