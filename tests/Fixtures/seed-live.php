<?php

use App\Modules\Identity\User;
use App\Modules\PublicMenus\Publication;
use App\Modules\Restaurants\Restaurant;
use App\Modules\Restaurants\RestaurantLifecycle;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$password = getenv('EMENU_INTEGRATION_PASSWORD');
if (! $app->environment('testing') || DB::connection()->getDatabaseName() !== 'emenu_integration'
    || ! is_string($password) || strlen($password) < 16 || Restaurant::withTrashed()->exists()) {
    fwrite(STDERR, "Use a fresh emenu_integration database with APP_ENV=testing and a test password of at least 16 characters.\n");
    exit(1);
}

DB::transaction(function () use ($password): void {
    $users = [];
    foreach (['owner', 'staff', 'other'] as $role) {
        $user = new User;
        $user->forceFill([
            'name' => ucfirst($role).' fixture',
            'email' => $role.'-live@example.test',
            'email_verified_at' => now(),
            'password' => Hash::make($password),
        ])->save();
        $users[$role] = $user;
    }

    $lifecycle = app(RestaurantLifecycle::class);
    $bistro = $lifecycle->create($users['owner'], 'River Bistro', 'river-live', 'AED');
    $large = $lifecycle->create($users['other'], 'Market Hall', 'market-live', 'AED');
    $bistro->memberships()->create(['user_id' => $users['staff']->id, 'role' => 'staff', 'status' => 'active']);

    foreach ([[$bistro, 'مطعم النهر', 2, 2], [$large, 'قاعة السوق', 20, 10]] as [$restaurant, $name, $categoryCount, $productCount]) {
        $restaurant->branding()->update(['display_name_ar' => $name]);
        for ($category = 1; $category <= $categoryCount; $category++) {
            $section = $restaurant->categories()->create([
                'name_en' => 'Course '.$category,
                'name_ar' => 'صنف '.$category,
                'sort_order' => $category,
                'is_visible' => true,
            ]);
            for ($product = 1; $product <= $productCount; $product++) {
                $restaurant->products()->create([
                    'category_id' => $section->id,
                    'name_en' => "Dish $category-$product",
                    'name_ar' => "طبق $category-$product",
                    'description_en' => 'Seasonal ingredients prepared fresh with grains, herbs, and vegetables.',
                    'description_ar' => 'مكونات موسمية طازجة مع الحبوب والأعشاب والخضروات.',
                    'price_amount' => '36.50',
                    'is_visible' => true,
                    'is_available' => $product !== $productCount,
                    'sort_order' => $product,
                ]);
            }
        }
    }
    $locked = Restaurant::query()->lockForUpdate()->findOrFail($large->id);
    app(Publication::class)->publish($locked, (string) $locked->menuSettings()->firstOrFail()->draft_revision);
});

fwrite(STDOUT, "Seeded the two live integration restaurants.\n");
