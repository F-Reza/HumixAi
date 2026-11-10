export function normalizeSettings(rawUrl, rawToken) {
  let url;
  try { url = new URL(rawUrl); } catch { throw new Error('Enter the URL of your HumixAi app.'); }
  const local = ['localhost', '127.0.0.1'].includes(url.hostname);
  if ((url.protocol !== 'https:' && !(url.protocol === 'http:' && local)) || url.username || url.password || url.search || url.hash) {
    throw new Error('Use HTTPS, or HTTP on localhost/127.0.0.1, without URL credentials, query parameters or fragments.');
  }
  const token = rawToken.trim();
  if (!token || token.length > 200 || /\s/.test(token)) throw new Error('Enter a scoped HumixAi API token.');
  return { url: url.href.replace(/\/+$/, ''), token, originPermission: `${url.protocol}//${url.hostname}/*` };
}

export const characterCount = text => Array.from(text).length;
export const terminal = job => ['completed', 'partial', 'failed', 'cancelled'].includes(job?.status);

export function describeResult(job) {
  const data = job?.result?.data ?? job?.result;
  if (!data || typeof data !== 'object') return { summary: job?.error || `Job ${job?.status || 'status unavailable'}. No result is available yet.`, text: '' };
  const score = data.overall_score ?? data.humanized_score;
  const scan = data.verification ?? data;
  const scoreDescription = typeof score === 'number' && Number.isFinite(score)
    ? `${scan.score_label || 'Provider score'}: ${score}%.` : 'No authorship score was returned.';
  return { summary: `${job.status}. ${scoreDescription} ${data.message || ''} AI signals are estimates, not proof of authorship.`.trim(),
    text: typeof data.humanized_text === 'string' ? data.humanized_text : JSON.stringify(data, null, 2) };
}
