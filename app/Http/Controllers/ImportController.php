<?php

namespace App\Http\Controllers;

use App\Services\DocumentImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ImportController extends Controller
{
    public function preview(Request $request, DocumentImportService $imports): JsonResponse
    {
        $max = (int) config('studio.max_file_kb', 5120);
        $request->validate(['file' => "required|file|max:{$max}", 'language' => ['sometimes', \Illuminate\Validation\Rule::in(['auto', ...\App\Support\Locales::codes()])]]);
        return response()->json(['success' => true, 'data' => $imports->preview($request->file('file'), $request->input('language', 'auto'))]);
    }

    public function capabilities(DocumentImportService $imports): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $imports->capabilities()]);
    }
}
