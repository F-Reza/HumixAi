<?php

namespace App\Http\Controllers;

use App\Services\UrlImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UrlImportController extends Controller
{
    public function preview(Request $request, UrlImportService $imports): JsonResponse
    {
        abort_unless($request->user(), 401, 'Sign in to import a public page.');
        $data = $request->validate(['url' => 'required|string|max:4096']);
        return response()->json(['success' => true, 'data' => $imports->preview($data['url'])]);
    }
}
