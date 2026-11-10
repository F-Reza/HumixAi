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
        $request->validate(['file' => "required|file|max:{$max}"]);
        return response()->json(['success' => true, 'data' => $imports->preview($request->file('file'))]);
    }

    public function capabilities(DocumentImportService $imports): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $imports->capabilities()]);
    }
}
