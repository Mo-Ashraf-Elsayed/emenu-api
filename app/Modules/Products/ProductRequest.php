<?php

namespace App\Modules\Products;

use App\Shared\Http\LocalizedFields;
use App\Shared\Http\StrictRequest;
use Illuminate\Validation\ValidationException;

final class ProductRequest extends StrictRequest
{
    /** @return array<string,list<string>> */
    public function rules(): array
    {
        if ($this->routeIs('products.availability')) {
            return ['isAvailable' => ['required', 'boolean:strict']];
        }
        $create = $this->routeIs('products.store');

        return LocalizedFields::rules(required: $create) + [
            'categoryId' => [$create ? 'required' : 'sometimes', 'uuid'],
            'priceAmount' => [$create ? 'required' : 'sometimes', 'string', 'regex:/^(0|[1-9][0-9]{0,9})(\.[0-9]{1,4})?$/D'],
            'sortOrder' => ['sometimes', 'integer:strict', 'between:0,10000'],
            'isVisible' => ['sometimes', 'boolean:strict'],
            'isAvailable' => ['sometimes', 'boolean:strict'],
            'mediaIds' => ['sometimes', 'array', 'list', 'max:4'],
            'mediaIds.*' => ['required', 'uuid', 'distinct:ignore_case'],
        ];
    }

    /** @return array<string,mixed> */
    public function changes(): array
    {
        $data = $this->validated();
        if ($data === []) {
            throw ValidationException::withMessages(['request' => 'Supply at least one field.']);
        }

        return $data;
    }
}
