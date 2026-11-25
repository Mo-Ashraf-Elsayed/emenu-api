<?php

use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\MenuFixtures;
use Tests\Support\PhaseTwo;
use Tests\Support\UsesTestDatabase;

uses(UsesTestDatabase::class);

it('validates bilingual catalog data and preserves currency precision and scoped cursors', function (): void {
    $manager = PhaseTwo::user('manager@example.test');
    $restaurant = PhaseTwo::restaurant($manager, 'orchard');
    PhaseTwo::login($this, $manager);
    $base = '/api/v1/restaurants/'.$restaurant->id;
    $this->postJson($base.'/categories', ['name' => ['en' => 'Incomplete']])->assertUnprocessable();
    $this->postJson($base.'/categories', ['name' => ['en' => 'Breakfast', 'ar' => 'الإفطار', 'fr' => 'Salut']])->assertUnprocessable();
    $this->postJson($base.'/categories', ['name' => ['en' => 'Breakfast', 'ar' => 'الإفطار'], 'isVisible' => 'true'])->assertUnprocessable();
    $category = MenuFixtures::category($this, $base);
    $other = MenuFixtures::category($this, $base, 'Bakery');
    $this->getJson($base.'/categories?isVisible=true')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson($base.'/categories?q=Break')->assertOk()->assertJsonPath('data.0.id', $category);
    $this->getJson($base.'/categories?q=%25%25')->assertOk()->assertJsonCount(0, 'data');
    $cursor = $this->getJson($base.'/categories?perPage=1')->assertOk()->json('meta.nextCursor');
    $this->getJson($base.'/categories?perPage=1&cursor='.urlencode($cursor))->assertOk()->assertJsonPath('data.0.id', $other);
    $this->getJson($base.'/categories?perPage=1&sort=createdAt&cursor='.urlencode($cursor))->assertUnprocessable();
    $this->getJson($base.'/categories?isVisible=true&isVisible=false')->assertUnprocessable();

    $baseProduct = ['name' => ['en' => 'Egg', 'ar' => 'بيض'], 'categoryId' => $category];
    $this->postJson($base.'/products', $baseProduct + ['priceAmount' => '1.001'])->assertUnprocessable();
    $this->postJson($base.'/products', $baseProduct + ['priceAmount' => 1.99])->assertUnprocessable();
    $this->postJson($base.'/products', $baseProduct + ['priceAmount' => '1e2'])->assertUnprocessable();
    $product = MenuFixtures::product($this, $base, $category);
    foreach (['priceAmount', 'categoryId', 'mediaIds'] as $field) {
        $this->patchJson($base.'/products/'.$product, [$field => ''])->assertUnprocessable()
            ->assertJsonValidationErrors($field, 'error.fields');
    }
    $this->getJson($base.'/products/'.$product)->assertOk()->assertJsonPath('data.priceAmount', '17.50');
    $this->getJson($base.'/products?categoryId='.$category.'&isAvailable=true')->assertOk()->assertJsonCount(1, 'data');
    $this->deleteJson($base.'/categories/'.$category)->assertConflict()->assertJsonPath('error.code', 'RESOURCE_IN_USE');
    $this->patchJson($base.'/categories/'.$category, [], ['Content-Type' => 'application/json'])->assertBadRequest();
    $this->patchJson($base.'/categories/'.$category, ['description' => ['en' => '', 'ar' => null]])->assertUnprocessable();
    $this->patchJson($base.'/categories/'.$category, ['description' => ['en' => 'All day', 'ar' => null]])
        ->assertOk()->assertJsonPath('data.description.en', 'All day')->assertJsonPath('data.description.ar', null);
    $this->deleteJson($base.'/products/'.$product)->assertNoContent();
    $this->getJson($base.'/products/'.$product)->assertNotFound();
    $this->deleteJson($base.'/categories/'.$category)->assertNoContent();
});

it('enforces distinct tenant roles and composite catalog relationships', function (): void {
    $owner = PhaseTwo::user('owner@example.test');
    $staff = PhaseTwo::user('staff@example.test');
    $first = PhaseTwo::restaurant($owner, 'orchard');
    $second = PhaseTwo::restaurant($owner, 'seaside');
    PhaseTwo::member($first, $staff, 'staff');
    PhaseTwo::member($second, $staff, 'manager');
    $base = '/api/v1/restaurants/'.$first->id;
    $foreign = '/api/v1/restaurants/'.$second->id;
    PhaseTwo::login($this, $staff);
    $foreignCategory = MenuFixtures::category($this, $foreign);
    $foreignProduct = MenuFixtures::product($this, $foreign, $foreignCategory);

    PhaseTwo::login($this, $owner);
    $category = MenuFixtures::category($this, $base);
    $product = MenuFixtures::product($this, $base, $category);
    $this->postJson($base.'/products', [
        'name' => ['en' => 'Egg', 'ar' => 'بيض'], 'categoryId' => $foreignCategory, 'priceAmount' => '1',
    ])->assertUnprocessable()->assertJsonValidationErrors('categoryId', 'error.fields');
    $this->getJson($base.'/products?categoryId='.$foreignCategory)->assertUnprocessable();
    expect(fn () => DB::transaction(fn () => $first->products()->whereKey($product)->update(['category_id' => $foreignCategory])))
        ->toThrow(QueryException::class);

    PhaseTwo::login($this, $staff);
    $this->getJson($base.'/categories/'.$category)->assertOk();
    $this->getJson($base.'/categories/'.$foreignCategory)->assertNotFound();
    $this->getJson($base.'/products/'.$foreignProduct)->assertNotFound();
    $this->patchJson($base.'/products/'.$foreignProduct.'/availability', ['isAvailable' => false])->assertNotFound();
    $this->patchJson($base.'/products/'.$product, ['isAvailable' => false])->assertForbidden();
    $this->patchJson($base.'/products/'.$product.'/availability', ['isAvailable' => false, 'priceAmount' => '0'])->assertUnprocessable();
    $this->patchJson($base.'/products/'.$product.'/availability', ['isAvailable' => false])->assertOk()->assertJsonPath('data.isAvailable', false);
    $this->getJson($base.'/branding')->assertForbidden();
    $this->getJson($base.'/media')->assertForbidden();
    $this->patchJson($base.'/menu-settings', ['defaultLocale' => 'ar'])->assertForbidden();
});

it('publishes a localized, isolated snapshot and invalidates the guest view on revision changes', function (): void {
    $owner = PhaseTwo::user('operator@example.test');
    $platform = PhaseTwo::user('platform@example.test', platform: true);
    $restaurant = PhaseTwo::restaurant($owner, 'orchard');
    $base = '/api/v1/restaurants/'.$restaurant->id;
    $public = '/api/v1/public/menus/orchard';
    $this->getJson($public)->assertNotFound()->assertHeaderMissing('Set-Cookie');
    PhaseTwo::login($this, $owner);
    $this->get($base.'/qr-code')->assertNotFound();
    $this->postJson($base.'/publication', ['expectedDraftRevision' => '1'])->assertUnprocessable()->assertJsonPath('error.code', 'MENU_NOT_PUBLISHABLE');
    $main = MenuFixtures::category($this, $base);
    $visible = MenuFixtures::product($this, $base, $main);
    MenuFixtures::product($this, $base, $main, ['isVisible' => false]);
    MenuFixtures::category($this, $base, 'Empty');
    $hiddenCategory = MenuFixtures::category($this, $base, 'Hidden', false);
    MenuFixtures::product($this, $base, $hiddenCategory);
    $this->patchJson($base.'/branding', [
        'themeColor' => '#008800', 'displayName' => ['en' => 'Orchard Kitchen', 'ar' => 'مطبخ البستان'],
    ])->assertOk();
    $this->patchJson($base.'/menu-settings', ['defaultLocale' => 'ar'])->assertOk();
    $this->postJson($base.'/publication', ['expectedDraftRevision' => '1'])->assertConflict()->assertJsonPath('error.code', 'DRAFT_CHANGED');
    expect(MenuFixtures::publish($this, $base))->toBe('1');
    expect(MenuFixtures::publish($this, $base))->toBe('1');
    $this->getJson($public)->assertOk()->assertHeaderMissing('Set-Cookie')->assertJsonPath('data.locale', 'ar')
        ->assertJsonPath('data.displayName', 'مطبخ البستان')->assertJsonCount(1, 'data.categories')
        ->assertJsonCount(1, 'data.categories.0.products')->assertJsonPath('data.categories.0.products.0.name', 'فول طازج')
        ->assertJsonMissingPath('data.assets')->assertJsonMissingPath('data.draftRevision')->assertJsonMissingPath('data.restaurantId');
    $en = $this->getJson($public.'?lang=en')->assertOk()->assertJsonPath('data.displayName', 'Orchard Kitchen');
    expect($en->headers->hasCacheControlDirective('no-store'))->toBeTrue();
    $this->getJson($public.'?lang=fr')->assertUnprocessable();
    $this->getJson($public.'?lang=en&lang=ar')->assertUnprocessable();
    $this->getJson($public.'?fake=ar')->assertUnprocessable();

    $this->patchJson($base.'/products/'.$visible.'/availability', ['isAvailable' => false])->assertOk();
    $this->getJson($public.'?lang=en')->assertOk()->assertJsonPath('data.categories.0.products.0.isAvailable', true);
    expect(MenuFixtures::publish($this, $base))->toBe('2');
    $this->getJson($public.'?lang=en')->assertOk()->assertJsonPath('data.categories.0.products.0.isAvailable', false);
    $unchanged = $this->getJson($base.'/menu-settings')->json('data.draftRevision');
    $this->patchJson($base.'/products/'.$visible, ['isAvailable' => false, 'priceAmount' => '17.50'])->assertOk();
    $this->getJson($base.'/menu-settings')->assertJsonPath('data.draftRevision', $unchanged);

    $qr = $this->withHeader('Host', 'irrelevant.example.test')->get($base.'/qr-code')->assertOk()
        ->assertHeader('Content-Type', 'image/png')->assertHeader('Content-Disposition', 'attachment; filename="orchard-menu.png"');
    expect($qr->getContent())->toBe((new Writer(new GDLibRenderer(400, 4)))
        ->writeString(rtrim((string) config('app.url'), '/').'/menu/orchard'));

    $this->deleteJson($base.'/publication')->assertNoContent();
    $this->deleteJson($base.'/publication')->assertNoContent();
    $this->getJson($public)->assertNotFound();
    $this->getJson($base.'/menu-settings')->assertJsonPath('data.publicationRevision', '3');
    expect(MenuFixtures::publish($this, $base))->toBe('4');
    PhaseTwo::login($this, $platform);
    $this->patchJson('/api/v1/platform/restaurants/'.$restaurant->id.'/status', ['status' => 'suspended'])->assertOk();
    $this->getJson($public)->assertNotFound();
    $this->patchJson('/api/v1/platform/restaurants/'.$restaurant->id.'/status', ['status' => 'active'])->assertOk();
    $this->getJson($public)->assertNotFound();
});

it('reads the committed menu when the projection cache fails', function (): void {
    $owner = PhaseTwo::user('owner@example.test');
    $restaurant = PhaseTwo::restaurant($owner, 'orchard');
    PhaseTwo::login($this, $owner);
    $base = '/api/v1/restaurants/'.$restaurant->id;
    MenuFixtures::product($this, $base, MenuFixtures::category($this, $base));
    MenuFixtures::publish($this, $base);

    Cache::partialMock()->shouldReceive('get')->with('menu:v1:'.$restaurant->id.':1:en')->once()->andThrow(new RuntimeException);
    Cache::partialMock()->shouldReceive('put')->once()->andThrow(new RuntimeException);
    $this->getJson('/api/v1/public/menus/orchard')->assertOk()->assertJsonPath('data.categories.0.products.0.name', 'Fresh beans');
});

it('rate limits anonymous menu lookups with a retry header', function (): void {
    for ($i = 0; $i < 120; $i++) {
        $this->getJson('/api/v1/public/menus/missing')->assertNotFound();
    }
    $this->getJson('/api/v1/public/menus/missing')->assertTooManyRequests()
        ->assertHeader('Retry-After')->assertJsonPath('error.code', 'RATE_LIMITED')->assertHeaderMissing('Set-Cookie');
});

it('shares guest quotas across trusted network paths and discards untrusted or invalid address hints', function (): void {
    config(['public_menu.trusted_proxy_ips' => ['192.0.2.10', '192.0.2.11']]);
    $path = '/api/v1/public/menus/missing';

    for ($i = 0; $i < 120; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => $i % 2 === 0 ? '192.0.2.10' : '::ffff:192.0.2.11'])
            ->getJson($path, ['X-Emenu-Client-IP' => '2001:db8::17'])->assertNotFound();
    }
    $this->getJson($path, ['X-Emenu-Client-IP' => '2001:0db8:0:0::17'])->assertTooManyRequests();
    $this->getJson($path, ['X-Emenu-Client-IP' => '2001:db8::18'])->assertNotFound();

    for ($i = 1; $i <= 120; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.12'])
            ->getJson($path, ['X-Emenu-Client-IP' => '198.51.100.'.$i])->assertNotFound();
    }
    $this->getJson($path, ['X-Emenu-Client-IP' => '198.51.100.121'])->assertTooManyRequests();

    for ($i = 0; $i < 120; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.10'])
            ->getJson($path, ['X-Emenu-Client-IP' => '198.51.100.90, 198.51.100.91'])->assertNotFound();
    }
    $this->getJson($path)->assertTooManyRequests();
});
