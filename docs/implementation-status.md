# HumixAi implementation status

Updated 10 November 2026 (Asia/Dhaka).

The blueprint includes an initial text product plus a longer-term expansion roadmap. The initial studio and the platform modules below have application code, routes and functional tests. External credentials, provider accounts and publication are separate setup steps.

| Blueprint area | Implemented in this checkout | External setup or remaining boundary |
| --- | --- | --- |
| Detect / Humanize / Analyze & Improve | Real GPTZero adapter, Gemini editing, protected text, exact revisions, recheck, partial failures and comparable scores | Set `GPTZERO_API_KEY`; retain a valid Gemini key/model. No detector accuracy benchmark was run. |
| Sentence review and writing quality | Provider sentence evidence, Unicode offsets, local English/Bengali writing signals | Highlights are signals; unsupported-language and short-text results are explicit. |
| Voice and selective editing | Session voice reference, protected terms, selected-passage rewriting and reviewable diffs | No persistent voice-sample profile or authorship guarantee. |
| Meaning and guidelines | Exact preservation checks plus separate assisted meaning/grammar/custom-guideline review | Model feedback does not independently verify facts or complete semantic equivalence. |
| Plagiarism and sources | Walter plagiarism API and GPTZero bibliography evidence adapters | Walter key/appropriate scope required. Similarity is separate from AI probability. No fabricated source matches. |
| Import / export | TXT, DOCX, text PDF with Poppler; public URL preview; TXT/JSON/DOCX/report exports and browser PDF printing | No OCR, authenticated-page extraction or headless JavaScript rendering. |
| Accounts / history / privacy | Session authentication, hashed passwords/tokens, ownership, revisions, history controls, deletion and expiring shares | Email recovery/verification services are not configured. Provider retention terms must be checked for the account used. |
| Scale / usage | Database jobs, actual stages, idempotency, atomic reservations, expiry, batch processing and pruning | Run queue worker and scheduler. No implicit provider retries or recurring website crawler. |
| Team review | Owner/editor/reviewer roles, link invitations, selected shared drafts and comments | Invitations are copied/shared by the owner; no email service sends messages. |
| Plans / billing | Optional hosted Stripe checkout, customer portal and signed subscription synchronization | Disabled by default. Configure Stripe products/prices, keys, portal and webhooks; test the account configuration before selling. |
| API / distribution | Scoped HTTP API, browser-extension source and MCP bridge source | Manual extension/client setup. Neither was installed, marketplace-published or connected to an external account. |
| Languages | Unicode-friendly local analysis and editing; Bengali rewriting labelled experimental | Evaluate Bengali detection and mixed-language quality before enabling accuracy claims. |
| Process evidence | Saved document revisions and exact scan bindings | No keystroke/video replay or proof of human authorship is claimed. |
| Future roadmap | Provider/service boundaries leave room for more modules | Proprietary trained detector, media/deepfake detection, native mobile clients, full Google Docs/LMS/WordPress integrations and scheduled whole-site scanning remain separate roadmap projects. They are not disguised by placeholder results. |

## Validation boundary

Automated tests cover provider normalization/failures, preservation, selected text, quotas, tenant isolation, reports, authentication, imports, teams, queue lifecycle, privacy races and optional billing. HTTP smoke verification also exercised a real local web process and queue worker with disposable test data. No live paid-provider requests or real payment transactions were made.

The browser-control host was unavailable, and the native fallback stopped because it could not establish the current URL with enough confidence. Visual desktop/mobile QA could not be completed. Source syntax, Blade rendering, local asset responses and HTTP behavior were checked without claiming a visual inspection.
