(() => {
    'use strict';
    const catalog = JSON.parse(document.getElementById('humixTranslations')?.textContent || '{}');
    const t = (key, values = {}) => (catalog[key] || key).replace(/:([A-Za-z][A-Za-z0-9_]*)/g, (m, name) => Object.hasOwn(values, name) ? String(values[name]) : m);
    window.HumixI18n = { t };
    document.getElementById('interfaceLocale')?.addEventListener('change', async event => {
        const select = event.target; select.disabled = true;
        try {
            const response = await fetch('/locale', { method:'PATCH', credentials:'same-origin', headers:{'Content-Type':'application/json', Accept:'application/json', 'X-CSRF-TOKEN':document.querySelector('meta[name="csrf-token"]').content}, body:JSON.stringify({locale:select.value}) });
            if (!response.ok) throw new Error(t('Language could not be saved. Please try again.'));
            select.disabled = false;
            location.reload();
        } catch(error) { document.getElementById('localeError').textContent = error.message; select.disabled = false; }
    });
})();
