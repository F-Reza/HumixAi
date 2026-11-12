# HumixAi Studio

A Laravel writing workspace for real AI-detection results, controlled rewriting and transparent review. The application does not invent competitor scores or describe writing statistics as calibrated AI probabilities.

## Run locally

Requirements: PHP 8.2+, Composer, SQLite (or MySQL), mbstring, intl, DOM/XML, fileinfo and ZIP. cURL enables public URL import. Optional Poppler `pdftotext` enables text-PDF import. No Node build is required for the web app.

```powershell
composer install
if (-not (Test-Path .env)) {
    Copy-Item .env.example .env
    php artisan key:generate
}
php artisan migrate
php artisan serve --host=127.0.0.1 --port=8000
```

The setup preserves an existing `.env` and application key. Start a second terminal for processing jobs:

```powershell
php artisan queue:work database --tries=1 --timeout=300 --sleep=1
```

Open `http://127.0.0.1:8000`, create an account, and use the studio. Run the scheduler so transient drafts and abandoned jobs are pruned:

```powershell
php artisan schedule:work
```

On a production host, use its process supervisor for the queue and Laravel's normal minute-by-minute scheduler. Run with `APP_ENV=production`, `APP_DEBUG=false`, HTTPS and secure session cookies. The web root must be `public/`.

## Server configuration

Keep credentials in server configuration. Visitors cannot edit `.env` through the application.

| Variable | Purpose |
| --- | --- |
| `GPTZERO_API_KEY` | Real authorship detection and citation/bibliography evidence. Required for an actual AI percentage. |
| `GPTZERO_MODEL_VERSION` | Optional requested detector version; comparisons use the version actually returned. |
| `GEMINI_API_KEY`, `GEMINI_MODEL` | Controlled rewriting and grammar/guideline/meaning feedback. Default model: `gemini-3.8-flash`. |
| `WALTER_API_KEY` | Optional real plagiarism/source similarity checks. Separate from AI detection. |
| `PDFTOTEXT_PATH` | Optional executable path, or `pdftotext` found through PATH. |
| `STUDIO_QUEUE_CONNECTION` | Defaults to `database`. Use `sync` only when synchronous processing is intentional. |
| `STUDIO_MAX_CHARACTERS` | Maximum draft length, default 60,000 characters. No silent truncation. |
| `STUDIO_MAX_FILE_KB` | Upload cap, default 6,144 KB. |
| `STUDIO_DAILY_WORD_LIMIT` | Default per-account daily processing allowance, 20,000 input-word units. |
| `STUDIO_MAX_BATCH_SIZE` | Default maximum of 10 documents per batch. |
| `STUDIO_JOB_RETENTION_HOURS` | Transient job retention, default 24 hours. Requires scheduler. |
| `STUDIO_ALLOW_REGISTRATION` | Enable/disable new accounts. |
| `STUDIO_URL_IMPORT_ENABLED` | Enable public-page import, with public DNS/address validation and pinned connections. |

`php artisan humix:status` reports configuration availability without printing credentials. Configuration does not prove connectivity, a valid subscription or remaining provider allowance. After server configuration changes, restart queue workers and clear/rebuild Laravel's configuration cache as appropriate.

Without a detector key, local writing review works and AI scores are null. Without a writing key, rewriting is unavailable; there is no disguised regex fallback. Provider errors never produce simulated results.

## What is implemented

- One editor with **Detect AI**, **Humanize**, **Analyze & Improve** and separate local style review.
- Genuine GPTZero class probabilities, provider labels, confidence when supplied, reported model version, full coverage metadata and sentence evidence when available.
- Professional, Academic, Casual and Marketing tones; rewrite strength and length controls; exact selected-passage rewriting with Unicode offsets.
- Protected citations, quotations, URLs, numbers, negation, user terms and recognizable named spans. Removed, changed or duplicated protection markers reject the draft safely.
- An original scan, rewrite, exact protection checks and recheck in one pipeline; honest partial failures and signed before/after changes only when results are comparable.
- Separate grammar/style, custom guideline and assisted meaning reviews using Gemini. Citation evidence comes from GPTZero; plagiarism similarity comes from Walter when configured.
- Responsive local-asset UI with safe highlighting/diffs, manual revision review, stale-result notices, copy/export, saved drafts and revision history.
- Registration/login/logout, ownership checks, daily allowance reservation/settlement, history opt-out and account deletion.
- TXT, DOCX, optional text-PDF and public URL import with extraction preview. OCR, signed-in pages and JavaScript rendering are not claimed.
- TXT, JSON, DOCX and audit-text exports; browser print-to-PDF; expiring, revocable read-only report snapshots.
- Bounded background jobs and batch processing with real stage updates, idempotent requests, queue failure cleanup and reservation expiry checks.
- Team owners/editors/reviewers, single-use invitation links, explicitly linked documents, editing permissions and comments. Invitations are not emailed automatically.
- Scoped, expiring, revocable API tokens. Plaintext tokens are shown once; only hashes are stored.
- Optional hosted Stripe checkout and subscription management, disabled until the server is configured. No card data is collected by HumixAi.
- Optional browser-extension and MCP client source in [integrations](integrations/). These are not installed or published automatically.

See [implementation status](docs/implementation-status.md) for the boundary between working modules, external setup and longer-term roadmap items. The original [product blueprint](docs/humixai-product-blueprint.md) is the planning reference.

## Score interpretation

`overall_score` is the probability of GPTZero's **AI class**, expressed as a percentage. It is not the percentage of words proven to have been written by AI. Human, Mixed and AI probabilities remain separate. A provider's confidence is shown only when it returns one.

Local readability, repetition and sentence-variety measurements describe writing quality; they do not establish authorship. English Flesch readability is not applied to Bengali. Bengali rewriting is experimental; Bengali authorship detection is not enabled in this release.

`score_change` is revised minus original, so a negative value means a lower AI-class probability. A comparison requires completed full scans with matching provider, reported model version, score definition and language. Missing versions or failed scans make comparison unavailable. Edits invalidate results for the previous text.

Protection checks verify exact surface text and number sequences. They cannot prove complete semantic fidelity or factual correctness. Assisted meaning review is a separate check. A lower detector result is not proof of human authorship, plagiarism clearance or better writing.

## Privacy and usage

External actions require a signed-in user and explicit processing acknowledgement. Local review sends the draft to this HumixAi server, not an external AI provider. AI actions send only the requested content to their configured providers.

Voice samples are excluded from saved document settings and profiles. Queued work temporarily contains its draft and optional voice sample until completion/failure/expiry; the scheduler removes expired jobs. Turning off history prevents new saved revisions, including after an in-flight provider call. Deleting a document removes its revisions, shares and associated transient job data.

Daily allowances count completed operations in input-word units. A combo reserves room before dispatch and settles only completed operations; an unavailable detector consumes no detection units. A completed original scan can still count if a later rewrite fails; that scan remains available in the job result. These usage units are not a statement of a vendor's monetary billing.

Share links expose a snapshot and draft text to anyone who has the link. They expire and can be revoked. Shared scan evidence is derived from server-recorded revisions, not user-submitted settings.

## API

Create a token from **Account → API tokens**. Send it as `Authorization: Bearer ...`. Supported scopes are `detect`, `humanize`, `jobs` and `documents`. Job submission also requires the scope for its actual action; attaching a saved document requires `documents`.

The [OpenAPI 3.1 document](docs/openapi.json) provides machine-readable requests, responses, scopes and failure states.

| Endpoint | Use |
| --- | --- |
| `GET /api/health`, `GET /api/v1/status` | Health and secret-free capability status. |
| `POST /api/v1/style` | Local writing statistics; no external AI call. |
| `POST /api/v1/detect` | Real AI detection or an explicit unavailable/limited result. |
| `POST /api/v1/humanize` | Controlled rewriting without authorship scans. |
| `POST /api/v1/combo` | Original scan, rewrite, checks and recheck. |
| `POST /api/v1/jobs`, `GET /api/v1/jobs/{id}` | Queue processing and inspect exact stages/results. |
| `POST /api/v1/batch` | Queue separate jobs for a bounded document set. |
| `POST /api/v1/review` | Grammar, guidelines or meaning review. |
| `POST /api/v1/citations`, `POST /api/v1/plagiarism` | Optional provider source checks. |
| `/api/v1/documents` | Owned document CRUD, revisions and exports. |
| `POST /api/v1/imports`, `POST /api/v1/imports/url` | Preview file/public-page extraction. |

Example JSON for a combo/job (token supplied through your client's secret configuration):

```json
{
  "action": "combo",
  "text": "Your draft with enough text for the configured detector...",
  "mode": "professional",
  "strength": "balanced",
  "length": "similar",
  "language": "en",
  "protected_terms": ["HumixAi"],
  "external_processing_consent": true,
  "idempotency_key": "unique-operation-identifier"
}
```

Reusing an idempotency key with the same payload returns the existing job. A different payload returns HTTP 409. API consent must reflect the actual user's choice; integrations should not silently assume it. Never embed bearer tokens in page URLs.

## Optional billing

Configure `BILLING_ENABLED`, `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET`, `STRIPE_PRO_PRICE_ID` and `PRO_DAILY_WORD_LIMIT`. Start with Stripe test mode. Optionally pin a supported `STRIPE_API_VERSION`; otherwise the account's API version applies. Point signed subscription webhooks at `/api/billing/webhook` over HTTPS.

Plan pricing/currency/recurrence is configured in Stripe and displayed by hosted Checkout before approval. HumixAi does not invent a price, activate a plan from a success URL or perform payments during tests. Existing subscription management remains separate from enabling new purchases. End subscriptions before deleting the associated account.

Subscribe the webhook to `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`, `customer.subscription.paused` and `customer.subscription.resumed`. Each delivery reconciles current Stripe state rather than assuming webhook order. Enable subscription cancellation in your Stripe customer portal configuration.

Open checkouts are reused and new sessions expire after 30 minutes. Ambiguous requests retain an exact idempotent retry for 23 hours; older unresolved attempts require an administrator to verify Stripe state before clearing the local pending marker. Pending purchases or nonterminal subscriptions block another checkout and account deletion. Refunds/disputes and remote Stripe customer deletion remain manual Dashboard operations. Multiple app instances require a shared atomic lock store.

## Verification

```powershell
php vendor/phpunit/phpunit/phpunit
node --test integrations/mcp/client.test.mjs integrations/browser-extension/settings.test.mjs
node --test tests/frontend/studio.test.cjs
node --check public/assets/humixai.js
php artisan view:cache
composer validate --no-check-publish
```

PHP tests force an in-memory database, blank provider keys and blocked stray HTTP. Provider, payment and URL scenarios use fakes. These are functional/integration tests, not evidence of detector accuracy or guaranteed external-provider behavior. Run a representative held-out evaluation before publishing numerical accuracy claims.

Browser visual verification was unavailable in this environment because Computer Use could not reliably verify the current browser URL. HTTP smoke checks and automated functional checks are recorded separately; no screenshot-based visual QA is claimed.

## System Admin and languages

English is the primary interface language; Bangla, Hindi, Urdu, Arabic and Nepali are available from the language selector. Interface and writing languages are independent. Arabic and Urdu use RTL layouts.

After registering an account, promote it on your own server:

```sh
php artisan migrate --force
php artisan humix:admin your-existing-account@example.com
```

Sign in and open `/admin` to manage users, access, quotas, jobs, settings, encrypted AI provider credentials and audit history. No default administrator credentials are created. Configure the GPTZero key in **AI providers** to enable live detection. See [admin and localization setup](docs/admin-and-localization.md).

To create a missing administrator account directly, run `php artisan humix:admin admin@humix.com --create`. Enter the name and password in the terminal prompts; the password is hidden and must be confirmed. Existing accounts are promoted without resetting their passwords.

## Blueprint expansion workflows

- `/account/security`: email verification; `/forgot-password`: password recovery. Set SMTP and `ACCOUNT_EMAIL_ENABLED=true` to enable delivery.
- `/voice-profiles`: opt-in encrypted personal/brand samples and editable audience/style preferences.
- `/website-checks`: consented daily/weekly public-page scans with pause/resume/delete. Requires the database worker, scheduler and GPTZero key.
- PNG/JPEG import: optional local OCR. Install Tesseract/language packs, set `TESSERACT_PATH` and enable `STUDIO_OCR_ENABLED`. Select the image language before importing.

See [setup and boundaries](docs/blueprint-expansion.md). Run `php artisan migrate --force` on other deployments; local migrations are already applied.

Publisher workflow: open `/sitemap` to preview XML sitemap pages or manually choose a child sitemap. Select pages and authorize their schedules within the 10-page account cap. `/website-checks/export` downloads your latest per-page reports as JSON. Preview does not run detector scans.

Opt-in draft recording is available at `/writing-records` (also in the studio account panel). It includes encrypted snapshots, replay slider and JSON export; it does not certify authorship. Offline detector evaluation instructions: [docs/detector-evaluation.md](docs/detector-evaluation.md).

WordPress connector: [installation guide](integrations/wordpress/README.md). Build the installable ZIP with `python integrations/wordpress/build.py`; server-side personal API token configuration and a running HumixAi queue worker are required.
