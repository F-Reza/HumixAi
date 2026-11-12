# HumixAi — কী শেষ, কী আংশিক, কী বাকি

**রিপোর্টের তারিখ:** ১২ নভেম্বর ২০২৬, Asia/Dhaka  
**ভিত্তি:** Product Blueprint, বর্তমান implementation, verification notes এবং এই রিপোর্ট তৈরির সময়কার `php artisan humix:status`।

> মূল writing studio ও platform-এর বড় অংশ implement হয়েছে। কিন্তু সম্পূর্ণ Blueprint এবং production launch-এর কাজ শেষ হয়নি। “Implemented” মানে code/workflow আছে; সব external service বাস্তবে চালু বা independently evaluated—এমন নয়। নির্ভরযোগ্য weighted scope ছাড়া কোনো completion percentage দেওয়া হচ্ছে না।

## ১. তিনটি প্রধান button-এর বর্তমান অবস্থা

| Function | কী কাজ করে | কী সীমাবদ্ধতা আছে |
| --- | --- | --- |
| **Detect AI — Local** | Key ছাড়া server-side writing-pattern analysis; ডান পাশে Detection result, বামে sentence evidence ও প্রযোজ্য highlight | এটি trained AI detector নয়। ০–১০০ index বাক্যের দৈর্ঘ্যের সমতা বোঝায়; AI-written percentage বা human/AI verdict নয় |
| **Detect AI — GPTZero** | API integration, provider probability, sentence evidence, coverage, validation ও failure handling আছে | GPTZero key এখনো configured নেই; live scan পরীক্ষা হয়নি। বর্তমান minimum ৮০ words |
| **Humanize** | Full/selected-text rewrite, tone/strength/length, voice reference, protected-content checks, ডান পাশে revision ও diff | Generic passage দিয়ে live test সফল। Model কোনো protected content বদলালে output প্রত্যাখ্যাত হতে পারে; সব input সফল হবে এমন নিশ্চয়তা নেই |
| **Analyze & improve** | Original scan → rewrite → protection check → revised scan → comparison workflow | GPTZero ছাড়া rewrite পাওয়া যায়, কিন্তু scans unavailable/insufficient হলে **PARTIAL** দেখায়। **Local mode এখনো এই Combo workflow-এ যুক্ত নয়** |

### বর্তমান screenshot-এর PARTIAL-এর অর্থ

Rewrite সফল হলেও দুইটি authorship scan সম্পন্ন না হলে score comparison হয় না। এটি rewrite হারিয়ে যাওয়ার error নয়। Key না থাকা এবং ৮০ words-এর কম input—দুটিই scan বন্ধ করতে পারে। Original ও revised text আলাদাভাবে minimum পূরণ করতে হয়।

## ২. Implemented কাজ

| বিভাগ | যা তৈরি হয়েছে |
| --- | --- |
| Writing studio | Dual editor, settings, word count, selection rewrite, revision/diff, copy, use revision, detection preview |
| Detection UI | Auto/GPTZero/Local selector; evidence tab, sentence explanation, stale-result indication; local ও provider score আলাদা |
| Preservation | Protected quotation/citation/number/term/negation checks; invalid rewrite-এ original অক্ষত রাখা |
| Voice | Session voice reference; consent-based encrypted personal/brand profiles ও style preferences |
| Quality review | Local readability/writing signals; assisted grammar, meaning ও custom-guideline review |
| Documents | Save, owned history/revisions, restore, deletion, history preference, expiring share links |
| Import/export | TXT, DOCX, supported text PDF, public URL preview; TXT/JSON/DOCX/report export ও browser PDF printing |
| Accounts | Registration/login/logout, ownership, password reset ও signed email verification workflow, account deletion |
| Usage/queue | Queued jobs, stage status, reservations/settlement, idempotency, bounded processing, batch review ও cleanup commands |
| Teams | Owner/editor/reviewer roles, invitations, shared documents ও comments |
| Admin backend | `/admin`, roles, suspension, quota controls, token/share revocation, job controls, runtime settings, encrypted provider keys ও audit trail |
| Languages | Primary English, Bangla, Hindi, Urdu, Arabic, Nepali; interface preference, validation, RTL এবং Unicode handling |
| Website tools | Consented scheduled page checks, pause/resume/delete, bounded sitemap preview, selected-page scheduling ও JSON reports |
| Writing replay | Opt-in encrypted snapshots, slider/play/pause, event-count report ও export; এটি full keystroke replay নয় |
| Developer access | Scoped HTTP API, browser-extension source, MCP bridge |
| Evaluation tooling | Offline labelled-prediction evaluator; language/domain slices, confusion matrix, precision/recall, Brier/calibration metrics |

### সাম্প্রতিক fixes

- `Keep length`-এর `preserve`/`similar` mismatch ঠিক হয়েছে।
- Completed rewrite পুনরায় document খুললে ফাঁকা হয়ে যাওয়ার সমস্যা ঠিক হয়েছে।
- নতুন document-এ পুরোনো detection preview থেকে যাওয়ার সমস্যা ঠিক হয়েছে।
- Right-panel Detection result এবং left-panel Evidence tab যুক্ত হয়েছে।
- Queue processing indicator, compact header, language-selector alignment ও stylesheet/script versioning যুক্ত হয়েছে।
- Working Gemini model হিসেবে `gemini-3.1-flash-lite` configured; protected-marker context উন্নত করা হয়েছে।

## ৩. Code আছে, কিন্তু activation বা live validation বাকি

| Module | বর্তমান অবস্থা | বাকি কাজ |
| --- | --- | --- |
| GPTZero | Adapter ও mocked tests আছে; runtime-এ not configured | নিজের API key Admin → AI providers অথবা `GPTZERO_API_KEY`-এ বসানো; live scan, quota ও sentence result যাচাই |
| SMTP | Recovery/verification workflow implement হয়েছে; email not configured | SMTP ও `APP_URL` setup, account email enable এবং নিজের mailbox দিয়ে delivery test |
| OCR | Local PNG/JPEG OCR support আছে; runtime-এ কোনো language pack পাওয়া যায়নি | Tesseract, প্রয়োজনীয় ছয় language pack, path/configuration ও real-image test |
| Walter similarity | API adapter আছে | Provider account/key/scope ও live source-match test; বর্তমান credential status এই রিপোর্টে পুনরায় যাচাই করা হয়নি |
| Stripe subscription | Hosted checkout, portal ও signed synchronization code আছে | Products/prices/keys/webhook configuration; sandbox payment lifecycle test। Real transaction পরীক্ষা হয়নি |
| WordPress | Installable plugin ও ZIP তৈরি; import/review, consent, API jobs, six-language UI, manual copy-back | Actual WordPress activation, Classic/Block Editor, theme/plugin compatibility ও end-to-end provider test |
| Extension/MCP | Source ও automated client tests আছে | User environment-এ install/connect এবং live workflow verification; marketplace publication হয়নি |
| Scheduled checks | Scheduler/jobs/sitemap workflow implement হয়েছে | Production scheduler/worker supervision ও consented live page/sitemap/provider test |
| Six-language UI | ছয় catalog-এ ১,০৩৯টি matching key আছে | Native-speaker review, layout/RTL visual QA এবং language-specific rewrite/detector evaluation |
| Benchmark runner | Offline metrics tool ও synthetic tests আছে | Licensed/consented independently labelled dataset, real evaluation ও results publication |

**Credential note:** কোনো secret এই রিপোর্টে রাখা হয়নি। GPTZero key সংগ্রহ বা তৈরি করা হয়নি; user/provider account থেকে key দিতে হবে।

## ৪. অসম্পূর্ণ functionality ও roadmap

### আগে করা উচিত

- [ ] **Combo-তে detection mode এক করা:** Local/Auto/GPTZero selection অনুযায়ী before/after analysis; local metric-কে AI probability না বলা; usage, stages ও report semantics মিলানো।
- [ ] **Key setup ও live GPTZero verification:** short-text, unavailable, quota এবং actual successful response test।
- [ ] **Production queue/scheduler:** persistent supervised worker, scheduled cleanup, failure monitoring। সাম্প্রতিক local worker দুই ঘণ্টার temporary test worker—production service নয়।
- [ ] **Browser-based end-to-end QA:** তিন button, import/export, document reopen, stale results, mobile, RTL ও accessibility। Automated DOM tests visual browser QA-এর বিকল্প নয়।
- [ ] **SMTP/OCR activation** এবং প্রয়োজন অনুযায়ী billing/similarity provider validation।
- [ ] **Release operations:** deployment configuration, HTTPS, backups/restore exercise, monitoring এবং production load testing সম্পন্ন ও নথিভুক্ত করা।

### Blueprint expansion

- [ ] Proprietary trained/calibrated AI detector ও independent multilingual benchmarks।
- [ ] Optional second-opinion detector, long-document coverage এবং paraphrase/mixed-content evaluation।
- [ ] Source-backed fact/hallucination verification ও আরও গভীর citation/source consistency।
- [ ] Credit top-ups এবং বিস্তারিত team analytics।
- [ ] Outgoing webhooks ও dedicated Zapier integration।
- [ ] Full Google Docs এবং LMS integrations।
- [ ] WordPress rich-editor replacement/আরও গভীর workflow; বর্তমান connector manual copy-back পর্যন্ত।
- [ ] Automatic recursive whole-site crawling এবং compressed/recursive sitemap support।
- [ ] Scanned multipage PDF rasterization/OCR; authenticated বা JavaScript-rendered page import।
- [ ] Full keystroke replay; বর্তমান coarse snapshots-কে typing proof হিসেবে ব্যবহার করা যাবে না।
- [ ] Native Android/iOS applications।
- [ ] Image/voice/video/deepfake detection, image plagiarism এবং moderation modules।

## ৫. পরীক্ষার প্রমাণ ও সীমা

এগুলো সর্বশেষ **recorded runs**; শুধু এই রিপোর্ট তৈরির জন্য পুরো suite নতুন করে চালানো হয়নি।

| Verification | Recorded result |
| --- | --- |
| Full PHP suite, শেষ prompt refinement-এর আগে | **143 tests / 1,055 assertions passed** |
| Prompt refinement-এর পর focused humanizer suite | **17 tests / 54 assertions passed** |
| Combined Node suites | **42 tests passed** |
| Offline evaluator | **4 Python tests passed**; synthetic fixtures |
| WordPress client | Isolated PHP HTTP-stub contract checks passed; live WordPress নয় |
| Live Gemini | Generic test passage-এ Humanize ও Combo rewrite/preservation সফল; কিছু আগের generated output protection check-এ প্রত্যাখ্যাত হয়েছে |
| Local HTTP | Corrected template/assets serve হওয়ার পরীক্ষা হয়েছে |

**এখনো প্রমাণিত নয়:** live GPTZero operation/accuracy, real payment lifecycle, SMTP delivery, real OCR, live website scanning, actual WordPress compatibility, comprehensive visual/mobile QA এবং independent language quality।

## ৬. পরবর্তী কাজের প্রস্তাবিত ক্রম

1. Combo-তে Local/Auto/GPTZero আচরণ consistent করা।
2. GPTZero key setup ও live three-button test সম্পন্ন করা।
3. Persistent worker/scheduler এবং browser QA শেষ করা।
4. SMTP, OCR ও প্রয়োজনীয় paid integrations activate/test করা।
5. Production release checklist এবং language-quality review শেষ করা।
6. তারপর webhooks, top-ups, Google Docs/LMS, mobile ও proprietary detector research।

## Reference documents

- [Product Blueprint](humixai-product-blueprint.md)
- [Implementation status](implementation-status.md) — পুরোনো summary-এর পরে increment notes আছে; latest অবস্থার জন্য এই report ও verification notes একসঙ্গে দেখো।
- [Blueprint expansion](blueprint-expansion.md)
- [Verification history](verification.md)
- [Admin and localization](admin-and-localization.md)
- [Detector evaluation](detector-evaluation.md)
- [WordPress setup](../integrations/wordpress/README.md)
- [WordPress ZIP](../output/humixai-wordpress.zip)
