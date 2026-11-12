<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StudioRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'min:15', 'regex:/\S/u', 'max:'.config('studio.max_characters')],
            'mode' => ['sometimes', Rule::in(['standard', 'professional', 'academic', 'casual', 'marketing'])],
            'language' => ['sometimes', Rule::in(['auto', 'en', 'bn', 'hi', 'ur', 'ar', 'ne', 'es', 'fr', 'de', 'pt'])],
            'strength' => ['sometimes', Rule::in(['light', 'balanced', 'strong'])],
            'length' => ['sometimes', Rule::in(['shorter', 'similar', 'longer'])],
            'protected_terms' => ['sometimes', 'array', 'max:40'],
            'protected_terms.*' => ['string', 'min:1', 'max:1000'],
            'voice_preferences' => ['nullable', 'string', 'max:1000'],
            'voice_sample' => ['nullable', 'string', 'max:4000'],
            'selection' => ['nullable', 'string', 'max:'.config('studio.max_characters')],
            'selection_start' => ['nullable', 'integer', 'min:0', 'max:'.config('studio.max_characters')],
            'document_id' => ['nullable', 'uuid'],
            'external_processing_consent' => ['sometimes', 'boolean'],
            'action' => ['sometimes', Rule::in(['detect', 'humanize', 'combo'])],
            'idempotency_key' => ['nullable', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/'],
        ];
    }
}
