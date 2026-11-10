import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { normalizeSettings, describeResult, characterCount } from './settings.mjs';

test('connection validation requests only a chosen app host and rejects insecure remote URLs', () => {
  assert.deepEqual(normalizeSettings('http://localhost:8000/', 'hxi_test'), { url: 'http://localhost:8000', token: 'hxi_test', originPermission: 'http://localhost/*' });
  assert.equal(normalizeSettings('https://humix.example/app/', 'hxi_test').originPermission, 'https://humix.example/*');
  for (const url of ['http://remote.test', 'https://user:pass@host.test', 'https://host.test?key=x', 'file:///tmp/file']) assert.throws(() => normalizeSettings(url, 'hxi_test'));
});
test('unavailable scores stay unavailable while real zero probability stays zero', () => {
  const unavailable = describeResult({ status: 'partial', result: { status: 'unavailable', overall_score: null } });
  assert.match(unavailable.summary, /No authorship score/);
  assert.ok(!unavailable.summary.includes('0%'));
  const zero = describeResult({ status: 'completed', result: { status: 'completed', overall_score: 0, score_label: 'Probability of GPTZero’s AI class' } });
  assert.match(zero.summary, /0%/);
});
test('Unicode character limits use code points for emoji and Bengali', () => {
  assert.equal(characterCount('🙂 বাংলা'), Array.from('🙂 বাংলা').length);
  assert.equal(characterCount('🙂'), 1);
});
test('MV3 source keeps credentials in session storage with no remote scripts or automatic page replacement', async () => {
  const manifest = JSON.parse(await readFile(new URL('./manifest.json', import.meta.url), 'utf8'));
  const source = await readFile(new URL('./popup.mjs', import.meta.url), 'utf8');
  assert.equal(manifest.manifest_version, 3);
  assert.equal(manifest.host_permissions, undefined);
  assert.equal(manifest.content_scripts, undefined);
  assert.ok(manifest.permissions.includes('activeTab'));
  assert.match(source, /chrome\.storage\.session/);
  assert.doesNotMatch(source, /storage\.(?:local|sync)|localStorage|innerHTML|insertAdjacentHTML|console\./);
});
