# Administration and six-language support

Updated 11 November 2026 (Asia/Dhaka).

## Start administration

1. Register a normal account, or create one directly with `php artisan humix:admin admin@humix.com --create`. The command asks for a name, hidden password (10+ characters), and confirmation. No default password is created.
2. On the application server, run `php artisan humix:admin your-existing-account@example.com`.
3. Sign in as that account and open `/admin`. The studio also shows a System Admin link to administrators.

The additive administration migration has been applied to this local checkout. Other deployments must run `php artisan migrate --force`.

| Area | Available controls |
| --- | --- |
| Overview | User, suspended-account, document and team counts; today's used/reserved words; job status counts and failed queue jobs |
| Users | Search/pagination; name and locale; daily quota override; suspension/reactivation; administrator roles |
| Access | Revoke a user's API tokens and public shares; suspended users cannot use existing workspace/API sessions or start queued provider work |
| Usage | Audited positive/negative corrections to existing usage days, with a required reason; reservations remain unchanged |
| Jobs | Metadata-only list; cancel queued work atomically and release its allowance; prune expired transient jobs |
| Settings | Registration, URL importing, default daily allowance, maximum batch size, text limit and job retention |
| Providers | GPTZero, Gemini and Walter key configuration; encrypted database storage; configured/source indicators; disable a provider |
| Audit | Paginated administrator actions, actor/target IDs and changed field names; no passwords, keys, drafts or raw adjustment reasons |

Administrators can manage ordinary users and jobs. Only super administrators can change system/provider settings or privileged users. Sensitive writes require the current password. The last active super administrator cannot be deleted/demoted/suspended; self-demotion and self-suspension are blocked. Public API bearer tokens do not grant administration access.

Quota overrides are separate from billed plan limits. Provider disable stores an encrypted empty override, so an environment key cannot silently re-enable the provider. Runtime settings refresh on HTTP requests and before queue jobs. Keep the application's `APP_KEY` with encrypted backups; encrypted provider credentials depend on that key. Stripe prices, billing credentials, mail transport and infrastructure configuration remain deployment configuration, not editable arbitrary config fields.

## Languages

English (`en`) is the default. Bangla (`bn`), Hindi (`hi`), Urdu (`ur`), Arabic (`ar`) and Nepali (`ne`) have checked-in catalogs, including studio/auth/share/admin labels and Laravel validation messages. The catalogs contain 867 matching keys with verified named placeholders. Urdu and Arabic use right-to-left layouts; submitted text uses its own direction. Translation generation was assisted by Gemini, and native-speaker editorial review is still recommended before a public release.

The interface selector saves the user's preference, or the anonymous visitor's session preference. It is separate from the writing-language selector: switching interface language never translates a draft. Unsaved writing continues to trigger the browser's navigation warning. Requests can use `X-Locale`; browser sessions can `PATCH /locale` with `{ "locale": "bn" }`. Public catalogs are available at `GET /localization/catalog?locale=bn`.

Unicode words, sentence boundaries, source offsets, numbers and protected negation support the six scripts/languages. Script hints cannot reliably distinguish Hindi from Nepali or Arabic from Urdu; choose the document language when known. Legacy Spanish/French/German/Portuguese API values remain accepted for compatibility.

GPTZero submissions send original text and expose actual returned class probabilities; no translation or locally fabricated percentage is used. [GPTZero's API reference](https://docs.gptzero.me/api-reference/) documents automatic language detection and a multilingual model. HumixAi has not independently evaluated language-specific accuracy, and reports explicitly say so. A missing/rejected provider response produces no authorship score. Existing saved provider reports retain their recorded feedback; changing the interface does not rewrite historical evidence.

## Operations and verification

Run the database queue worker and Laravel scheduler as described in README. `php artisan humix:status` reports effective provider availability without printing secrets. GPTZero is still unconfigured in this checkout; a super administrator can now supply its key under AI providers. Provider connectivity and quota are separate from a configured flag.

Use `python scripts/build-catalog.py` after adding interface strings. `php scripts/translate-catalog.php bn` is an optional developer utility that sends only public strings to the configured Gemini endpoint and can incur provider usage; it never runs during normal page requests. All deployed translations are served locally. The utility preserves placeholders and supports resuming after rate limits.

Validation: 102 PHP tests / 812 assertions; 21 Node tests, including full catalog key/placeholder parity; Blade compilation, route cache generation and Composer manifest validation. No live detector scan or payment was performed. This change does not claim visual browser QA, native-speaker linguistic certification, or a trained proprietary detector.
