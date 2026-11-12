# Implementation verification

10 November 2026 (Asia/Dhaka).

## Automated checks

- PHP functional/unit suite: **90 tests, 666 assertions passed**.
- Browser-extension/MCP client checks: **13 Node tests passed**.
- Frontend handler regressions: **6 Node tests passed**, executing the shipped JavaScript in a small DOM harness without a browser or network. Together with the distribution clients, **19 Node tests passed**.
- JavaScript entry points passed syntax checks.
- Blade templates compiled, Laravel routes cached and cleared successfully, and Composer manifest/lock validation passed.
- OpenAPI JSON parsed and **216 internal references** resolved. No external OpenAPI validator was installed.
- Additive database migrations through `000500` were applied to the local application database. No database reset or destructive migration was run.

The frontend regressions cover preserving an existing revision after a failed rewrite, preventing edits from being overwritten while a job runs, preventing history-off sharing/DOCX export from silently using an older saved draft, unavailable similarity checks, URL extraction previews and independently available subscription management.

## Local HTTP smoke test

A separate disposable SQLite database was used with an actual local Laravel HTTP server and a real database queue worker.

| Check | Observed result |
| --- | --- |
| Homepage | HTTP 200, HumixAi branding and local assets |
| Registration | Successful session-based account creation |
| Document creation and edit | Saved; edited original persisted independently of revision |
| Local writing review | Successful, authorship score remained null |
| Shared report | HTTP 200 |
| Detection job without a usable authorship scan | Honest partial result |
| Allowance after unavailable scan | Reserved amount returned to zero |

The disposable smoke-test database remained separate from the application database and was removed during teardown. The owned preview server was stopped.

## Limits

Provider, payment and URL-import tests used controlled fakes; no paid AI scans, real Stripe transactions or public URL fetches were performed. These results verify application behavior, not detector accuracy or a provider subscription's connectivity/quota.

The local runtime reports Gemini configured and GPTZero not configured. A valid `GPTZERO_API_KEY` is required to enable live percentage detection. Optional Walter/Stripe modules require their own configuration.

The browser-control host was unavailable. A native fallback then stopped because it could not reliably establish the current browser URL. No further UI automation was attempted after that stop, and no screenshot-based desktop/mobile visual QA is claimed.

## 11 November 2026 ? administration and localization

- PHP: **102 tests, 812 assertions**, all passing. Added permission, suspension, provider-secret, settings, quota-adjustment, last-admin, queued-cancellation and six-language provider/text tests.
- Node: **21 tests**, all passing, including all six catalogs' exact 867-key parity and placeholder preservation.
- Blade templates compile and cached PHP parses; route cache generates; Composer manifest validates.
- Additive admin migration applied to local SQLite; no data reset or default admin created.
- Public UI translations generated with Gemini; user documents were not included. No live GPTZero scan or payment was performed. Visual browser and native-speaker reviews remain unperformed.

## Blueprint expansion verification ? 11 November 2026

**128 PHP tests / 953 assertions and 24 Node tests pass.** Account recovery/verification, encrypted voice profiles, scheduled page checks and optional OCR have focused functional coverage. Six language catalogs now contain 939 matching keys with verified placeholders. Migrations through `000800` are applied locally. No live email, detector scan, public-page crawl or payment was performed. OCR is unavailable on this machine until its engine/packs are installed. See [blueprint expansion](blueprint-expansion.md).

## Sitemap and publisher reports

Full PHP suite: **135 tests / 993 assertions passed**. After refining native-form redirects, **7 focused sitemap tests / 42 assertions** passed. Node suite: **24 tests passed**, with 959-key catalog parity across all six languages. Coverage includes XML entity rejection, same-origin filtering, private redirect prevention, index non-recursion, URL caps, consent, preview ownership/expiry, atomic capacity checks and owner-only report export. No live sitemap or detector service was called.

Latest writing-record increment: full PHP suite 139 tests / 1,038 assertions; Node suites 26 tests; offline evaluator 4 Python tests. All passed. Blade cache and route cache compiled; compiled Blade PHP syntax checked. Additive migration 000900 applied. Six catalogs have 1,006 matching keys/placeholders. These are automated checks; no live detector, SMTP, OCR or visual browser verification was performed.

12 November 2026 WordPress increment: PHP plugin/client syntax checks and isolated HTTP-stub contract checks passed. The combined Node suites passed 28 tests, including consent, retry idempotency and safe output for the WordPress UI. All six application catalogs contain 1,026 matching keys/placeholders. The installable ZIP was checked for valid entries and six matching bundled catalogs. No live WordPress activation, actual editor integration or provider scan was performed. The application PHP backend was not changed in this increment; the prior 139-test PHP run is not claimed as a new run.

12 November 2026 keep-length fix: the studio option now uses the API's canonical `similar` value, and legacy `preserve` values normalize before document saving and settings restoration. This fixes `settings.length is invalid` blocking all three AI actions at the save step. The studio script URL now includes a file version to avoid stale browser cache; compiled views were cleared. All 34 combined Node tests pass, including all three action save/job payloads, legacy normalization and consent continuation. Local HTTP returned 200 and served the corrected option and versioned script. GPTZero remains unconfigured; the configured rewriter was not connectivity-tested. Process inspection was denied, so queue-worker availability was not confirmed.

12 November 2026 provider/layout repair: configured model gemini-3.8-flash metadata lookup succeeded but the generic generation request failed. gemini-3.1-flash-lite generated successfully, so local GEMINI_MODEL and code/example defaults now use it. No user draft was used in diagnostics. A second live generic full humanizer test exposed an invented protection marker; the prompt now describes markers only when source markers exist. The corrected live pipeline produced output and passed exact protection checks. Sixteen focused humanizer tests / 51 assertions and 34 Node tests passed. Local test queue worker launched hidden with a two-hour maximum lifetime. GPTZero is still unconfigured.

Studio locale/admin controls now sit inside the topbar instead of creating separate rows above it; top padding and heading size reduced, responsive wrapping retained, stylesheet URL versioned. Local HTTP confirms the new header and stylesheet. No visual browser inspection is claimed.

## Three-action verification - 12 November 2026

Full PHP suite: 143 tests / 1,055 assertions passed. Combined Node suites: 42 tests passed. Added explicit tests for GPTZero report/highlight rendering using mocked provider data, Combo partial results without invented probabilities, and clearing previous detection reports on new-document creation. Fixed the stale right-panel detection report during reset.

Live checks use only a generic diagnostic passage, never user documents. Combo successfully returned a revision and passed preservation checks; both GPTZero scans returned unavailable because no detector key is configured, so scores and comparison stayed absent. One earlier live generation was rejected for changing protected wording; this remains a deliberate protection failure, not a successful rewrite. Live GPTZero accuracy/connectivity has not been verified. Local detection is pattern analysis, not an authorship classifier. No visual browser QA is claimed for this verification run.

Follow-up: repeated generic Humanize output exposed an intermittent protected-word duplication/removal. The rewrite prompt now supplies the existing marker-to-source dictionary as meaning-only context, while requiring markers and surrounding spacing to remain intact. Exact restoration and duplicate/number rejection are unchanged. The same live Humanize passage then succeeded with preservation passed. Focused updated humanizer suite: 17 tests / 54 assertions passed. Worker restart requested to load this prompt change. A successful live sample is not a guarantee that every generated draft will pass preservation.
