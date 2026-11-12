<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\DocumentRevision;
use App\Models\StudioJob;
use App\Models\UsageReservation;
use App\Services\UsageService;
use DOMDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

class DocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $documents = Document::where('user_id', $request->user()->id)
            ->select(['id', 'title', 'settings', 'created_at', 'updated_at'])
            ->withCount('revisions')->orderByDesc('updated_at')->paginate(20);
        return response()->json(['success' => true, 'data' => [
            'documents' => $documents->items(), 'current_page' => $documents->currentPage(),
            'last_page' => $documents->lastPage(), 'total' => $documents->total(),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureHistoryEnabled($request);
        $max = (int) config('studio.max_characters', 100000);
        $data = $request->validate([
            'title' => 'nullable|string|max:200', 'source_text' => "required|string|max:{$max}",
            'current_text' => "sometimes|required|string|max:{$max}", 'settings' => 'nullable|array|max:20',
        ]);
        $this->validateSettings($data['settings'] ?? null);
        $document = DB::transaction(function () use ($request, $data) {
            $document = Document::create([
                'user_id' => $request->user()->id,
                'title' => trim($data['title'] ?? '') ?: Str::limit(preg_replace('/\s+/u', ' ', trim($data['source_text'])), 80, ''),
                'source_text' => $data['source_text'], 'current_text' => $data['current_text'] ?? $data['source_text'],
                'settings' => $data['settings'] ?? [],
            ]);
            $this->revision($document, 'created', $document->source_text, $document->current_text);
            return $document;
        });
        return response()->json(['success' => true, 'data' => $document->loadCount('revisions')], 201);
    }

    public function show(Request $request, string $document): JsonResponse
    {
        $document = $this->owned($request, $document);
        $document->setRelation('revisions', $document->revisions()->latest()->limit(50)->get());
        return response()->json(['success' => true, 'data' => $document]);
    }

    public function update(Request $request, string $document): JsonResponse
    {
        $document = $this->owned($request, $document);
        $this->ensureHistoryEnabled($request);
        foreach (['source_text', 'current_text'] as $field) {
            if ($request->exists($field) && $request->input($field) === null) { $request->merge([$field => '']); }
        }
        $max = (int) config('studio.max_characters', 100000);
        $data = $request->validate([
            'title' => 'sometimes|required|string|max:200', 'source_text' => "sometimes|string|max:{$max}",
            'current_text' => "sometimes|string|max:{$max}",
            'settings' => 'sometimes|nullable|array|max:20',
        ]);
        $this->validateSettings($data['settings'] ?? null);
        DB::transaction(function () use ($document, $data) {
            $document->fill($data);
            $textChanged = $document->isDirty('current_text') || $document->isDirty('source_text');
            $document->save();
            if ($textChanged) { $this->revision($document, 'edit', $document->source_text, $document->current_text); }
        });
        return response()->json(['success' => true, 'data' => $document->fresh()->loadCount('revisions')]);
    }

    public function destroy(Request $request, string $document, UsageService $usage): JsonResponse
    {
        $document = $this->owned($request, $document);
        DB::transaction(function () use ($document, $usage) {
            $jobs = StudioJob::where('document_id', $document->id)->lockForUpdate()->get();
            foreach ($jobs as $job) {
                if (in_array($job->status, ['queued', 'running'], true) && $job->reservation_id
                    && UsageReservation::whereKey($job->reservation_id)->exists()) {
                    $usage->release($job->reservation_id);
                }
            }
            StudioJob::where('document_id', $document->id)->delete();
            $document->delete();
        }, 3);
        return response()->json(['success' => true, 'data' => ['deleted' => true]]);
    }

    public function revisions(Request $request, string $document): JsonResponse
    {
        $document = $this->owned($request, $document);
        $revisions = $document->revisions()->latest()->paginate(20);
        return response()->json(['success' => true, 'data' => [
            'revisions' => $revisions->items(), 'current_page' => $revisions->currentPage(),
            'last_page' => $revisions->lastPage(), 'total' => $revisions->total(),
        ]]);
    }

    public function export(Request $request, string $document): Response
    {
        $document = $this->owned($request, $document);
        return self::downloadPayload(self::snapshot($document), (string) $request->query('format', 'txt'));
    }

    public static function snapshot(Document $document): array
    {
        return [
            'id' => $document->id, 'title' => $document->title, 'source_text' => $document->source_text,
            'current_text' => $document->current_text, 'settings' => $document->settings,
            'revisions' => $document->revisions()->latest()->limit(20)
                ->get(['id', 'action', 'input_text', 'output_text', 'result', 'text_hash', 'created_at'])->toArray(),
            'exported_at' => now()->toIso8601String(),
        ];
    }

    /** A shared report uses the same safe, immutable snapshot export. */
    public static function downloadPayload(array $snapshot, string $format): Response
    {
        if (!in_array($format, ['txt', 'json', 'docx', 'report'], true)) {
            throw ValidationException::withMessages(['format' => ['Choose txt, json, docx, or report. Print to PDF is available in your browser.']]);
        }
        $filename = Str::slug(mb_substr($snapshot['title'] ?? 'HumixAi document', 0, 80)) ?: 'humixai-document';
        $headers = ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "sandbox; default-src 'none'"];
        if ($format === 'json') {
            return response(json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), 200,
                $headers + ['Content-Type' => 'application/json; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$filename.'.json"']);
        }
        $text = $format === 'report' ? self::reportText($snapshot) : (string) ($snapshot['current_text'] ?? '');
        if ($format !== 'docx') {
            return response($text, 200, $headers + ['Content-Type' => 'text/plain; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="'.$filename.($format === 'report' ? '-report' : '').'.txt"']);
        }
        if (!class_exists(ZipArchive::class)) {
            throw ValidationException::withMessages(['format' => ['DOCX export is unavailable on this server.']]);
        }
        $temporary = tempnam(sys_get_temp_dir(), 'humix-docx-');
        if ($temporary === false) { abort(503, 'DOCX export is temporarily unavailable.'); }
        try {
            $zip = new ZipArchive();
            if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) { abort(503, 'DOCX export is temporarily unavailable.'); }
            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>');
            $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>');
            $dom = new DOMDocument('1.0', 'UTF-8');
            $ns = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
            $root = $dom->createElementNS($ns, 'w:document');
            $dom->appendChild($root);
            $body = $root->appendChild($dom->createElementNS($ns, 'w:body'));
            foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $line) {
                $paragraph = $body->appendChild($dom->createElementNS($ns, 'w:p'));
                $run = $paragraph->appendChild($dom->createElementNS($ns, 'w:r'));
                $node = $run->appendChild($dom->createElementNS($ns, 'w:t'));
                $node->setAttributeNS('http://www.w3.org/XML/1998/namespace', 'xml:space', 'preserve');
                $node->appendChild($dom->createTextNode($line));
            }
            $zip->addFromString('word/document.xml', $dom->saveXML());
            $zip->close();
            $bytes = file_get_contents($temporary);
            if ($bytes === false) { abort(503, 'DOCX export is temporarily unavailable.'); }
            return response($bytes, 200, $headers + ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'Content-Disposition' => 'attachment; filename="'.$filename.'.docx"']);
        } finally { @unlink($temporary); }
    }

    private static function reportText(array $snapshot): string
    {
        $lines = [__('HumixAi writing report'), (string) ($snapshot['title'] ?? ''), __('Exported: ').($snapshot['exported_at'] ?? now()->toIso8601String()),
            '', __('ORIGINAL TEXT'), (string) ($snapshot['source_text'] ?? ''), '', __('CURRENT TEXT'), (string) ($snapshot['current_text'] ?? ''), '', __('RECORDED ANALYSIS')];
        foreach ($snapshot['revisions'] ?? [] as $revision) {
            if (!empty($revision['result'])) {
                $lines[] = ($revision['action'] ?? 'analysis').' — '.($revision['created_at'] ?? '');
                $lines[] = json_encode($revision['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            }
        }
        $lines[] = "\nAI scores are probabilistic signals and do not establish authorship or academic misconduct.";
        return implode("\n", $lines);
    }

    private function owned(Request $request, string $id): Document
    {
        return Document::where('user_id', $request->user()->id)->whereKey($id)->firstOrFail();
    }

    private function ensureHistoryEnabled(Request $request): void
    {
        if (!$request->user()->history_enabled) {
            throw ValidationException::withMessages(['history_enabled' => ['Enable document history in Account before saving documents.']]);
        }
    }

    private function validateSettings(?array $settings): void
    {
        if ($settings !== null) {
            \Illuminate\Support\Facades\Validator::make(['settings' => $settings], [
                'settings.mode' => 'sometimes|string|in:standard,professional,academic,casual,marketing',
                'settings.language' => 'sometimes|string|in:auto,en,bn,hi,ur,ar,ne,es,fr,de,pt',
                'settings.strength' => 'sometimes|string|in:light,balanced,strong',
                'settings.length' => 'sometimes|string|in:shorter,similar,longer',
                'settings.protected_terms' => 'sometimes|array|max:40',
                'settings.protected_terms.*' => 'string|max:1000',
                'settings.selection' => 'sometimes|nullable|string|max:60000',
            ])->validate();
        }
        if ($settings && strlen(json_encode($settings, JSON_THROW_ON_ERROR)) > 10000) {
            throw ValidationException::withMessages(['settings' => ['Document settings are too large.']]);
        }
    }

    private function revision(Document $document, string $action, string $input, string $output): void
    {
        DocumentRevision::create(['document_id' => $document->id, 'action' => $action,
            'input_text' => $input, 'output_text' => $output, 'text_hash' => hash('sha256', $input)]);
    }
}
