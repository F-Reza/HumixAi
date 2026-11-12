const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
test('all six catalogs cover every English key and preserve placeholders', () => {
    const english = JSON.parse(fs.readFileSync(path.join(root, 'lang/en.json'), 'utf8'));
    const placeholders = text => (text.match(/:[A-Za-z][A-Za-z0-9_]*/g) || []).sort();
    for (const locale of ['en','bn','hi','ur','ar','ne']) {
        const catalog = JSON.parse(fs.readFileSync(path.join(root, `lang/${locale}.json`), 'utf8'));
        assert.deepEqual(Object.keys(catalog).sort(), Object.keys(english).sort(), locale);
        for (const [key, value] of Object.entries(catalog)) {
            assert.equal(typeof value, 'string');
            assert.ok(value.trim(), `${locale}: ${key}`);
            assert.deepEqual(placeholders(value), placeholders(key), `${locale}: ${key}`);
        }
        if (locale !== 'en') assert.notEqual(catalog['Interface language'], 'Interface language');
    }
});
test('localization substitutes named values without interpreting HTML or touching drafts', () => {
    const context = { window: {}, document: { getElementById: id => id === 'humixTranslations' ? { textContent: JSON.stringify({'Hello :name':'স্বাগতম :name'}) } : null } };
    vm.runInNewContext(fs.readFileSync(path.join(root, 'public/assets/i18n.js'), 'utf8'), context);
    assert.equal(context.window.HumixI18n.t('Hello :name', {name:'<script>draft</script>'}), 'স্বাগতম <script>draft</script>');
    assert.equal(context.window.HumixI18n.t('Missing key'), 'Missing key');
});
