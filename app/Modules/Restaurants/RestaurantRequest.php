<?php

namespace App\Modules\Restaurants;

use App\Shared\Http\StrictRequest;
use Illuminate\Validation\Rule;

final class RestaurantRequest extends StrictRequest
{
    /** @return array<string,mixed> */
    public function rules(): array
    {
        $name = ['name' => ['required', 'string', 'max:120']];

        return match ($this->route()?->getName()) {
            'restaurants.store' => $name + [
                'slug' => ['required', 'string', 'min:3', 'max:63', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D'],
                'currency' => ['required', 'string', Rule::in(array_keys(Currencies::MINOR_UNITS))],
            ],
            'platform.restaurants.status' => ['status' => ['required', 'string', Rule::in(['active', 'suspended'])]],
            default => $name,
        };
    }
}
