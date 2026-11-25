<?php

namespace App\Shared\Http;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class StrictRequest extends FormRequest
{
    /** @return array<string,mixed> */
    public function validationData(): array
    {
        return $this->json()->all();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $data = $this->validationData();
            $rules = $validator->getRules();
            foreach (array_diff_key($data, $rules) as $field => $_) {
                // Report one fixed key so arbitrary input keys never appear in errors.
                $validator->errors()->add('request', 'The request contains an unsupported field.');
                break;
            }
            foreach (array_intersect_key($data, $rules) as $field => $value) {
                if ($value === '') {
                    $validator->errors()->add($field, 'The field may not be blank.');
                }
            }
        });
    }

    public function value(string $key): string
    {
        $value = $this->validated($key);
        abort_unless(is_string($value), 422);

        return $value;
    }
}
