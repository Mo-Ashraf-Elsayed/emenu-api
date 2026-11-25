<?php

namespace Tests\Support;

use App\Modules\Identity\User;
use App\Modules\Memberships\Membership;
use App\Modules\Restaurants\Restaurant;
use App\Modules\Restaurants\RestaurantLifecycle;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class PhaseTwo
{
    public const PASSWORD = 'Melon lattice at summer dusk 23';

    public static function user(string $email, bool $verified = true, bool $platform = false): User
    {
        $user = new User(['name' => 'Menu operator', 'email' => $email, 'password' => Hash::make(self::PASSWORD)]);
        $user->forceFill(['email_verified_at' => $verified ? now() : null, 'is_platform_admin' => $platform])->save();

        return $user->refresh();
    }

    public static function login(TestCase $test, User $user): void
    {
        $test->actingAs($user->fresh(), 'sanctum');
    }

    public static function restaurant(User $owner, string $slug): Restaurant
    {
        return (new RestaurantLifecycle)->create($owner, ucfirst($slug), $slug, 'AED');
    }

    public static function member(Restaurant $restaurant, User $user, string $role): Membership
    {
        return $restaurant->memberships()->create(['user_id' => $user->id, 'role' => $role, 'status' => 'active']);
    }
}
