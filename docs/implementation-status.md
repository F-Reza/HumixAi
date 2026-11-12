# HumixAi implementation status

Updated 11 November 2026 (Asia/Dhaka).

The blueprint includes an initial text product plus a longer-term expansion roadmap. The initial studio and the platform modules below have application code, routes and functional tests. External credentials, provider accounts and publication are separate setup steps.

| Blueprint area | Implemented in this checkout | External setup or remaining boundary |
| --- | --- | --- |
| Detect / Humanize / Analyze & Improve | Real GPTZero adapter, Gemini editing, protected text, exact revisions, recheck, partial failures and comparable scores | Set `GPTZERO_API_KEY`; retain a valid Gemini key/model. No detector accuracy benchmark was run. |
| Sentence review and writing quality | Provider sentence evidence, Unicode offsets and six-language local writing signals | Highlights are signals; unsupported-language and short-text results are explicit. |
| Voice and selective editing | Session voice reference plus opt-in encrypted personal/brand profiles, audience/style preferences, deletion, selected-passage rewriting and reviewable diffs | Stored samples require explicit consent; no authorship guarantee. |
| Meaning and guidelines | Exact preservation checks plus separate assisted meaning/grammar/custom-guideline review | Model feedback does not independently verify facts or complete semantic equivalence. |
| Plagiarism and sources | Walter plagiarism API and GPTZero bibliography evidence adapters | Walter key/appropriate scope required. Similarity is separate from AI probability. No fabricated source matches. |
| Import / export | TXT, DOCX, text PDF with Poppler; public URL preview; optional local PNG/JPEG OCR; TXT/JSON/DOCX/report exports and browser PDF printing | OCR requires Tesseract and selected language packs. Scanned PDF rasterization, authenticated pages and JavaScript rendering remain absent. |
| Accounts / history / privacy | Session authentication, hashed passwords/tokens, ownership, revisions, history controls, deletion, expiring shares, reset links and signed email verification | SMTP must be configured for recovery/verification delivery. Provider retention terms must be checked for the account used. |
| Scale / usage | Database jobs, stages, idempotency, reservations, expiry, batch and pruning; consented daily/weekly public-page checks with pause/delete and latest report | Run queue worker and scheduler. Bounded XML sitemap discovery and selected-page scheduling are available. No automatic recursive whole-site crawler. |
| Team review | Owner/editor/reviewer roles, link invitations, selected shared drafts and comments | Invitations are copied/shared by the owner; no email service sends messages. |
| Plans / billing | Optional hosted Stripe checkout, customer portal and signed subscription synchronization | Disabled by default. Configure Stripe products/prices, keys, portal and webhooks; test the account configuration before selling. |
| API / distribution | Scoped HTTP API, browser-extension source and MCP bridge source | Manual extension/client setup. Neither was installed, marketplace-published or connected to an external account. |
| Languages | English default plus Bangla, Hindi, Urdu, Arabic and Nepali; 959-key catalogs, RTL, saved interface preferences, localized validation/admin, Unicode analysis and six-language writing controls | Native-speaker editorial review and language-specific detector evaluation remain release work; provider probabilities are not proof of authorship. |
| System administration | Protected `/admin`; user/role/suspension/quota management, usage corrections, token/share revocation, queued-job cancellation, bounded runtime settings, encrypted provider keys and audit trail | Promote an existing account with `php artisan humix:admin email`. GPTZero still needs a key. Infrastructure, Stripe and mail transport configuration stay server-managed. |
| Process evidence | Saved document revisions and exact scan bindings | No keystroke/video replay or proof of human authorship is claimed. |
| Future roadmap | Provider/service boundaries leave room for more modules | Proprietary trained detector, media/deepfake detection, native mobile clients, full Google Docs/LMS/WordPress integrations and recursive whole-site crawling, typing replay and credit top-ups remain separate roadmap projects. They are not disguised by placeholder results. |

## Validation boundary

Automated tests cover provider normalization/failures, preservation, selected text, quotas, tenant isolation, reports, authentication, imports, teams, queue lifecycle, privacy races and optional billing. HTTP smoke verification also exercised a real local web process and queue worker with disposable test data. No live paid-provider requests or real payment transactions were made.

The browser-control host was unavailable, and the native fallback stopped because it could not establish the current URL with enough confidence. Visual desktop/mobile QA could not be completed. Source syntax, Blade rendering, local asset responses and HTTP behavior were checked without claiming a visual inspection.

See [administration and localization](admin-and-localization.md) for setup, permissions, language behavior and verification.

The new account, voice, website-check and OCR workflows are documented in [blueprint expansion](blueprint-expansion.md). Their optional services are not enabled merely by applying migrations.

Publisher expansion: `/sitemap` previews up to 200 same-origin URLs, supports manually selected child sitemap indexes, and schedules selected pages under the existing 10-page account cap. `/website-checks/export` downloads individual latest reports and state counts, without averaging detector scores.

Writing replay: `/writing-records` offers opt-in encrypted draft snapshots, slider replay, event-count reports and JSON export. These are client-reported history, not proof of authorship. See [blueprint expansion](blueprint-expansion.md) for limits and [detector evaluation](detector-evaluation.md) for the offline labelled-prediction evaluator. Actual independent benchmarks and full keystroke replay remain outstanding.

WordPress draft-review connector: [installation and security model](../integrations/wordpress/README.md). Installable ZIP at `output/humixai-wordpress.zip`, six interface languages, explicit consent, queued API operations and manual copy-back. Automated isolated client/UI checks pass; actual WordPress runtime and provider integration testing remain outstanding.
