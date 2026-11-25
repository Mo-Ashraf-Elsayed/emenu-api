<?php

namespace App\Modules\Restaurants;

use App\Modules\Identity\User;
use App\Shared\Http\ApiProblem;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class RestaurantLifecycle
{
    public function create(User $owner, string $name, string $slug, string $currency): Restaurant
    {
        try {
            return DB::transaction(function () use ($owner, $name, $slug, $currency): Restaurant {
                $restaurant = Restaurant::query()->create(['name' => $name, 'slug' => $slug, 'currency' => $currency]);
                $restaurant->memberships()->create(['user_id' => $owner->id, 'role' => 'owner', 'status' => 'active']);
                $timestamps = ['restaurant_id' => $restaurant->id, 'created_at' => now(), 'updated_at' => now()];
                DB::table('restaurant_branding')->insert($timestamps + ['display_name_en' => $name, 'display_name_ar' => $name]);
                DB::table('public_menu_settings')->insert($timestamps);

                return $restaurant;
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (Restaurant::withTrashed()->where('slug', $slug)->exists()) {
                throw new ApiProblem(409, 'SLUG_TAKEN');
            }
            throw $exception;
        }
    }

    /** Call while holding the restaurant row lock. */
    public function clearPublication(Restaurant $restaurant): void
    {
        DB::table('public_menu_settings')->where('restaurant_id', $restaurant->id)->whereNotNull('published_at')
            ->increment('publication_revision', 1, [
                'published_at' => null,
                'published_source_revision' => null,
                'snapshot' => null,
                'updated_at' => now(),
            ]);
    }
}
