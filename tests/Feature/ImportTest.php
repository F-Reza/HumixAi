<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DocumentImportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

class ImportTest extends TestCase
{
    private array $temporaryPaths = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.foreign_key_constraints' => true, 'services.gemini.api_key' => null]);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        Http::preventStrayRequests();
        $this->actingAs(User::create(['name' => 'Reader', 'email' => 'reader@example.test', 'password' => Hash::make('test-password-123')]));
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryPaths as $path) { @unlink($path); }
        parent::tearDown();
    }

    private function docx(string $xml, array $entries = []): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'humix-import-test-');
        $this->temporaryPaths[] = $path;
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types/>');
        $zip->addFromString('word/document.xml', $xml);
        foreach ($entries as $name => $content) { $zip->addFromString($name, $content); }
        $zip->close();
        return new UploadedFile($path, 'draft.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
    }

    public function test_txt_preview_preserves_paragraphs_and_never_starts_a_scan(): void
    {
        $text = "First paragraph.\n\nবাংলা লেখার দ্বিতীয় অনুচ্ছেদ।";
        $file = UploadedFile::fake()->createWithContent('draft.txt', $text);
        $this->postJson('/imports', ['file' => $file])->assertOk()->assertJsonPath('data.text', $text)
            ->assertJsonPath('data.capabilities.ocr', false)->assertJsonPath('data.capabilities.url', false);
        $this->assertDatabaseCount('studio_jobs', 0);
        $this->assertDatabaseCount('documents', 0);
        Http::assertNothingSent();
    }

    public function test_docx_import_preserves_paragraph_boundaries_and_decodes_text_safely(): void
    {
        $xml = '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>First &amp; second.</w:t></w:r></w:p><w:p/><w:p><w:r><w:t>Third paragraph (Smith, 2024).</w:t></w:r></w:p></w:body></w:document>';
        $this->postJson('/imports', ['file' => $this->docx($xml)])->assertOk()
            ->assertJsonPath('data.text', "First & second.\n\nThird paragraph (Smith, 2024).");
    }

    public function test_docx_entities_and_archive_bombs_are_rejected_before_expansion(): void
    {
        $entity = '<?xml version="1.0"?><!DOCTYPE doc [<!ENTITY external SYSTEM "file:///never-read-this-test-file">]><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>&external;</w:t></w:r></w:p></w:body></w:document>';
        $this->postJson('/imports', ['file' => $this->docx($entity)])->assertStatus(422)->assertJsonValidationErrors('file');
        $this->postJson('/imports', ['file' => $this->docx('<document/>', ['word/large.bin' => str_repeat('A', 2 * 1024 * 1024)])])
            ->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_pdf_unavailable_and_binary_text_are_explicitly_rejected(): void
    {
        config(['studio.pdftotext_path' => base_path('missing-pdftotext-executable')]);
        $this->assertFalse(app(DocumentImportService::class)->capabilities()['pdf_available']);
        $this->postJson('/imports', ['file' => UploadedFile::fake()->createWithContent('draft.pdf', '%PDF-1.4')])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->postJson('/imports', ['file' => UploadedFile::fake()->createWithContent('binary.txt', "bad\0binary")])
            ->assertStatus(422)->assertJsonValidationErrors('file');
    }
}
