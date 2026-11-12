<?php
namespace App\Http\Controllers;
use App\Support\Locales;
use App\Services\LocalizationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
class LocaleController extends Controller {
    public function update(Request $request) {
        $data = $request->validate(['locale'=>['required', Rule::in(Locales::codes())]]);
        $request->session()->put('locale', $data['locale']);
        if ($request->user()) { $request->user()->forceFill($data)->save(); }
        return response()->json(['locale'=>$data['locale'], 'direction'=>Locales::direction($data['locale']), 'reload'=>true]);
    }
    public function catalog(Request $request, LocalizationService $service) {
        $data = $request->validate(['locale'=>['sometimes', Rule::in(Locales::codes())]]);
        return response()->json($service->catalog($data['locale'] ?? app()->getLocale()));
    }
}
