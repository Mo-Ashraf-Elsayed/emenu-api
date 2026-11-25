<?php

namespace App\Modules\Media;

use App\Modules\Restaurants\Restaurant;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class RunMedia implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 25;

    public int $tries = 6;

    public int $uniqueFor = 300;

    /** @var list<int> */
    public array $backoff = [15, 30, 60];

    public function __construct(public readonly string $restaurantId, public readonly string $mediaId) {}

    public function uniqueId(): string
    {
        return $this->restaurantId.':'.$this->mediaId;
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->uniqueId()))->releaseAfter(15)->expireAfter(120)];
    }

    public function handle(): void
    {
        $restaurant = Restaurant::withTrashed()->find($this->restaurantId);
        $media = $restaurant?->media()->find($this->mediaId);
        if ($restaurant === null || $media === null) {
            return;
        }
        if ($media->status === 'pending' && ! $restaurant->trashed() && $restaurant->status === 'active') {
            $this->process($media);
            $media->refresh();
        }
        if ($media->status === 'deleted' && $media->purged_at === null) {
            $this->purge($media);

            return;
        }
        $this->removeSource($media);
    }

    private function process(Media $media): void
    {
        try {
            $image = Raster::decode(MediaStorage::source($media));
        } catch (InvalidImage) {
            $this->recordResult('failed', null, 'invalid_image');

            return;
        }

        $variants = [];
        foreach (Media::VARIANTS as $name => $side) {
            $variant = Raster::variant($image, $side);
            MediaStorage::put($media->variantKey($name), $variant['bytes']);
            $variants[$name] = ['width' => $variant['width'], 'height' => $variant['height']];
        }
        $this->recordResult('ready', $variants, null);
    }

    /** @param array<string,array{width:int,height:int}>|null $variants */
    private function recordResult(string $status, ?array $variants, ?string $failure): void
    {
        DB::transaction(function () use ($status, $variants, $failure): void {
            $restaurant = Restaurant::query()->where('status', 'active')->lockForUpdate()->find($this->restaurantId);
            $media = $restaurant?->media()->where('status', 'pending')->find($this->mediaId);
            $media?->forceFill(['status' => $status, 'variants' => $variants, 'failure_code' => $failure])->save();
        });
    }

    private function removeSource(Media $media): void
    {
        if (! in_array($media->status, ['ready', 'failed'], true) || $media->source_cleaned_at !== null) {
            return;
        }
        $keys = [$media->sourceKey()];
        if ($media->status === 'failed') {
            foreach (array_keys(Media::VARIANTS) as $name) {
                $keys[] = $media->variantKey($name);
            }
        }
        MediaStorage::delete($keys);
        $this->finalizeCleanup(['ready', 'failed'], ['source_cleaned_at' => now()]);
    }

    private function purge(Media $media): void
    {
        $keys = [$media->sourceKey()];
        foreach (array_keys(Media::VARIANTS) as $name) {
            $keys[] = $media->variantKey($name);
        }
        MediaStorage::delete($keys);
        $this->finalizeCleanup(['deleted'], ['source_cleaned_at' => now(), 'purged_at' => now()]);
    }

    /** @param list<string> $statuses
     * @param  array<string,mixed>  $changes
     */
    private function finalizeCleanup(array $statuses, array $changes): void
    {
        DB::transaction(function () use ($statuses, $changes): void {
            $restaurant = Restaurant::withTrashed()->lockForUpdate()->find($this->restaurantId);
            $restaurant?->media()->whereKey($this->mediaId)->whereIn('status', $statuses)->update($changes);
        });
    }

    public function failed(): void
    {
        $this->recordResult('failed', null, 'processing_failed');
        Log::warning('emenu.media.job_failed', ['media_id' => $this->mediaId]);
    }
}
