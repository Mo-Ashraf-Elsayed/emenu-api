<?php

namespace App\Modules\Media;

use App\Modules\Restaurants\Restaurant;
use App\Shared\Http\ApiProblem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

final class MediaWorkflow
{
    /** @param array{mime_type:string,byte_size:int,width:int,height:int} $metadata */
    public function stage(Restaurant $restaurant, string $bytes, array $metadata): Media
    {
        if ($restaurant->media()->where('status', '<>', 'deleted')->count() >= 1000) {
            throw new ApiProblem(409, 'CAPACITY_REACHED');
        }
        $model = $restaurant->media()->create($metadata + ['storage_id' => (string) Str::uuid(), 'status' => 'pending']);
        MediaStorage::put($model->sourceKey(), $bytes);

        return $model;
    }

    public function markDeleted(Restaurant $restaurant, Media $media): void
    {
        $id = $media->id;
        if (DB::table('product_media')->where('restaurant_id', $restaurant->id)->where('media_id', $id)->exists()
            || $restaurant->branding()->where(fn ($query) => $query->where('logo_media_id', $id)->orWhere('cover_media_id', $id))->exists()
            || $restaurant->menuSettings()->whereRaw("jsonb_exists(snapshot->'assets', ?)", [$id])->exists()) {
            throw new ApiProblem(409, 'RESOURCE_IN_USE');
        }
        $media->forceFill(['status' => 'deleted', 'variants' => null, 'failure_code' => null])->save();
    }

    /** @return array{mime_type:string,byte_size:int,width:int,height:int} */
    public function inspectUpload(string $bytes): array
    {
        try {
            return Raster::inspect($bytes);
        } catch (InvalidImage) {
            throw ValidationException::withMessages(['file' => 'Select a static JPEG, PNG or WebP image within the size limits.']);
        }
    }

    public static function enqueue(Media $media): void
    {
        try {
            RunMedia::dispatch($media->restaurant_id, $media->id)->afterCommit();
        } catch (Throwable) {
            Log::error('emenu.media.dispatch_failed', ['media_id' => $media->id]);
        }
    }
}
