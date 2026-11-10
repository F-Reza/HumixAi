<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\Process\Process;
use ZipArchive;

class DocumentImportService
{
    public function capabilities(): array
    {
        $pdf = $this->pdfBinary() !== null;
        $docx = class_exists(ZipArchive::class) && class_exists(DOMDocument::class);
        return [
            'formats' => array_values(array_filter(['txt', $docx ? 'docx' : null, $pdf ? 'pdf' : null])),
            'txt' => true, 'docx' => $docx, 'pdf' => $pdf, 'pdf_available' => $pdf,
            'url' => false, 'ocr' => false,
            'max_bytes' => (int) config('studio.import_max_bytes', 5 * 1024 * 1024),
            'max_characters' => (int) config('studio.max_characters', 100000),
        ];
    }

    public function preview(UploadedFile $file): array
    {
        $limit = (int) config('studio.import_max_bytes', 5 * 1024 * 1024);
        if (!$file->isValid() || $file->getSize() > $limit) {
            $this->invalid('Choose a valid file no larger than '.round($limit / 1048576, 1).' MB.');
        }
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, $this->capabilities()['formats'], true)) {
            $this->invalid($extension === 'pdf'
                ? 'PDF import is unavailable on this server. Upload TXT or DOCX instead.'
                : 'This file format is not supported. Use TXT, DOCX, or an available text PDF.');
        }
        $text = match ($extension) {
            'txt' => $this->plainText($file->getRealPath()),
            'docx' => $this->docxText($file->getRealPath()),
            'pdf' => $this->pdfText($file->getRealPath()),
        };
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = trim($text);
        if ($text === '') {
            $this->invalid('No readable text was found. Scanned images require OCR, which is not available.');
        }
        if (!mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) {
            $this->invalid('The file must contain valid UTF-8 text.');
        }
        if (mb_strlen($text) > (int) config('studio.max_characters', 100000)) {
            $this->invalid('The extracted text is too long. Split the document into smaller files.');
        }
        preg_match_all('/[\p{L}\p{N}][\p{L}\p{M}\p{N}\x{200C}\x{200D}\x{2019}\x{0027}-]*/u', $text, $matches);
        return [
            'text' => $text, 'word_count' => count($matches[0]), 'character_count' => mb_strlen($text),
            'filename' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 200),
            'format' => $extension,
            'warnings' => $extension === 'docx' ? ['Imports the document body text. Images, comments, headers and visual formatting are not imported.']
                : ($extension === 'pdf' ? ['PDF reading order may differ from its visual layout. Review the preview before scanning.'] : []),
            'capabilities' => $this->capabilities(),
        ];
    }

    private function plainText(string $path): string
    {
        $text = file_get_contents($path);
        if ($text === false) { $this->invalid('The file could not be read.'); }
        if (str_starts_with($text, "\xEF\xBB\xBF")) { $text = substr($text, 3); }
        return $text;
    }

    private function docxText(string $path): string
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            $this->invalid('The DOCX file is invalid or encrypted.');
        }
        try {
            if ($zip->numFiles > 2000) { $this->invalid('The DOCX archive contains too many entries.'); }
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                if (!$entry) { $this->invalid('The DOCX archive is malformed.'); }
                $name = str_replace('\\', '/', $entry['name']);
                if (str_starts_with($name, '/') || preg_match('~(^|/)\.\.(/|$)~', $name)) {
                    $this->invalid('The DOCX archive contains unsafe paths.');
                }
                $total += (int) $entry['size'];
                if ($total > 20 * 1024 * 1024 || ((int) $entry['size'] > 1024 * 1024 && (int) $entry['size'] / max(1, (int) $entry['comp_size']) > 150)) {
                    $this->invalid('The DOCX archive expands beyond the permitted limit.');
                }
            }
            $stat = $zip->statName('word/document.xml');
            if (!$stat || $stat['size'] > 4 * 1024 * 1024 || $zip->locateName('[Content_Types].xml') === false) {
                $this->invalid('A valid DOCX document body was not found.');
            }
            $xml = $zip->getFromName('word/document.xml');
            if ($xml === false || preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $xml)) {
                $this->invalid('XML entities and document type declarations are not supported.');
            }
            $previous = libxml_use_internal_errors(true);
            try {
                $dom = new DOMDocument();
                $dom->resolveExternals = false;
                $dom->substituteEntities = false;
                if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) { $this->invalid('The DOCX XML is malformed.'); }
                $paragraphs = [];
                foreach ($dom->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 'p') as $paragraph) {
                    $paragraphs[] = $this->paragraphText($paragraph);
                }
                return implode("\n", $paragraphs);
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
        } finally { $zip->close(); }
    }

    private function paragraphText(DOMNode $node): string
    {
        $text = '';
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement) { continue; }
            if ($child->namespaceURI === 'http://schemas.openxmlformats.org/wordprocessingml/2006/main') {
                if ($child->localName === 't') { $text .= $child->textContent; continue; }
                if ($child->localName === 'tab') { $text .= "\t"; continue; }
                if (in_array($child->localName, ['br', 'cr'], true)) { $text .= "\n"; continue; }
                if ($child->localName === 'del') { continue; }
            }
            $text .= $this->paragraphText($child);
        }
        return $text;
    }

    private function pdfText(string $path): string
    {
        $binary = $this->pdfBinary();
        if (!$binary) { $this->invalid('Text PDF import is unavailable on this server.'); }
        $handle = fopen($path, 'rb');
        $magic = $handle ? fread($handle, 5) : '';
        if ($handle) { fclose($handle); }
        if ($magic !== '%PDF-') { $this->invalid('The uploaded file is not a valid PDF.'); }
        $process = new Process([$binary, '-q', '-layout', '-enc', 'UTF-8', '-nopgbrk', $path, '-']);
        $process->setTimeout(20);
        $outputBytes = 0;
        try {
            $process->run(function ($type, $buffer) use (&$outputBytes) {
                $outputBytes += strlen($buffer);
                if ($outputBytes > 1024 * 1024) { throw new RuntimeException('Extracted PDF text is too large.'); }
            });
            if (!$process->isSuccessful()) { $this->invalid('This PDF could not be read. It may be encrypted or damaged.'); }
            return $process->getOutput();
        } catch (\Symfony\Component\Process\Exception\ExceptionInterface|RuntimeException $exception) {
            if ($process->isRunning()) { $process->stop(0); }
            $this->invalid('PDF extraction exceeded its limits or could not complete. Use a smaller, unencrypted text PDF.');
        }
    }

    private function pdfBinary(): ?string
    {
        if (!function_exists('proc_open')) { return null; }
        $configured = config('studio.pdftotext_path');
        if (is_string($configured) && $configured !== '') {
            $real = realpath($configured);
            if ($real && is_file($real) && is_executable($real)) { return $real; }
            // A symbolic executable name may be resolved through PATH; a broken explicit path may not.
            if (str_contains($configured, '/') || str_contains($configured, '\\') || str_contains($configured, ':')) { return null; }
            if (!in_array(strtolower($configured), ['pdftotext', 'pdftotext.exe'], true)) { return null; }
        }
        $name = PHP_OS_FAMILY === 'Windows' ? 'pdftotext.exe' : 'pdftotext';
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $directory) {
            if ($directory === '' || $directory === '.') { continue; }
            $real = realpath(trim($directory, '"').DIRECTORY_SEPARATOR.$name);
            if ($real && is_file($real) && is_executable($real)) { return $real; }
        }
        return null;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => [$message]]);
    }
}
