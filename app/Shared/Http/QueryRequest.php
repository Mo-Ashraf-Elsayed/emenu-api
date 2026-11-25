<?php

namespace App\Shared\Http;

use Illuminate\Validation\Validator;

abstract class QueryRequest extends StrictRequest
{
    /** @return array<string,mixed> */
    public function validationData(): array
    {
        return $this->query->all();
    }

    public function withValidator(Validator $validator): void
    {
        parent::withValidator($validator);
        $validator->after(function (Validator $validator): void {
            $seen = [];
            foreach (explode('&', $this->server->getString('QUERY_STRING')) as $pair) {
                if ($pair === '') {
                    continue;
                }
                $name = urldecode(explode('=', $pair, 2)[0]);
                if (! array_key_exists($name, $validator->getRules()) || isset($seen[$name])) {
                    $validator->errors()->add('request', 'The query contains an unsupported or repeated parameter.');
                    break;
                }
                $seen[$name] = true;
            }
        });
    }

    public function filter(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) ? $value : null;
    }
}
