<?php

namespace App\Shared\Http;

use Illuminate\Validation\ValidationException;

final class LocalizedFields
{
    /** @return array<string,list<string>> */
    public static function rules(string $name = 'name', bool $required = false): array
    {
        return [
            $name => [$required ? 'required' : 'sometimes', 'array:en,ar', 'required_array_keys:en,ar'],
            $name.'.en' => ['required_with:'.$name, 'string', 'max:120'],
            $name.'.ar' => ['required_with:'.$name, 'string', 'max:120'],
            'description' => ['sometimes', 'array:en,ar', 'required_array_keys:en,ar'],
            'description.en' => ['nullable', 'string', 'min:1', 'max:1000'],
            'description.ar' => ['nullable', 'string', 'min:1', 'max:1000'],
        ];
    }

    /** @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public static function attributes(array $data, string $name = 'name', string $column = 'name'): array
    {
        $attributes = [];
        foreach ([$name => $column, 'description' => 'description'] as $field => $prefix) {
            if (is_array($data[$field] ?? null)) {
                foreach (['en', 'ar'] as $locale) {
                    $value = $data[$field][$locale];
                    if ($value === '') {
                        throw ValidationException::withMessages([$field.'.'.$locale => 'Supply text or an explicit null description.']);
                    }
                    $attributes[$prefix.'_'.$locale] = $value;
                }
            }
        }

        return $attributes;
    }
}
