# WalterAI - Top 5 Calibrated AI Detector & 1-Pass Humanizer

A full-stack Laravel application that detects AI-generated content (showing exact percentages and sentence-level risk) and humanizes it in a single pass. Calibrated and benchmarked against the industry's top 5 AI detection tools:

1. 🏆 **#1 Walter Writes AI Detector:** Best overall detector + cleanup combo, sentence-level risk, keeping structure intact while passing checks.
2. 📘 **#2 Proofademic:** Turnitin-modeled academic draft detector; flags problematic phrasing while protecting MLA/APA citations from breaking.
3. 🟡 **#3 Copyleaks:** Publishers' choice; catches subtle GPT patterns with sentence-level scores and exportable audit reports.
4. 🟠 **#4 GPTZero:** Burstiness, perplexity, and sentence cadence analysis.
5. 🔵 **#5 Originality.ai:** Fast short-form and web copy scanning.

---

## 🌟 Key Features

1. **Overall AI Likelihood Percentage (0% - 100%):** Weighted document-level score with clear verdict badges (High Risk, Medium, Human).
2. **Top 5 Multi-Engine Benchmark Comparison:** Real-time simulated calibration breakdown across Walter Writes, Proofademic, Copyleaks, GPTZero, and Originality.ai.
3. **Interactive Sentence Risk Heatmap:**
   - 🔴 **High Risk (>= 70%):** Repetitive token patterns, predictable transitions, LLM clichés.
   - 🟡 **Medium Risk (35% - 69%):** Moderately uniform cadence.
   - 🟢 **Low / Human (< 35%):** Natural burstiness and authentic human rhythm.
   - **Click-to-Inspect:** Click any sentence to view its diagnosis and **instantly apply an alternative suggestion** right into your draft.
4. **1-Step Check + Humanize Combo (Walter Writes Style):**
   - Click **"1-Step Combo"** to scan risks, humanize phrasing, and automatically re-verify the score drop (e.g. 96% ➔ 8%) in a closed loop.
5. **Proofademic Citation Preservation Guard:**
   - In **Academic Mode**, APA, MLA, Harvard, and IEEE citations (`(Author, Year, p. 12)`, `[1-3]`, `et al.`) are tokenized and protected verbatim, ensuring citations and scholarly quotes never get mangled or deleted.
6. **4 Tailored Humanization Modes:**
   - **Standard Mode:** Walter Writes style, best for SEO, blogs, and general articles.
   - **Academic Mode:** Proofademic / Turnitin safe, formal scholarly voice.
   - **Casual Mode:** Conversational, lively, natural contractions.
   - **Stealth Bypass Mode:** Maximum burstiness and perplexity variation.
7. **Offline Heuristic Engine + Gemini Deep AI:**
   - Works immediately out of the box using statistical NLP (Burstiness, Type-Token Ratio, 70+ AI clichés).
   - Enter your Google Gemini API key directly through the web UI settings modal for deep semantic bypass.
8. **Exportable Reports (Copyleaks Feature):**
   - 1-click **Export Audit Report** (.txt) and **Print / PDF Audit** view.

---

## 🚀 Quick Setup & Installation

### Step 1: Install Dependencies
```bash
composer install
```

### Step 2: Configure Environment
Copy `.env.example` to `.env` (if needed) and generate key:
```bash
cp .env.example .env
php artisan key:generate
```

### Step 3: Run Tests
Verify all 10 unit and feature tests pass:
```bash
php vendor/phpunit/phpunit/phpunit
```

### Step 4: Start Laravel Server
```bash
php artisan serve
```
Open your browser at:
👉 **`http://127.0.0.1:8000`**

---

## 📡 REST API Documentation

### 1. Detect AI Content
- **Endpoint:** `POST /api/v1/detect`
- **Request Body:**
```json
{
  "text": "In today's fast-paced digital world, artificial intelligence plays a pivotal role. Furthermore, it is crucial to delve into machine learning."
}
```
- **Response:**
```json
{
  "success": true,
  "data": {
    "overall_score": 98,
    "verdict": "Highly Likely AI-Generated",
    "word_count": 21,
    "risk_summary": { "high": 2, "medium": 0, "low": 0 },
    "detectors_breakdown": {
      "walter_writes": { "score": 98, "verdict": "Highly Likely AI-Generated" },
      "proofademic": { "score": 100, "verdict": "Highly Likely AI-Generated" },
      "copyleaks": { "score": 96, "verdict": "Highly Likely AI-Generated" },
      "gptzero": { "score": 93, "verdict": "Highly Likely AI-Generated" },
      "originality": { "score": 100, "verdict": "Highly Likely AI-Generated" }
    },
    "sentences": [
      {
        "id": 1,
        "sentence": "In today's fast-paced digital world, artificial intelligence plays a pivotal role.",
        "score": 95,
        "risk_level": "high",
        "reason": "Robotic Opening Transition; AI Cliché: 'plays a pivotal role in'.",
        "suggestion": "Today, artificial intelligence is essential to..."
      }
    ]
  }
}
```

### 2. Humanize Text
- **Endpoint:** `POST /api/v1/humanize`
- **Request Body:**
```json
{
  "text": "According to Miller et al. (2022), neural nets play a pivotal role.",
  "mode": "academic"
}
```

### 3. 1-Step Combo
- **Endpoint:** `POST /api/v1/combo`
- **Request Body:**
```json
{
  "text": "Your text here...",
  "mode": "standard"
}
```
- **Response:** Contains both original detection, rewritten humanized text, closed-loop verified score, and reduction percentage.

### 4. Health Check
- **Endpoint:** `GET /api/health`
```json
{
  "status": "ok",
  "service": "WalterAI Detector & Humanizer API",
  "version": "1.2.0"
}
```
