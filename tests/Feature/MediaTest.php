<?php

use App\Modules\Media\MediaWorkflow;
use App\Modules\Media\ReconcileMedia;
use App\Modules\Media\RunMedia;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\MenuFixtures;
use Tests\Support\PhaseTwo;
use Tests\Support\UsesTestDatabase;

uses(UsesTestDatabase::class);

it('processes private uploads and permits guest image access only through the current publication', function (): void {
    Storage::fake('local');
    Bus::fake([RunMedia::class]);
    config(['filesystems.menu_media' => 'local']);
    $owner = PhaseTwo::user('owner@example.test');
    $first = PhaseTwo::restaurant($owner, 'orchard');
    $second = PhaseTwo::restaurant($owner, 'seaside');
    PhaseTwo::login($this, $owner);
    $base = '/api/v1/restaurants/'.$first->id;
    $foreign = '/api/v1/restaurants/'.$second->id;

    $upload = MenuFixtures::upload($this, $base, UploadedFile::fake()->createWithContent('breakfast.png', MenuFixtures::image()))
        ->assertStatus(202)->assertJsonPath('data.status', 'pending')->assertJsonMissingPath('data.storageId')->assertJsonCount(0, 'data.variants');
    $id = $upload->json('data.id');
    $image = $first->media()->findOrFail($id);
    Storage::disk('local')->assertExists($image->sourceKey());
    $this->getJson($base.'/media/'.$id.'/variants/thumbnail')->assertNotFound();
    $this->patchJson($base.'/branding', ['logoMediaId' => $id])->assertUnprocessable();
    (new RunMedia($second->id, $id))->handle();
    $this->getJson($base.'/media/'.$id)->assertJsonPath('data.status', 'pending');

    $job = Bus::dispatched(RunMedia::class, fn (RunMedia $job) => $job->mediaId === $id)->sole();
    $job->handle();
    $job->handle();
    Storage::disk('local')->assertMissing($image->sourceKey());
    $media = $this->getJson($base.'/media?status=ready')->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.variants.0.width', 320)->assertJsonPath('data.0.variants.0.height', 200)
        ->assertJsonPath('data.0.variants.1.width', 640)->assertJsonPath('data.0.variants.1.height', 400);
    $privateUrl = $media->json('data.0.variants.0.url');
    $response = $this->get($privateUrl)->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect(substr($response->streamedContent(), 8, 4))->toBe('WEBP');
    $this->getJson($foreign.'/media/'.$id)->assertNotFound();
    $this->getJson($foreign.'/media/'.$id.'/variants/thumbnail')->assertNotFound();
    $this->patchJson($foreign.'/branding', ['logoMediaId' => $id])->assertUnprocessable();
    expect(fn () => DB::transaction(fn () => $second->branding()->update(['logo_media_id' => $id])))->toThrow(QueryException::class);

    $foreignCategory = MenuFixtures::category($this, $foreign);
    $foreignProduct = MenuFixtures::product($this, $foreign, $foreignCategory);
    $this->patchJson($foreign.'/products/'.$foreignProduct, ['mediaIds' => [$id]])->assertUnprocessable();
    expect(fn () => DB::transaction(fn () => DB::table('product_media')->insert([
        'restaurant_id' => $second->id, 'product_id' => $foreignProduct, 'media_id' => $id, 'sort_order' => 0,
    ])))->toThrow(QueryException::class);

    $publicImage = '/api/v1/public/menus/orchard/media/'.$id.'/thumbnail';
    $product = MenuFixtures::product($this, $base, MenuFixtures::category($this, $base), ['mediaIds' => [$id]]);
    $this->patchJson($base.'/branding', ['logoMediaId' => $id, 'coverMediaId' => $id])->assertOk();
    $this->getJson($publicImage)->assertNotFound();
    $this->deleteJson($base.'/media/'.$id)->assertConflict();
    MenuFixtures::publish($this, $base);
    $this->getJson('/api/v1/public/menus/orchard')->assertOk()->assertJsonPath('data.logo.0.url', $publicImage)
        ->assertJsonPath('data.categories.0.products.0.images.0.0.url', $publicImage);
    $public = $this->get($publicImage)->assertOk()->assertHeader('Content-Type', 'image/webp')->assertHeaderMissing('Set-Cookie');
    expect($public->streamedContent())->toBe(Storage::disk('local')->get($image->variantKey('thumbnail')));
    $this->getJson('/api/v1/public/menus/seaside/media/'.$id.'/thumbnail')->assertNotFound();
    $this->getJson(str_replace('thumbnail', 'original', $publicImage))->assertNotFound();

    $this->patchJson($base.'/branding', ['logoMediaId' => null, 'coverMediaId' => null])->assertOk();
    $this->patchJson($base.'/products/'.$product, ['mediaIds' => []])->assertOk();
    $this->deleteJson($base.'/media/'.$id)->assertConflict();
    $this->deleteJson($base.'/publication')->assertNoContent();
    $this->getJson($publicImage)->assertNotFound();
    $this->deleteJson($base.'/media/'.$id)->assertNoContent();
    $this->getJson($base.'/media/'.$id)->assertNotFound();
    $job->handle();
    Storage::disk('local')->assertMissing([$image->variantKey('thumbnail'), $image->variantKey('display')]);
    expect($image->fresh()->purged_at)->not->toBeNull();
});

it('rejects unsupported, animated, and oversized sources without exposing storage paths', function (): void {
    Storage::fake('local');
    Bus::fake([RunMedia::class]);
    $owner = PhaseTwo::user('owner@example.test');
    $restaurant = PhaseTwo::restaurant($owner, 'orchard');
    PhaseTwo::login($this, $owner);
    $base = '/api/v1/restaurants/'.$restaurant->id;
    $this->postJson($base.'/media', ['file' => 'https://example.test/file'])->assertUnsupportedMediaType();
    MenuFixtures::upload($this, $base, UploadedFile::fake()->createWithContent('menu.png', '<svg></svg>'))->assertUnprocessable();
    MenuFixtures::upload($this, $base, UploadedFile::fake()->create('oversized.png', 8193))->assertStatus(413);

    $png = MenuFixtures::image();
    $animation = 'acTL'.pack('NN', 2, 0);
    $insert = pack('N', 8).$animation.pack('N', crc32($animation));
    $apng = substr($png, 0, 33).$insert.substr($png, 33);
    MenuFixtures::upload($this, $base, UploadedFile::fake()->createWithContent('animated.png', $apng))->assertUnprocessable();
    Bus::assertNotDispatched(RunMedia::class);
    expect($restaurant->media()->count())->toBe(0);
});

it('processes JPEG and WebP by their actual bytes and reconciles stale uploads', function (): void {
    Storage::fake('local');
    Bus::fake([RunMedia::class]);
    $owner = PhaseTwo::user('owner@example.test');
    $restaurant = PhaseTwo::restaurant($owner, 'orchard');
    PhaseTwo::login($this, $owner);
    $workflow = new MediaWorkflow;
    foreach (['jpeg', 'webp'] as $format) {
        $bytes = MenuFixtures::image($format);
        $media = DB::transaction(fn () => $workflow->stage($restaurant, $bytes, $workflow->inspectUpload($bytes)));
        $media->forceFill(['updated_at' => now()->subMinutes(6)])->save();
        expect($media->mime_type)->toBe('image/'.$format);
    }

    Bus::assertNotDispatched(RunMedia::class);
    (new ReconcileMedia)();
    Bus::assertDispatchedTimes(RunMedia::class, 2);
    foreach (Bus::dispatched(RunMedia::class) as $job) {
        $job->handle();
    }
    expect($restaurant->media()->where('status', 'ready')->count())->toBe(2);
});
