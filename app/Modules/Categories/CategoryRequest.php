<?php

namespace App\Modules\Categories;

use App\Shared\Http\LocalizedFields;
use App\Shared\Http\StrictRequest;
use Illuminate\Validation\ValidationException;

final class CategoryRequest extends StrictRequest
{
    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return LocalizedFields::rules(required: $this->routeIs('categories.store')) + [
            'sortOrder' => ['sometimes', 'integer:strict', 'between:0,10000'],
            'isVisible' => ['sometimes', 'boolean:strict'],
        ];
    }

    /** @return array<string,mixed> */
    public function attributesForDraft(): array
    {
        $data = $this->validated();
        if ($data === []) {
            throw ValidationException::withMessages(['request' => 'Supply at least one field.']);
        }

        $attributes = LocalizedFields::attributes($data);
        foreach (['sortOrder' => 'sort_order', 'isVisible' => 'is_visible'] as $field => $column) {
            if (array_key_exists($field, $data)) {
                $attributes[$column] = $data[$field];
            }
        }

        return $attributes;
    }
}
