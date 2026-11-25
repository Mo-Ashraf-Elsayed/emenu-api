<?php

namespace App\Modules\Restaurants;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Restaurant */
final class RestaurantResource extends JsonResource
{
    public function __construct(Restaurant $restaurant, private readonly string $role)
    {
        parent::__construct($restaurant);
    }

    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'slug' => $this->slug, 'currency' => $this->currency, 'role' => $this->role];
    }
}
