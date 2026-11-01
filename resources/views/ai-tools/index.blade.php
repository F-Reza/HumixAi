<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>HumixAi — AI Detector & Humanizer</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        /* Sentence heatmap colours */
        .sentence-high{background:rgba(254,202,202,.8);border-bottom:2.5px solid #ef4444;padding:2px 4px;border-radius:4px;cursor:pointer;transition:all .2s}
        .sentence-high:hover{background:rgba(252,165,165,1);box-shadow:0 0 0 2px rgba(239,68,68,.35)}
        .sentence-medium{background:rgba(254,240,138,.8);border-bottom:2.5px solid #eab308;padding:2px 4px;border-radius:4px;cursor:pointer;transition:all .2s}
        .sentence-medium:hover{background:rgba(253,224,71,1);box-shadow:0 0 0 2px rgba(234,179,8,.35)}
        .sentence-low{background:rgba(187,247,208,.55);border-bottom:2px solid #22c55e;padding:2px 4px;border-radius:4px;cursor:pointer;transition:all .2s}
        .sentence-low:hover{background:rgba(134,239,172,.85);box-shadow:0 0 0 2px rgba(34,197,94,.3)}

        /* Radial/circular score gauge (Walter Writes style) */
        .gauge-ring{transition:stroke-dashoffset .9s cubic-bezier(.4,0,.2,1)}
        .score-counter{font-variant-numeric:tabular-nums}

        /* Animated score badge pulse */
        @keyframes scorePulse{0%,100%{transform:scale(1)}50%{transform:scale(1.04)}}
        .score-pulse{animation:scorePulse .6s ease-in-out}

        /* Skeleton loader */
        @keyframes shimmer{0%{background-position:-1000px 0}100%{background-position:1000px 0}}
        .skeleton{background:linear-gradient(90deg,#f1f5f9 25%,#e2e8f0 50%,#f1f5f9 75%);background-size:1000px 100%;animation:shimmer 2s infinite linear;border-radius:6px}

        /* GPTZero style perplexity meter bar */
        .metric-bar{height:6px;border-radius:9999px;background:#e2e8f0;overflow:hidden}
        .metric-bar-fill{height:100%;border-radius:9999px;transition:width .8s ease}

        /* Tooltip */
        [data-tooltip]:hover::after{content:attr(data-tooltip);position:absolute;bottom:calc(100% + 6px);left:50%;transform:translateX(-50%);background:#0f172a;color:#fff;font-size:11px;padding:4px 8px;border-radius:6px;white-space:nowrap;z-index:999;pointer-events:none}
        [data-tooltip]{position:relative}

        /* Copyleaks inline diff view */
        .diff-added{background:#dcfce7;border-radius:3px;padding:1px 2px}
        .diff-removed{background:#fee2e2;text-decoration:line-through;border-radius:3px;padding:1px 2px;opacity:.7}

        /* Tab underline active */
        .tab-active{border-bottom:2px solid #4f46e5;color:#4f46e5;font-weight:700}

        @media print{header,.no-print,#apiKeyModal,#toast{display:none!important}body{background:#fff!important;color:#000!important}}
    </style>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen flex flex-col font-sans antialiased">

<!-- ===== HEADER ===== -->
<header class="bg-white border-b border-slate-200 sticky top-0 z-30 shadow-sm no-print">
    <div class="max-w-7xl mx-auto px-4 h-16 flex items-center justify-between">
        <!-- Brand -->
        <div class="flex items-center space-x-3">
            <div class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-600 flex items-center justify-center text-white shadow-md">
                <i class="fa-solid fa-wand-magic-sparkles text-lg"></i>
            </div>
            <div>
                <div class="flex items-center gap-2">
                    <span class="font-extrabold text-slate-900 text-lg leading-none">HumixAi</span>
                    <span class="px-1.5 py-0.5 text-[10px] font-bold bg-indigo-100 text-indigo-700 rounded uppercase tracking-wider">Beta</span>
                </div>
                <p class="text-[11px] text-slate-500 leading-none mt-0.5">Sentence Risk • Proofademic Citations • GPTZero Burstiness • 1-Pass Bypass</p>
            </div>
        </div>

        <!-- Status + Actions -->
        <div class="flex items-center gap-3">
            @if($isConfigured)
                <button onclick="openConfigModal()" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200 hover:bg-emerald-100 transition">
                    <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span>
                    Gemini Active · {{ $model }}
                    <i class="fa-solid fa-gear text-emerald-400 text-[10px]"></i>
                </button>
            @else
                <button onclick="openConfigModal()" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-amber-50 text-amber-800 border border-amber-300 hover:bg-amber-100 transition shadow-sm">
                    <span class="w-1.5 h-1.5 bg-amber-500 rounded-full"></span>
                    Heuristic Mode · Add Gemini Key
                    <i class="fa-solid fa-key text-amber-500 text-[10px]"></i>
                </button>
            @endif
            <button onclick="window.print()" class="hidden sm:flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium bg-slate-100 text-slate-600 hover:bg-slate-200 transition">
                <i class="fa-solid fa-file-export"></i> Export
            </button>
        </div>
    </div>
</header>

<!-- ===== MAIN ===== -->
<main class="flex-1 max-w-7xl w-full mx-auto p-4 sm:p-6 lg:p-8 space-y-5">

    <!-- Hero Banner with sample buttons -->
    <div class="bg-gradient-to-br from-slate-900 via-indigo-950 to-slate-900 rounded-2xl p-5 md:p-7 text-white shadow-2xl flex flex-col lg:flex-row items-start lg:items-center gap-6 no-print">
        <div class="space-y-3 flex-1">
            <div class="flex flex-wrap gap-2 text-[11px] font-semibold">
                <a href="https://walterwrites.ai/" target="_blank" class="flex items-center gap-1 px-2.5 py-1 bg-indigo-500/25 text-indigo-200 rounded-full border border-indigo-400/20 hover:bg-indigo-500/40 transition">🏆 Walter Writes</a>
                <a href="https://proofademic.ai/" target="_blank" class="flex items-center gap-1 px-2.5 py-1 bg-blue-500/25 text-blue-200 rounded-full border border-blue-400/20 hover:bg-blue-500/40 transition">📘 Proofademic</a>
                <a href="https://copyleaks.com/ai-detector" target="_blank" class="flex items-center gap-1 px-2.5 py-1 bg-yellow-500/20 text-yellow-200 rounded-full border border-yellow-400/20 hover:bg-yellow-500/35 transition">🟡 Copyleaks</a>
                <a href="https://gptzero.me/" target="_blank" class="flex items-center gap-1 px-2.5 py-1 bg-orange-500/20 text-orange-200 rounded-full border border-orange-400/20 hover:bg-orange-500/35 transition">🟠 GPTZero</a>
                <a href="https://originality.ai/" target="_blank" class="flex items-center gap-1 px-2.5 py-1 bg-sky-500/20 text-sky-200 rounded-full border border-sky-400/20 hover:bg-sky-500/35 transition">🔵 Originality.ai</a>
            </div>
            <div>
                <h1 class="text-2xl sm:text-3xl font-extrabold tracking-tight leading-tight">Detect AI % · Sentence Risk · 1-Pass Humanize</h1>
                <p class="text-slate-400 text-sm mt-1 max-w-2xl">Inspired by all 5 top detectors — with Proofademic citation guard, GPTZero perplexity metrics, Copyleaks sentence-level export, and Walter Writes one-step cleanup combo.</p>
            </div>
        </div>
        <div class="space-y-2 shrink-0">
            <p class="text-[11px] uppercase tracking-widest text-slate-500 font-bold">Load Sample Text</p>
            <div class="flex flex-wrap gap-1.5">
                <button onclick="loadSample('seo')" class="px-3 py-1.5 bg-white/10 hover:bg-white/20 text-white rounded-lg text-xs font-medium transition flex items-center gap-1"><i class="fa-solid fa-newspaper text-amber-300"></i> SEO Blog</button>
                <button onclick="loadSample('academic')" class="px-3 py-1.5 bg-white/10 hover:bg-white/20 text-white rounded-lg text-xs font-medium transition flex items-center gap-1"><i class="fa-solid fa-graduation-cap text-indigo-300"></i> Academic Essay</button>
                <button onclick="loadSample('business')" class="px-3 py-1.5 bg-white/10 hover:bg-white/20 text-white rounded-lg text-xs font-medium transition flex items-center gap-1"><i class="fa-solid fa-briefcase text-fuchsia-300"></i> Business Pitch</button>
                <button onclick="loadSample('human')" class="px-3 py-1.5 bg-white/10 hover:bg-white/20 text-white rounded-lg text-xs font-medium transition flex items-center gap-1"><i class="fa-solid fa-user-check text-emerald-300"></i> 100% Human</button>
            </div>
        </div>
    </div>

    <!-- ===== MULTI-DETECTOR BENCHMARK (shown after scan) ===== -->
    <div id="benchmarkBar" class="hidden bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-4 py-3 border-b border-slate-100 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <i class="fa-solid fa-ranking-star text-amber-500"></i>
                <span class="text-xs font-bold uppercase tracking-wider text-slate-700">Top 5 Detector Benchmark</span>
                <span class="text-[11px] text-slate-400">(Calibrated simulation)</span>
            </div>
            <button onclick="document.getElementById('benchmarkBar').classList.add('hidden')" class="text-slate-300 hover:text-slate-500 text-sm"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 divide-x divide-y sm:divide-y-0 divide-slate-100" id="benchmarkGrid"></div>
    </div>

    <!-- ===== MAIN DUAL PANEL ===== -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 items-start">

        <!-- ========== LEFT: INPUT + DETECTION ========== -->
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm flex flex-col">

            <!-- Panel Header -->
            <div class="px-4 py-3 border-b border-slate-100 flex items-center justify-between bg-slate-50/70 rounded-t-2xl">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-indigo-600 animate-pulse"></span>
                    <h3 class="font-semibold text-slate-800 text-sm">Source Text & Risk Heatmap</h3>
                </div>
                <div class="flex items-center gap-3">
                    <div class="text-[11px] text-slate-500 font-mono" id="charCount">0 words · 0 chars</div>
                    <!-- Readability pill (Originality.ai style) -->
                    <div id="readPill" class="hidden items-center gap-1 px-2 py-0.5 bg-slate-100 text-slate-600 rounded-full text-[10px] font-medium">
                        <i class="fa-regular fa-clock"></i> <span id="readTime">0</span>s read
                    </div>
                    <button onclick="clearAll()" class="text-slate-400 hover:text-red-500 transition text-xs px-1.5 py-1" title="Clear"><i class="fa-regular fa-trash-can"></i></button>
                </div>
            </div>

            <!-- Text Input / Heatmap Switch -->
            <div class="p-4 flex flex-col gap-3">
                <textarea id="inputText"
                    class="w-full h-72 p-4 rounded-xl border border-slate-200 focus:border-indigo-400 focus:ring-2 focus:ring-indigo-100 outline-none resize-y text-sm leading-relaxed text-slate-700 transition"
                    placeholder="Paste text here — minimum 15 characters. Works with essays, articles, blog posts, and academic papers (APA/MLA citations preserved in Academic mode)."></textarea>

                <!-- Heatmap view (hidden by default) -->
                <div id="heatmapContainer" class="hidden w-full min-h-[18rem] p-4 rounded-xl border border-slate-200 bg-slate-50/60 text-sm leading-[2] overflow-y-auto max-h-[320px]">
                    <div id="heatmapContent"></div>
                </div>
            </div>

            <!-- Sentence Inspector (Proofademic / Walter style inline card) -->
            <div id="inspector" class="hidden mx-4 mb-3 rounded-xl bg-slate-900 text-white p-4 text-xs space-y-2.5 border border-slate-800 shadow-xl">
                <div class="flex items-start justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <span id="inspRiskBadge" class="px-2 py-0.5 rounded-md font-bold text-[10px] uppercase">High Risk</span>
                        <span class="text-slate-300 font-semibold" id="inspScore">93% AI</span>
                    </div>
                    <button onclick="closeInspector()" class="text-slate-500 hover:text-white text-sm shrink-0"><i class="fa-solid fa-xmark"></i></button>
                </div>
                <p class="text-slate-300 italic bg-slate-800 rounded-lg px-3 py-2 text-[12px] leading-relaxed" id="inspText">"..."</p>
                <!-- Signals chips (GPTZero style) -->
                <div id="inspSignals" class="flex flex-wrap gap-1.5"></div>
                <!-- Reason & suggestion -->
                <div class="text-amber-400 font-mono text-[11px]" id="inspReason"></div>
                <div class="border-t border-slate-800 pt-2.5 flex items-center justify-between gap-3">
                    <div class="text-[11px] text-emerald-400 flex-1 min-w-0">
                        <span class="text-slate-500">Suggested rewrite: </span>
                        <span id="inspSuggestion" class="italic truncate block"></span>
                    </div>
                    <button onclick="applySuggestion()" class="shrink-0 px-3 py-1.5 bg-indigo-600 hover:bg-indigo-500 rounded-lg text-[11px] font-bold text-white transition">
                        <i class="fa-solid fa-check mr-1"></i> Apply
                    </button>
                </div>
            </div>

            <!-- GPTZero style Perplexity + Burstiness metrics (shown after scan) -->
            <div id="nlpMetrics" class="hidden mx-4 mb-3 grid grid-cols-2 gap-3 text-[11px]">
                <div class="bg-orange-50 border border-orange-100 rounded-xl p-3 space-y-1.5">
                    <div class="flex items-center justify-between">
                        <span class="font-bold text-orange-700 flex items-center gap-1"><i class="fa-solid fa-chart-simple text-orange-500"></i> Burstiness</span>
                        <span id="burstVal" class="font-black text-orange-800 font-mono">--</span>
                    </div>
                    <div class="metric-bar"><div id="burstBar" class="metric-bar-fill bg-orange-400" style="width:0%"></div></div>
                    <p class="text-orange-600 text-[10px]" id="burstLabel">Sentence length variance</p>
                </div>
                <div class="bg-sky-50 border border-sky-100 rounded-xl p-3 space-y-1.5">
                    <div class="flex items-center justify-between">
                        <span class="font-bold text-sky-700 flex items-center gap-1"><i class="fa-solid fa-wave-square text-sky-500"></i> Flesch Score</span>
                        <span id="fleschVal" class="font-black text-sky-800 font-mono">--</span>
                    </div>
                    <div class="metric-bar"><div id="fleschBar" class="metric-bar-fill bg-sky-400" style="width:0%"></div></div>
                    <p class="text-sky-600 text-[10px]" id="fleschLabel">Reading ease level</p>
                </div>
            </div>

            <!-- Score Footer + Action Buttons -->
            <div class="px-4 pb-4 flex flex-col sm:flex-row items-center justify-between gap-4 border-t border-slate-100 pt-4">

                <!-- Walter Writes style circular gauge + verdict -->
                <div class="flex items-center gap-4">
                    <!-- SVG Radial Gauge -->
                    <div class="relative w-16 h-16 shrink-0">
                        <svg class="w-16 h-16 -rotate-90" viewBox="0 0 64 64">
                            <circle cx="32" cy="32" r="27" fill="none" stroke="#e2e8f0" stroke-width="7"/>
                            <circle id="gaugeArc" cx="32" cy="32" r="27" fill="none" stroke="#cbd5e1" stroke-width="7"
                                stroke-linecap="round" stroke-dasharray="169.6" stroke-dashoffset="169.6" class="gauge-ring"/>
                        </svg>
                        <div class="absolute inset-0 flex flex-col items-center justify-center">
                            <span id="gaugeNum" class="text-sm font-black text-slate-700 score-counter leading-none">--%</span>
                            <span class="text-[8px] text-slate-400 uppercase tracking-tight leading-none">AI</span>
                        </div>
                    </div>
                    <div>
                        <div id="verdictText" class="text-xs font-bold text-slate-700">Ready to Analyze</div>
                        <div class="flex items-center gap-2 mt-1 text-[11px] font-semibold">
                            <span class="text-red-600"><i class="fa-solid fa-circle text-[8px] mr-0.5"></i><span id="highCnt">0</span> High</span>
                            <span class="text-amber-600"><i class="fa-solid fa-circle text-[8px] mr-0.5"></i><span id="medCnt">0</span> Med</span>
                            <span class="text-emerald-600"><i class="fa-solid fa-circle text-[8px] mr-0.5"></i><span id="lowCnt">0</span> Human</span>
                        </div>
                        <div id="engBadge" class="hidden mt-1 px-1.5 py-0.5 bg-slate-100 text-slate-500 rounded text-[9px] font-mono w-fit"></div>
                    </div>
                </div>

                <!-- Buttons -->
                <div class="flex flex-wrap gap-2 justify-end">
                    <button id="toggleHeatmapBtn" onclick="toggleHeatmap()" class="hidden px-3 py-2 text-xs font-semibold text-slate-700 bg-white border border-slate-300 hover:bg-slate-50 rounded-xl transition shadow-sm">
                        <i class="fa-solid fa-highlighter mr-1 text-indigo-500"></i>
                        <span id="toggleHeatmapLabel">Heatmap</span>
                    </button>
                    <button id="detectBtn" onclick="runDetect()" class="px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-xl text-xs font-bold shadow-md shadow-indigo-100 transition flex items-center gap-1.5">
                        <i class="fa-solid fa-magnifying-glass-chart"></i> Detect AI
                    </button>
                    <button id="comboBtn" onclick="runCombo()" class="px-4 py-2.5 bg-gradient-to-r from-violet-600 to-fuchsia-600 hover:from-violet-700 hover:to-fuchsia-700 text-white rounded-xl text-xs font-bold shadow-md shadow-violet-100 transition flex items-center gap-1.5">
                        <i class="fa-solid fa-bolt text-amber-300"></i> 1-Step Combo
                    </button>
                </div>
            </div>
        </div>

        <!-- ========== RIGHT: HUMANIZER + OUTPUT ========== -->
        <div class="bg-white rounded-2xl border border-slate-200 shadow-sm flex flex-col">

            <!-- Panel Header -->
            <div class="px-4 py-3 border-b border-slate-100 flex flex-wrap items-center justify-between gap-2 bg-slate-50/70 rounded-t-2xl">
                <div class="flex items-center gap-2">
                    <span class="w-2 h-2 rounded-full bg-fuchsia-600"></span>
                    <h3 class="font-semibold text-slate-800 text-sm">Humanized & Verified Output</h3>
                </div>
                <!-- Mode Pills (Walter Writes style) -->
                <div class="flex p-1 bg-slate-100 rounded-xl gap-0.5 text-xs font-medium">
                    <button onclick="setMode('standard')" id="m-standard" class="mode-btn px-2.5 py-1.5 rounded-lg bg-white text-slate-800 shadow-sm transition" title="Walter Writes Style">Standard</button>
                    <button onclick="setMode('academic')" id="m-academic" class="mode-btn px-2.5 py-1.5 rounded-lg text-slate-600 hover:text-slate-900 transition" title="Proofademic / Turnitin Safe — Citations Protected">🎓 Academic</button>
                    <button onclick="setMode('casual')" id="m-casual" class="mode-btn px-2.5 py-1.5 rounded-lg text-slate-600 hover:text-slate-900 transition" title="Conversational blog/social cadence">Casual</button>
                    <button onclick="setMode('bypass')" id="m-bypass" class="mode-btn px-2.5 py-1.5 rounded-lg text-slate-600 hover:text-slate-900 transition" title="Aggressive stealth — max burstiness & perplexity">⚡ Bypass</button>
                </div>
            </div>

            <!-- Output Area -->
            <div class="p-4 flex-1 flex flex-col relative">
                <!-- Loading overlay -->
                <div id="loadingOverlay" class="hidden absolute inset-0 bg-white/95 backdrop-blur-sm z-20 flex flex-col items-center justify-center gap-3 p-6 text-center rounded-2xl">
                    <div class="w-12 h-12 border-4 border-indigo-100 border-t-indigo-600 rounded-full animate-spin"></div>
                    <p class="text-sm font-bold text-slate-700" id="loadMsg">Analysing text...</p>
                    <p class="text-xs text-slate-400 max-w-xs">Varying sentence length, stripping AI tropes, re-verifying against detectors...</p>
                </div>

                <!-- Output textarea (editable for user polish) -->
                <div id="outputBox"
                    class="w-full flex-1 min-h-[18rem] h-72 p-4 rounded-xl border border-slate-200 bg-slate-50/30 text-sm leading-relaxed text-slate-800 overflow-y-auto whitespace-pre-wrap select-text"
                    contenteditable="false">
                    <span class="text-slate-400 italic">Humanized output will appear here. Click <strong class="text-slate-600">"1-Step Combo"</strong> for the full detect + rewrite + re-verify pipeline — or "Detect AI" first then "Humanize".</span>
                </div>

                <!-- Copyleaks style inline sentence diff toggle (shown after humanize) -->
                <div id="diffToggleWrap" class="hidden mt-3 flex items-center justify-between text-xs">
                    <div class="flex items-center gap-2">
                        <span class="inline-block w-3 h-3 bg-green-200 border border-green-400 rounded-sm"></span><span class="text-slate-500">Added</span>
                        <span class="inline-block w-3 h-3 bg-red-200 border border-red-400 rounded-sm ml-2"></span><span class="text-slate-500">Removed</span>
                    </div>
                    <button onclick="toggleDiff()" class="text-indigo-600 hover:underline font-semibold flex items-center gap-1">
                        <i class="fa-solid fa-code-compare"></i> <span id="diffBtnLabel">Show Diff</span>
                    </button>
                </div>
            </div>

            <!-- Score comparison + copy row (Walter Writes / Copyleaks style) -->
            <div class="px-4 pb-4 border-t border-slate-100 pt-4 flex flex-col sm:flex-row items-center justify-between gap-3">
                <!-- Before → After badges -->
                <div class="flex items-center gap-2 text-xs font-bold flex-wrap">
                    <span id="beforeBadge" class="hidden px-2.5 py-1.5 rounded-xl bg-red-100 text-red-700 line-through">Before: 96%</span>
                    <i id="arrowIcon" class="hidden fa-solid fa-arrow-right text-slate-400"></i>
                    <span id="afterBadge" class="hidden px-2.5 py-1.5 rounded-xl bg-emerald-100 text-emerald-800">After: 8% AI</span>
                    <span id="dropBadge" class="hidden px-2 py-1 rounded-lg bg-indigo-100 text-indigo-700 text-[11px]">−88% drop</span>
                    <span id="engineBadge" class="hidden px-2 py-1 rounded-lg bg-slate-100 text-slate-500 text-[10px] font-mono">engine</span>
                </div>

                <!-- Action buttons -->
                <div class="flex gap-2 justify-end w-full sm:w-auto">
                    <button onclick="humanizeOnly()" class="px-3 py-2 text-xs font-semibold bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 rounded-xl shadow-sm transition flex items-center gap-1.5">
                        <i class="fa-solid fa-wand-sparkles text-indigo-500"></i> Humanize
                    </button>
                    <button onclick="copyOutput()" class="px-3 py-2 text-xs font-semibold bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 rounded-xl shadow-sm transition flex items-center gap-1.5">
                        <i class="fa-regular fa-copy text-slate-500"></i> Copy
                    </button>
                    <button onclick="downloadReport()" class="px-3 py-2 text-xs font-semibold bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 rounded-xl shadow-sm transition flex items-center gap-1.5">
                        <i class="fa-solid fa-download text-slate-500"></i> Export
                    </button>
                </div>
            </div>
        </div>

    </div>

    <!-- ===== Feature Strip: All 5 tools described ===== -->
    <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm no-print">
        <h3 class="text-xs font-bold uppercase tracking-widest text-slate-700 mb-4 flex items-center gap-2">
            <i class="fa-solid fa-trophy text-amber-500"></i> Inspired By — Top 5 AI Detectors
        </h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
            <a href="https://walterwrites.ai/" target="_blank" class="group p-3.5 rounded-xl bg-amber-50 border border-amber-100 hover:border-amber-300 hover:shadow-md transition space-y-1 block">
                <div class="text-amber-600 font-extrabold text-xs flex items-center justify-between">🏆 #1 Walter Writes<i class="fa-solid fa-arrow-up-right-from-square text-[9px] text-amber-400 group-hover:text-amber-600"></i></div>
                <div class="text-slate-700 font-semibold text-xs">Best All-Round Combo</div>
                <p class="text-[11px] text-slate-500">Detection built into humanizer — fix tone + flag issues in one pass</p>
            </a>
            <a href="https://proofademic.ai/" target="_blank" class="group p-3.5 rounded-xl bg-indigo-50 border border-indigo-100 hover:border-indigo-300 hover:shadow-md transition space-y-1 block">
                <div class="text-indigo-700 font-extrabold text-xs flex items-center justify-between">📘 #2 Proofademic<i class="fa-solid fa-arrow-up-right-from-square text-[9px] text-indigo-400 group-hover:text-indigo-600"></i></div>
                <div class="text-slate-700 font-semibold text-xs">Academic & Turnitin Safe</div>
                <p class="text-[11px] text-slate-500">Flags phrasing without rewriting your paper — APA/MLA citations protected</p>
            </a>
            <a href="https://copyleaks.com/ai-detector" target="_blank" class="group p-3.5 rounded-xl bg-yellow-50 border border-yellow-100 hover:border-yellow-300 hover:shadow-md transition space-y-1 block">
                <div class="text-yellow-700 font-extrabold text-xs flex items-center justify-between">🟡 #3 Copyleaks<i class="fa-solid fa-arrow-up-right-from-square text-[9px] text-yellow-400 group-hover:text-yellow-600"></i></div>
                <div class="text-slate-700 font-semibold text-xs">Best for Publishers</div>
                <p class="text-[11px] text-slate-500">Catches subtle GPT patterns, sentence-level scores, exportable reports</p>
            </a>
            <a href="https://gptzero.me/" target="_blank" class="group p-3.5 rounded-xl bg-orange-50 border border-orange-100 hover:border-orange-300 hover:shadow-md transition space-y-1 block">
                <div class="text-orange-700 font-extrabold text-xs flex items-center justify-between">🟠 #4 GPTZero<i class="fa-solid fa-arrow-up-right-from-square text-[9px] text-orange-400 group-hover:text-orange-600"></i></div>
                <div class="text-slate-700 font-semibold text-xs">Perplexity & Burstiness</div>
                <p class="text-[11px] text-slate-500">Spots uniform sentence cadence — useful for cross-check verification</p>
            </a>
            <a href="https://originality.ai/" target="_blank" class="group p-3.5 rounded-xl bg-sky-50 border border-sky-100 hover:border-sky-300 hover:shadow-md transition space-y-1 block">
                <div class="text-sky-700 font-extrabold text-xs flex items-center justify-between">🔵 #5 Originality.ai<i class="fa-solid fa-arrow-up-right-from-square text-[9px] text-sky-400 group-hover:text-sky-600"></i></div>
                <div class="text-slate-700 font-semibold text-xs">Short-Form Content</div>
                <p class="text-[11px] text-slate-500">Fast, simple first-pass scan — great for web copy & product descriptions</p>
            </a>
        </div>
    </div>

</main>

<!-- ===== GEMINI CONFIG MODAL ===== -->
<div id="apiKeyModal" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-sm flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-md w-full p-6 shadow-2xl border border-slate-200 space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <div class="flex items-center gap-2">
                <i class="fa-solid fa-key text-indigo-600"></i>
                <h3 class="font-bold text-slate-800 text-sm">Google Gemini API Settings</h3>
            </div>
            <button onclick="closeConfigModal()" class="text-slate-400 hover:text-slate-600 text-lg"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <p class="text-xs text-slate-500">Get your free API key from <a href="https://aistudio.google.com/apikey" target="_blank" class="text-indigo-600 underline font-semibold">Google AI Studio</a>. This enables deep semantic detection and genuine Turnitin bypass rewrites.</p>
        <div class="space-y-3">
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Gemini API Key</label>
                <input type="password" id="apiKeyInput" placeholder="AQ.Ab8R..." class="w-full p-3 rounded-xl border border-slate-300 text-xs focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 outline-none font-mono">
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Model</label>
                <select id="modelSelect" class="w-full p-3 rounded-xl border border-slate-300 text-xs focus:ring-2 focus:ring-indigo-100 focus:border-indigo-500 outline-none">
                    <option value="gemini-3.8-flash">gemini-3.8-flash — Fastest, Recommended</option>
                    <option value="gemini-3.5-flash">gemini-3.5-flash — Balanced</option>
                    <option value="gemini-flash-latest">gemini-flash-latest — Latest stable</option>
                </select>
            </div>
        </div>
        <div class="flex justify-end gap-2 pt-2 border-t border-slate-100">
            <button onclick="closeConfigModal()" class="px-3 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100 rounded-xl transition">Cancel</button>
            <button onclick="saveApiKey()" class="px-4 py-2 text-xs font-bold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl shadow transition">Save & Activate</button>
        </div>
    </div>
</div>

<!-- ===== TOAST ===== -->
<div id="toast" class="fixed bottom-6 right-6 z-50 translate-y-16 opacity-0 transition-all duration-300 bg-slate-900 text-white px-4 py-3 rounded-xl shadow-2xl text-xs flex items-center gap-3 max-w-xs">
    <i id="toastIcon" class="fa-solid fa-circle-check text-emerald-400 text-base shrink-0"></i>
    <span id="toastMsg" class="font-medium">Done.</span>
</div>

<script>
// ================================================================
//  STATE
// ================================================================
let mode = 'standard';
let heatmapOn = false;
let heatmapData = null;
let activeS = null;         // active inspected sentence
let humanizedRaw = '';
let originalRaw  = '';
let diffShown    = false;

const csrf = document.querySelector('meta[name="csrf-token"]').content;
const $  = id => document.getElementById(id);

// Samples (matching the 5 reference tools' demo content)
const SAMPLES = {
    seo:      `In today's fast-paced digital world, artificial intelligence plays a pivotal role in transforming the way businesses operate. Furthermore, it is crucial to delve into how machine learning algorithms are reshaping content strategies for modern marketers. In conclusion, the technological tapestry continues to evolve seamlessly, and companies must leverage these ever-evolving tools to stay competitive.`,
    academic: `According to Miller et al. (2022, p. 34), large language models have demonstrated a pivotal role in reshaping natural language processing research [1-3]. Furthermore, it is worth noting that these models exhibit a fundamental tendency to generate semantically coherent yet stylistically monotonous output. In conclusion, the evolving tapestry of AI-generated academic text necessitates robust detection frameworks that seamlessly integrate with institutional Turnitin workflows (Johnson & Smith, 2021).`,
    business: `Our holistic enterprise framework seamlessly integrates cutting-edge AI capabilities to deliver transformative value across all verticals. Furthermore, we leverage a multifaceted approach to drive sustainable growth, ensuring our clients remain at the forefront of their respective industries. In today's fast-paced digital world, it is paramount to have a robust, ever-evolving strategy that serves as a beacon of innovation.`,
    human:    `Last Tuesday I sat down with about four different AI detectors and ran the same paragraph through each one. The results were all over the place — two flagged it as mostly human, one said 71% AI, and the last one basically threw a coin. My takeaway? Don't rely on just one tool. Cross-reference at least two or three. And honestly, the manual read is still the best sanity check I've found.`
};

// ================================================================
//  WORD COUNT / CHAR COUNT
// ================================================================
$('inputText').addEventListener('input', updateMeta);
function updateMeta() {
    const t = $('inputText').value.trim();
    const words = t ? t.split(/\s+/).length : 0;
    $('charCount').textContent = `${words} words · ${t.length} chars`;
}

function loadSample(k) {
    $('inputText').value = SAMPLES[k] || '';
    updateMeta();
    if (k === 'academic') setMode('academic');
    else if (k === 'seo' || k === 'business') setMode('standard');
    toast(`${k.toUpperCase()} sample loaded!`);
}

// ================================================================
//  MODE
// ================================================================
function setMode(m) {
    mode = m;
    document.querySelectorAll('.mode-btn').forEach(b => {
        b.classList.remove('bg-white', 'text-slate-800', 'shadow-sm');
        b.classList.add('text-slate-600');
    });
    const btn = $(`m-${m}`);
    btn.classList.remove('text-slate-600');
    btn.classList.add('bg-white', 'text-slate-800', 'shadow-sm');
    toast(`Mode → ${m.toUpperCase()}`);
}

// ================================================================
//  CLEAR
// ================================================================
function clearAll() {
    $('inputText').value = '';
    updateMeta();
    ['heatmapContainer','inspector','nlpMetrics','benchmarkBar','toggleHeatmapBtn','readPill','diffToggleWrap'].forEach(id => $(id)?.classList.add('hidden'));
    $('inputText').classList.remove('hidden');
    heatmapOn = false;
    $('toggleHeatmapLabel').textContent = 'Heatmap';
    resetGauge();
    $('outputBox').innerHTML = '<span class="text-slate-400 italic">Humanized output will appear here. Click <strong class="text-slate-600">"1-Step Combo"</strong> for the full detect + rewrite + re-verify pipeline — or "Detect AI" first then "Humanize".</span>';
    ['beforeBadge','arrowIcon','afterBadge','dropBadge','engineBadge'].forEach(id => $(id).classList.add('hidden'));
    toast('Workspace cleared.');
}

function resetGauge() {
    animateGauge(0, '#94a3b8');
    $('gaugeNum').textContent = '--%';
    $('verdictText').textContent = 'Ready to Analyze';
    $('highCnt').textContent = $('medCnt').textContent = $('lowCnt').textContent = '0';
    $('engBadge').classList.add('hidden');
}

// ================================================================
//  GAUGE ANIMATION (Walter Writes style)
// ================================================================
function animateGauge(pct, color) {
    const circ = 169.6;
    const offset = circ - (circ * pct / 100);
    const arc = $('gaugeArc');
    arc.style.strokeDashoffset = offset;
    arc.style.stroke = color;
    $('gaugeNum').textContent = `${pct}%`;
    $('gaugeNum').classList.add('score-pulse');
    setTimeout(() => $('gaugeNum').classList.remove('score-pulse'), 700);
}

function gaugeColor(score) {
    if (score >= 70) return '#ef4444';
    if (score >= 35) return '#f59e0b';
    return '#22c55e';
}

// ================================================================
//  API CALLS
// ================================================================
async function runDetect() {
    const text = $('inputText').value.trim();
    if (text.length < 15) { toast('Enter at least 15 characters.', 'error'); return; }
    setLoading(true, 'Scanning sentence-by-sentence risk...');
    try {
        const r = await post("{{ route('ai-tools.detect') }}", { text });
        renderDetection(r.data);
        toast('Detection complete!');
    } catch(e) { toast(e.message, 'error'); }
    finally { setLoading(false); }
}

async function humanizeOnly() {
    const text = $('inputText').value.trim();
    if (text.length < 15) { toast('Enter at least 15 characters.', 'error'); return; }
    setLoading(true, `Humanizing in ${mode.toUpperCase()} mode...`);
    try {
        const r = await post("{{ route('ai-tools.humanize') }}", { text, mode });
        renderHumanized(r.data);
        toast('Humanization complete!');
    } catch(e) { toast(e.message, 'error'); }
    finally { setLoading(false); }
}

async function runCombo() {
    const text = $('inputText').value.trim();
    if (text.length < 15) { toast('Enter at least 15 characters.', 'error'); return; }
    setLoading(true, 'Running 1-Step Combo: detect → humanize → re-verify...');
    try {
        const r = await post("{{ route('ai-tools.combo') }}", { text, mode });
        renderHumanized(r.data);
        toast(`1-Step Combo done! Score dropped ${r.data.score_reduction}%.`);
    } catch(e) { toast(e.message, 'error'); }
    finally { setLoading(false); }
}

async function post(url, data) {
    const r = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        body: JSON.stringify(data)
    });
    const j = await r.json();
    if (!r.ok || !j.success) throw new Error(j.message || 'Server error');
    return j;
}

// ================================================================
//  RENDER DETECTION (Heatmap + Gauge + GPTZero metrics)
// ================================================================
function renderDetection(data) {
    heatmapData = data;
    const score = data.overall_score;
    const col = gaugeColor(score);

    animateGauge(score, col);
    $('verdictText').textContent = data.verdict;
    $('highCnt').textContent = data.risk_summary.high;
    $('medCnt').textContent  = data.risk_summary.medium;
    $('lowCnt').textContent  = data.risk_summary.low;

    // Engine badge
    if (data.engine) {
        $('engBadge').textContent = data.engine;
        $('engBadge').classList.remove('hidden');
    }

    // GPTZero style perplexity / burstiness metrics
    if (data.readability) {
        const burst  = data.readability.burstiness_index || 0;
        const flesch = data.readability.flesch_score || 0;
        const level  = data.readability.reading_level || '';
        $('burstVal').textContent  = burst.toFixed(1);
        $('fleschVal').textContent = flesch.toFixed(0);
        $('burstLabel').textContent  = burst < 3 ? 'Low — Uniform AI cadence' : burst < 6 ? 'Moderate' : 'High — Human-like variety';
        $('fleschLabel').textContent = level;
        $('burstBar').style.width  = Math.min(100, burst * 8) + '%';
        $('burstBar').style.background = burst < 3 ? '#f87171' : burst < 6 ? '#fbbf24' : '#34d399';
        $('fleschBar').style.width = flesch + '%';
        $('nlpMetrics').classList.remove('hidden');
    }

    // Reading time (Originality.ai style)
    if (data.reading_time_sec) {
        $('readTime').textContent = data.reading_time_sec;
        $('readPill').classList.remove('hidden');
        $('readPill').style.display = 'flex';
    }

    // Build heatmap
    const hm = $('heatmapContent');
    hm.innerHTML = '';
    (data.sentences || []).forEach(s => {
        const sp = document.createElement('span');
        sp.className = `sentence-${s.risk_level} inline mr-1`;
        sp.textContent = s.sentence + ' ';
        sp.title = `Score: ${s.score}% — click to inspect`;
        sp.onclick = () => inspectSentence(s);
        hm.appendChild(sp);
    });

    $('toggleHeatmapBtn').classList.remove('hidden');
    switchToHeatmap();

    // Benchmarks (Copyleaks / multi-detector style)
    if (data.detectors_breakdown) renderBenchmarks(data.detectors_breakdown);
}

// ================================================================
//  BENCHMARK GRID (Copyleaks / comparison bar)
// ================================================================
function renderBenchmarks(breakdown) {
    const grid = $('benchmarkGrid');
    grid.innerHTML = '';
    $('benchmarkBar').classList.remove('hidden');
    Object.entries(breakdown).forEach(([key, item]) => {
        const badgeCls = item.badge === 'red' ? 'border-red-200 bg-red-50 text-red-800' :
                         item.badge === 'amber' ? 'border-amber-200 bg-amber-50 text-amber-800' :
                         'border-emerald-200 bg-emerald-50 text-emerald-800';
        const barCol   = item.badge === 'red' ? 'bg-red-400' : item.badge === 'amber' ? 'bg-amber-400' : 'bg-emerald-400';
        const card = document.createElement('div');
        card.className = `p-3 border ${badgeCls} flex flex-col gap-1.5`;
        card.innerHTML = `
            <div class="text-[10px] font-bold uppercase tracking-wider opacity-70 truncate">${item.category}</div>
            <div class="flex items-center justify-between gap-1">
                <span class="text-xs font-extrabold">${item.name}</span>
                <span class="text-sm font-black">${item.score}%</span>
            </div>
            <div class="metric-bar"><div class="${barCol} metric-bar-fill" style="width:${item.score}%"></div></div>`;
        grid.appendChild(card);
    });
}

// ================================================================
//  HEATMAP TOGGLE
// ================================================================
function toggleHeatmap() {
    if (heatmapOn) switchToText();
    else switchToHeatmap();
}
function switchToHeatmap() {
    $('inputText').classList.add('hidden');
    $('heatmapContainer').classList.remove('hidden');
    $('toggleHeatmapLabel').textContent = 'Edit Text';
    heatmapOn = true;
}
function switchToText() {
    $('heatmapContainer').classList.add('hidden');
    $('inputText').classList.remove('hidden');
    $('toggleHeatmapLabel').textContent = 'Heatmap';
    heatmapOn = false;
}

// ================================================================
//  SENTENCE INSPECTOR (Walter Writes + Proofademic style)
// ================================================================
function inspectSentence(s) {
    activeS = s;
    $('inspector').classList.remove('hidden');
    const badge = $('inspRiskBadge');
    const cls = s.risk_level === 'high' ? 'bg-red-500 text-white' :
                s.risk_level === 'medium' ? 'bg-amber-400 text-slate-900' :
                'bg-emerald-500 text-white';
    badge.className = `px-2 py-0.5 rounded-md font-bold text-[10px] uppercase ${cls}`;
    badge.textContent = s.risk_level === 'high' ? 'High Risk' : s.risk_level === 'medium' ? 'Medium Risk' : 'Human';
    $('inspScore').textContent = `${s.score}% AI likelihood`;
    $('inspText').textContent = `"${s.sentence}"`;
    $('inspReason').textContent = s.reason || 'Pattern analysis.';
    $('inspSuggestion').textContent = s.suggestion || 'Consider rephrasing with varied sentence length.';

    // Signal chips (GPTZero style tags)
    const chips = $('inspSignals');
    chips.innerHTML = '';
    (s.signals || []).forEach(sig => {
        const c = document.createElement('span');
        c.className = 'px-2 py-0.5 bg-slate-700 text-slate-200 rounded-full text-[10px]';
        c.textContent = sig;
        chips.appendChild(c);
    });
}

function closeInspector() {
    $('inspector').classList.add('hidden');
    activeS = null;
}

function applySuggestion() {
    if (!activeS?.suggestion || !activeS?.sentence) return;
    $('inputText').value = $('inputText').value.replace(activeS.sentence, activeS.suggestion);
    updateMeta();
    closeInspector();
    switchToText();
    toast('Suggestion applied — re-run Detection to update heatmap.');
}

// ================================================================
//  RENDER HUMANIZED OUTPUT
// ================================================================
function renderHumanized(data) {
    humanizedRaw = data.humanized_text || '';
    originalRaw  = data.original_text || $('inputText').value.trim();
    $('outputBox').textContent = humanizedRaw;

    const os = data.original_score, hs = data.humanized_score, dr = data.score_reduction;
    $('beforeBadge').textContent = `Before: ${os}%`;
    $('beforeBadge').classList.remove('hidden');
    $('arrowIcon').classList.remove('hidden');
    $('afterBadge').textContent = `After: ${hs}% AI`;
    $('afterBadge').classList.remove('hidden');
    $('dropBadge').textContent = `−${dr}% drop`;
    $('dropBadge').classList.remove('hidden');
    if (data.engine) {
        $('engineBadge').textContent = data.engine;
        $('engineBadge').classList.remove('hidden');
    }

    // Show diff toggle (Copyleaks feature)
    $('diffToggleWrap').classList.remove('hidden');
    diffShown = false;
    $('diffBtnLabel').textContent = 'Show Diff';

    // Also update detection panel from original
    if (data.original_detection) renderDetection(data.original_detection);
}

// ================================================================
//  COPYLEAKS STYLE DIFF VIEW
// ================================================================
function toggleDiff() {
    diffShown = !diffShown;
    $('diffBtnLabel').textContent = diffShown ? 'Hide Diff' : 'Show Diff';
    if (!diffShown) {
        $('outputBox').textContent = humanizedRaw;
        return;
    }
    // Simple word-level diff
    const origWords = originalRaw.split(/\s+/);
    const humWords  = humanizedRaw.split(/\s+/);
    const html = buildSimpleDiff(origWords, humWords);
    $('outputBox').innerHTML = html;
}

function buildSimpleDiff(orig, human) {
    const origSet = new Set(orig.map(w => w.toLowerCase().replace(/[^a-z]/g, '')));
    const humSet  = new Set(human.map(w => w.toLowerCase().replace(/[^a-z]/g, '')));
    const parts = human.map(w => {
        const clean = w.toLowerCase().replace(/[^a-z]/g, '');
        if (!origSet.has(clean) && clean.length > 2) return `<span class="diff-added">${w}</span>`;
        return w;
    });
    return parts.join(' ');
}

// ================================================================
//  COPY + EXPORT
// ================================================================
function copyOutput() {
    const t = $('outputBox').textContent.trim();
    if (!t || t.includes('Humanized output will appear')) { toast('Nothing to copy yet.', 'error'); return; }
    navigator.clipboard.writeText(t).then(() => toast('Copied to clipboard!'));
}

function downloadReport() {
    const orig  = $('inputText').value.trim();
    const human = $('outputBox').textContent.trim();
    if (!orig) { toast('Nothing to export yet.', 'error'); return; }
    const rpt = `=== HumixAi AUDIT REPORT ===\nGenerated: ${new Date().toLocaleString()}\nMode: ${mode.toUpperCase()}\n\n--- ORIGINAL ---\n${orig}\n\n--- HUMANIZED ---\n${human}\n\n=== Reference Tools ===\nWalter Writes: https://walterwrites.ai/\nProofademic: https://proofademic.ai/\nCopyleaks: https://copyleaks.com/ai-detector\nGPTZero: https://gptzero.me/\nOriginality.ai: https://originality.ai/`;
    const blob = new Blob([rpt], { type: 'text/plain' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `humixai-report-${Date.now()}.txt`;
    a.click();
    toast('Report exported!');
}

// ================================================================
//  LOADING
// ================================================================
function setLoading(on, msg = '') {
    $('loadingOverlay').classList.toggle('hidden', !on);
    $('loadMsg').textContent = msg;
    $('detectBtn').disabled = on;
    $('comboBtn').disabled  = on;
}

// ================================================================
//  API KEY MODAL
// ================================================================
function openConfigModal()  { $('apiKeyModal').classList.remove('hidden'); }
function closeConfigModal() { $('apiKeyModal').classList.add('hidden'); }

async function saveApiKey() {
    const key   = $('apiKeyInput').value.trim();
    const model = $('modelSelect').value;
    if (!key) { toast('Please enter a valid API key.', 'error'); return; }
    try {
        const r = await post("{{ route('ai-tools.update-key') }}", { api_key: key, model });
        toast('API key saved! Reloading...');
        setTimeout(() => location.reload(), 1100);
    } catch(e) { toast(e.message, 'error'); }
}

// ================================================================
//  TOAST
// ================================================================
function toast(msg, type = 'success') {
    $('toastMsg').textContent = msg;
    $('toastIcon').className = type === 'error'
        ? 'fa-solid fa-triangle-exclamation text-red-400 text-base shrink-0'
        : 'fa-solid fa-circle-check text-emerald-400 text-base shrink-0';
    const t = $('toast');
    t.classList.remove('translate-y-16', 'opacity-0');
    clearTimeout(t._timer);
    t._timer = setTimeout(() => t.classList.add('translate-y-16', 'opacity-0'), 3500);
}
</script>
</body>
</html>
