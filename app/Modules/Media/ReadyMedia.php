<?php

namespace App\Modules\Media;

use App\Modules\Restaurants\Restaurant;
use Illuminate\Validation\ValidationException;

final class ReadyMedia
{
    /** @param list<string> $ids */
    public static function require(Restaurant $restaurant, array $ids, string $field): void
    {
        if ($ids !== [] && $restaurant->media()->where('status', 'ready')->whereIn('id', $ids)->count() !== count($ids)) {
            throw ValidationException::withMessages([$field => 'Select ready media from this restaurant.']);
        }
    }
}
