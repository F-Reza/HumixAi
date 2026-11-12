# HumixAi WordPress companion

This installable plugin adds a HumixAi panel to post/page editing and **Tools > HumixAi**. It imports the current Classic or Block Editor draft as plain text, allows manual review, and submits Detect, Humanize or Combo jobs only after explicit processing consent. Results show the provider report and a separate read-only rewritten draft. Copy text back manually; it never saves, publishes, or replaces WordPress content automatically.

## Installation

1. Build with `python integrations/wordpress/build.py` from the HumixAi repository. Upload `output/humixai-wordpress.zip` through WordPress Plugins > Add New > Upload Plugin, then activate.
2. In HumixAi create a separate personal API token for each WordPress writer with `jobs`, `detect` and `humanize` scopes (or omit `humanize` for detect-only use).
3. Configure the public HTTPS HumixAi deployment and map WordPress numeric user IDs to their own tokens in server-side `wp-config.php`, before the stop-editing line:

```php
define('HUMIXAI_URL', 'https://your-humixai.example');
define('HUMIXAI_TOKENS', [
    12 => getenv('HUMIXAI_WRITER_12_TOKEN'),
]);
```

Use environment/server secret management for actual values. Do not paste tokens into posts or front-end JavaScript. Each writer should have their own HumixAi account/token; using a shared account shares that account's quota and job visibility. Revocation is performed in HumixAi. Missing mappings fail closed. No token is stored in the WordPress database or sent to the browser.

4. Run the HumixAi database queue worker and configure the relevant GPTZero/rewrite credentials. WordPress PHP needs OpenSSL/HTTPS support and mbstring. This package requires PHP 8.0+. HTTPS with valid certificates is required; the client rejects credential-bearing URLs, query strings, fragments and nonstandard ports. WordPress safe HTTP validation blocks private targets. Local-only `http://localhost` development is intentionally unsupported by this connector.
5. Open a post/page, use **Import current editor text** or paste into the panel, review, select language/action, tick consent and choose **Run HumixAi**. On the Tools page, paste text directly. Polling stops after about three minutes; **Check result** resumes. **Stop waiting** stops browser polling, not server processing or billing. Check the HumixAi workspace after a lost response/reload before creating another job.

## Data and results

Only the reviewed textarea, chosen language/action and submission identifier go to the configured HumixAi deployment. HumixAi then uses its configured detector/rewriter. No background post scan, publication hook or telemetry runs. Import removes HTML/embedded media and does not preserve formatting; review text before sending. WordPress stores only a user-bound job-ID transient, for up to one day; no drafts/results are deliberately saved in WordPress by this plugin. HumixAi jobs follow its configured retention/usage policy. Unsaved panel content is lost on navigation.

Provider percentages are passed through as reported, not averaged or invented; unavailable scores remain null. The JSON report includes provider status, coverage and sentence details where supplied. Rewrites are rendered in a textarea; reports use textContent. No provider HTML is executed. Identical in-page retries reuse the same idempotency key; changed text/settings get a new key. After a completed operation, reload the panel to deliberately run an identical new job.

English, Bangla, Hindi, Urdu, Arabic and Nepali interface catalogs are bundled. The interface follows the WordPress user's language (unsupported locales fall back to English); Arabic/Urdu panel direction is RTL. Source-language selection is independent. Provider report messages may retain their server language. WordPress language packs themselves are installed through WordPress.

## Verification and limits

- `php integrations/wordpress/client-test.php`: isolated WordPress HTTP-stub checks for validation, personal-token selection, request bounds, no redirects, invalid responses and operation restrictions.
- `node --test integrations/wordpress/companion.test.cjs`: consent, source preservation, safe output and retry identity tests.
- `python integrations/wordpress/build.py`: generates six catalogs and a deterministic ZIP without credentials.

No live WordPress instance is available in the development workspace. Activation, actual Classic/Block Editor imports, multisite, plugin/theme conflicts and end-to-end live provider processing must be exercised on staging before deployment. This is a draft-review connector, not a Gutenberg rich-text replacement, automatic content publisher, whole-site scanner, Google Docs/LMS connector or outgoing webhook implementation.

Implementation references: [WordPress safe HTTP requests](https://developer.wordpress.org/reference/functions/wp_safe_remote_request/) and [Block Editor meta boxes](https://developer.wordpress.org/block-editor/how-to-guides/metabox/).
