<?php

namespace App\Modules\Branding;

use App\Shared\Http\LocalizedFields;
use App\Shared\Http\StrictRequest;
use Illuminate\Validation\ValidationException;

final class BrandingRequest extends StrictRequest
{
    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return LocalizedFields::rules('displayName') + [
            'themeColor' => ['sometimes', 'string', 'regex:/^#[a-fA-F0-9]{6}$/D'],
            'logoMediaId' => ['sometimes', 'nullable', 'uuid'],
            'coverMediaId' => ['sometimes', 'nullable', 'uuid'],
        ];
    }

    /** @return array<string,mixed> */
    public function attributesForDraft(): array
    {
        $data = $this->validated();
        if ($data === []) {
            throw ValidationException::withMessages(['request' => 'Supply at least one field.']);
        }

        $attributes = LocalizedFields::attributes($data, 'displayName', 'display_name');
        foreach (['themeColor' => 'theme_color', 'logoMediaId' => 'logo_media_id', 'coverMediaId' => 'cover_media_id'] as $field => $column) {
            if (array_key_exists($field, $data)) {
                $attributes[$column] = is_string($data[$field]) ? strtolower($data[$field]) : null;
            }
        }

        return $attributes;
    }
}
