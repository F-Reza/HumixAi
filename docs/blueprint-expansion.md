# Blueprint expansion — 11 November 2026

This increment adds working account, voice, recurring-page and image-import workflows. It does not claim that every long-term research/integration project in the blueprint is finished.

## Account recovery and verification

- `/forgot-password` requests a time-limited password-reset link. Responses do not reveal whether an email exists. Requests are rate limited; suspended accounts receive no recovery email.
- `/reset-password/{token}` accepts a new, confirmed password of 10–128 characters. Tokens are hashed in the database, expire after 60 minutes and are consumed on success. Reset revokes API tokens/public shares and invalidates other password-bound web sessions.
- `/account/security` sends an expiring, signed verification link on explicit request. The link must match the signed-in account. Verification is recorded without making existing writing workflows require email verification.
- Email links use `APP_URL`, rather than a caller-supplied host. Native forms have CSRF protection. Passwords and private samples are excluded from flashed validation input.

Configure `APP_URL` and the SMTP variables in `.env.example`, then set `ACCOUNT_EMAIL_ENABLED=true`. Normal mail transport is SMTP; there is no log transport that silently records reset links. No mail was sent during implementation: tests use notification fakes. Delivery must be tested with your own mailbox/provider before public release.

## Personal and brand voice profiles

Open `/voice-profiles`, or **Manage voice profiles** under the studio's advanced controls. Create/edit a name, personal/brand type, language, 30–3,000 character writing sample and optional 1,000 character audience/style preferences. Every save requires explicit storage consent. Samples and preferences are encrypted using the application key; lists never return samples. Up to 20 profiles per account are allowed.

**Use in studio** loads the sample and preferences for review. It does not submit anything to an AI service. Existing studio processing consent is still required for rewriting. The reference remains separate from the source text and is sent as advisory style data, never as permission to change protected facts. Clearing the session sample does not delete a saved profile; profile deletion is available on the profiles page. Account deletion cascades to profiles. Jobs already submitted follow normal processing retention.

## Scheduled website checks

Open `/website-checks` from the account panel. Add an individual public page, explicit source language and daily/weekly frequency. A required consent checkbox authorizes recurring public retrieval and sending that page's text to GPTZero. There are at most 10 saved pages per account.

The scheduler queues at most 20 due pages per invocation. Atomic claims and leases prevent duplicate concurrent dispatch. The existing safe URL importer validates DNS/public IPs, pins the connection, validates redirects and bounds downloads. Suspended users cannot start provider work. Actual detector operations use the existing word-reservation/settlement system. The latest report retains provider/model, probabilities, coverage, time and text hash, not the page body or sentence text. Errors produce no score.

Users can check now, pause/resume or delete schedules. Already-started network/provider calls cannot be recalled. Pause prevents later provider submission when observed before that stage. Failed workers release their recorded reservation; after deletion during a worker crash, any orphaned allowance follows the existing bounded reservation-expiry mechanism.

Run both the queue worker and scheduler:

```sh
php artisan queue:work database --tries=1 --timeout=300
php artisan schedule:work
```

For production, supervise the worker and invoke `php artisan schedule:run` every minute. `humix:website-checks` is the bounded dispatch command. No actual public page was crawled or sent to GPTZero during tests. Bounded sitemap discovery is now available at `/sitemap`; automatic recursive whole-site crawling remains separate work.

## Optional local image OCR

PNG/JPEG import uses [Tesseract's CLI](https://tesseract-ocr.github.io/tessdoc/Command-Line-Usage.html). Install the engine and the desired language packs: `eng`, `ben`, `hin`, `urd`, `ara`, `nep`. Set `TESSERACT_PATH` and `STUDIO_OCR_ENABLED=true`. Availability comes from the installed pack list; image language must be chosen explicitly in the studio. There is no silent English fallback.

Images are limited by the existing upload-byte cap and a 20-megapixel bound. Commands use argument arrays, a 30-second timeout, bounded output and one OCR thread. Extracted text is shown in the existing editable import preview before any detector request. OCR is local; it is not AI authorship detection. Scanned PDFs must currently be exported as page images first; automatic multipage PDF rasterization is not included.

This machine has no available OCR engine/packs and account email is not configured. `php artisan humix:status` now reports both capabilities without printing secrets. Configure these dependencies to activate them.

## Browser route contracts

These routes use session authentication, not the scoped public bearer-token API. Native forms accept `_token`; PUT/PATCH/DELETE forms use `_method`.

| Route | Fields / behavior |
| --- | --- |
| POST `/forgot-password` | `email`; generic redirect/status |
| POST `/reset-password` | `token`, `email`, `password`, `password_confirmation` |
| POST `/email/verification-notification` | Signed-in account; throttled |
| GET `/email/verify/{id}/{hash}` | Signed URL `expires`/`signature`; matching signed-in owner |
| POST `/voice-profiles` | `name`, `kind` personal/brand, `language` auto or six codes, `sample`, optional `preferences`, accepted `storage_consent` |
| GET/PUT/DELETE `/voice-profiles/{id}` | Owner-only detail, same fields for update, or deletion |
| POST `/website-checks` | `url`, explicit `language`, `frequency` daily/weekly, accepted `processing_consent` |
| PATCH/DELETE `/website-checks/{id}` | Boolean `active`, or deletion |
| POST `/website-checks/{id}/run` | Owner-only, active schedule, bounded manual frequency |

The existing import endpoint now accepts an optional `language`; images require an installed explicit OCR language. Studio processing accepts optional `voice_preferences` alongside `voice_sample`. Browser-extension/MCP behavior is otherwise unchanged.

## Verification and remaining boundaries

- **128 PHP tests / 953 assertions** pass, including ownership, encryption, explicit consent, session invalidation, signed links, token expiry, scheduled-job claims, quota release and OCR rejection/availability cases.
- **24 Node tests** pass, including voice preview without automatic processing and six catalogs with 939 matching keys/placeholders.
- Additive migrations `000700` and `000800` are applied locally; no user data was reset. Blade and route caches compile, and Composer validates.
- Translation generation sent only public interface strings. No real email, detector scan, website crawl or payment was performed; no live OCR engine or visual browser QA is claimed.

Still outside this increment: proprietary trained/calibrated detector and independent language benchmarks; media/deepfake detection; native mobile clients; full Google Docs/LMS/WordPress integrations and outgoing webhooks; credit top-ups; opt-in typing replay; automatic recursive whole-site crawling; source-backed fact verification beyond the existing bibliography/source adapters. These require their own implementation and, where applicable, licensed data, provider accounts, platform authorization or independent evaluation.

## Sitemap and publisher reports ? additional increment

From `/website-checks`, choose **Import sitemap**. Enter one public XML sitemap URL, preview its same-origin pages, choose pages/language/frequency, and explicitly authorize recurring processing. A sitemap index shows child sitemaps for manual selection; it does not recursively fetch them. The parser uses the [standard XML sitemap structure](https://www.sitemaps.org/protocol.html).

Limits: existing bounded URL-import byte cap (default 1 MiB, maximum 2 MiB), at most 200 preview URLs, at most 10 selected/account schedules, and 15-minute previews. Compressed sitemaps are not supported. Cross-origin, credential-bearing, private/reserved and nonstandard-port URLs are rejected or skipped. XML document types/entities are rejected and external network resolution is disabled. Redirects/DNS use the same pinned public-address checks as URL import.

The server stores the allowed URLs with the account ID, random preview nonce and expiry. Scheduling accepts selected indexes, never arbitrary hidden URLs. Capacity checks and inserts are atomic; duplicate schedules are kept unchanged. Refreshing a preview page does not repeat the network fetch. Previewing or scheduling performs no AI scan; scheduled workers follow the existing explicit consent and allowance rules.

**Export website reports** downloads owned metadata and latest page results as JSON, with state counts. It contains no combined website AI percentage or averaged probability.

Verification: the full PHP suite passed **135 tests / 993 assertions**, followed by the refined redirect workflow's **7 focused sitemap tests / 42 assertions**; **24 Node tests** pass. All six catalogs contain 959 matching keys/placeholders. No live sitemap was fetched and no provider scan was performed.

## Opt-in writing replay and offline evaluation - additional increment

`/writing-records` now provides an explicit-consent draft recorder. In the studio account panel choose **Writing records** or **Record this draft**. Creating a record stores an initial imported snapshot; **Start recording** requires a second explicit checkbox. While editing, the browser stores snapshots about every five seconds. **Stop recording** flushes the latest edit and pauses. Reloading does not automatically resume capture. Replay has a position slider, play/pause and event counts; JSON export includes the saved snapshots. Copy the draft back to the studio explicitly: recording does not overwrite a linked document.

This is coarse draft replay, not every keystroke. Event kinds are client-reported typing/paste/cut/drop/undo/redo/mixed/unknown; they are not authenticated evidence. No human-written percentage is inferred. Initial text is never attributed to newly typed writing. Text is encrypted at rest; ownership checks cover every route. Storage requires document history enabled. There are at most 20 records per account, 300 frames per record and 4 MiB of plaintext snapshot bytes per record, with 60,000 characters per snapshot. Sequence checks and idempotent event IDs reject conflicting writes. Delete a record, its linked document or its account to remove its frames. History-disabled accounts cannot append; existing records remain available for explicit export/deletion. No draft is sent to an AI provider by recording.

Browser/session routes: GET/POST `/writing-records`, GET `/writing-records/new`, GET/DELETE `/writing-records/{id}`, PATCH `/writing-records/{id}/state` (`active`, consent when enabling), GET/POST `/writing-records/{id}/frames`, GET `/writing-records/{id}/export`. Writes require CSRF. Frame POST accepts `event_id` UUID, `expected_sequence`, `kind`, `recording_text`. Native record creation accepts `title`, optional owned `document_id`, optional `recording_text`, accepted `storage_consent`.

The offline evaluator is documented in [detector evaluation](detector-evaluation.md). It validates labelled prediction files, detects declared group leakage, and computes per-language/domain confusion matrices, precision/recall, human false-positive rate, multiclass Brier score and calibration bins. This completes tooling only: no independent dataset or measured production accuracy is claimed.

Verification for this increment: 139 PHP tests / 1,038 assertions, 26 Node tests, and 4 Python evaluator tests pass. Migration `000900` is applied locally. Six language catalogs contain 1,006 matching keys/placeholders. No visual browser QA or live detector/email/OCR test was performed. Native apps, media/deepfake detection, full Google Docs/LMS/WordPress integrations, outgoing webhooks, credit top-ups, recursive site crawling, source-backed claim verification and proprietary detector training remain separate unfinished work. The earlier reference to opt-in typing replay is now partially addressed by coarse draft snapshots; full keystroke replay is not implemented.

## WordPress draft-review connector - 12 November 2026

An installable plugin now lives in `integrations/wordpress`, with package `output/humixai-wordpress.zip`. Post/page editor panels and Tools > HumixAi offer explicit plain-text import/review, six source languages, Detect/Humanize/Combo, consent-gated asynchronous jobs, bounded polling and copy-back of reviewed output. Six interface catalogs and Arabic/Urdu direction support are included. No automatic post replacement or publishing occurs.

Tokens are configured server-side per WordPress user; they are not exposed to browser JavaScript. AJAX requires an authenticated user with edit_posts plus a nonce. Job polling also requires a user-bound transient from a successful submission. HTTPS requests use WordPress's safe HTTP client, disable redirects, limit response bytes and send only validated fields. The existing HumixAi scopes, quotas and queue apply. Identical retries within the panel reuse the submission key.

See [WordPress installation and limitations](../integrations/wordpress/README.md). Isolated PHP HTTP-stub checks and two Node UI tests pass; live WordPress installation/editor compatibility is not claimed. This addresses the WordPress draft-review portion of the integrations roadmap. Rich editor replacement, Google Docs, LMS, outgoing webhooks and the other previously listed remaining work are still unfinished.

## Two detection options - 12 November 2026

The studio **Detection mode** selector controls the **Detect AI** button:

- Auto: uses GPTZero when its key is configured; otherwise runs local pattern analysis.
- GPTZero: existing provider-based authorship probabilities, external processing consent, queue and normal allowance. Requires a user-supplied key in Admin > AI providers > GPTZero or GPTZERO_API_KEY. No key was supplied/configured during this increment.
- Local patterns (no key): synchronous analysis on the HumixAi server, no external request, no queue worker or AI-provider allowance. It is not a trained AI detector and gives no authorship verdict/probability. At least 30 words and three sentences are needed for its sentence-regularity index: 100 / (1 + population standard deviation / mean sentence word count). Uniform human prose also scores highly. All six languages lack independent authorship evaluation.

Local results keep overall_score and all class probabilities null; the separate descriptive index is shown as /100, never AI %. No silent fallback replaces a failed GPTZero response. Humanize and Analyze & improve retain their existing provider workflows; the selector does not change their scans. Local patterns are sent to the HumixAi application server, not calculated entirely inside the browser. Local results are session reports and do not create a saved revision or queued job.

Browser POST /ai-tools/local-patterns uses the existing StudioRequest limits and studio throttle with CSRF. Scoped API POST /api/v1/local-patterns requires the detect scope. Both accept text and optional language. API key values must never be placed in public client assets. The existing admin provider screen stores credentials encrypted and exposes no stored key value.

Detection preview update: Detect AI now opens a separate right-panel Detection result tab and the left Evidence tab. Existing rewrites remain in Revision. Local evidence highlights sentences over 30 words, sentences at least 15 words and over 25% longer than the draft average, or a group of at least three sentences within max(1 word, 10% of mean) of the mean. Eligibility remains 30 words / three sentences. Each explanation explicitly disclaims AI authorship; sentence scores remain null. No flags is not proof of human writing. Clicking a highlight shows its explanation. Detection does not generate a rewrite. Interface catalogs include the explanations in all six languages.
