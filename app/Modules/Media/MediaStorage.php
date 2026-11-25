<?php

namespace App\Modules\Media;

use App\Shared\Http\ApiProblem;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class MediaStorage
{
    public static function disk(): Filesystem
    {
        $disk = config('filesystems.menu_media');
        if (! in_array($disk, ['local', 's3'], true)) {
            throw new ApiProblem(503, 'SERVICE_UNAVAILABLE');
        }

        return Storage::disk($disk);
    }

    public static function put(string $key, string $bytes): void
    {
        try {
            if (self::disk()->put($key, $bytes)) {
                return;
            }
        } catch (Throwable) {
            // Storage provider exceptions can contain private keys and connection data.
        }

        throw new ApiProblem(503, 'SERVICE_UNAVAILABLE');
    }

    public static function source(Media $media): string
    {
        try {
            $stream = self::disk()->readStream($media->sourceKey());
            if (is_resource($stream)) {
                try {
                    $bytes = stream_get_contents($stream, Raster::MAX_BYTES + 1);
                    if (is_string($bytes)) {
                        return $bytes;
                    }
                } finally {
                    fclose($stream);
                }
            }
        } catch (Throwable) {
            // Keep storage error details outside responses and queued failure messages.
        }

        throw new ApiProblem(503, 'SERVICE_UNAVAILABLE');
    }

    /** @param list<string> $keys */
    public static function delete(array $keys): void
    {
        try {
            if (self::disk()->delete($keys)) {
                return;
            }
        } catch (Throwable) {
            // Object cleanup is retried using the database record.
        }

        throw new ApiProblem(503, 'SERVICE_UNAVAILABLE');
    }

    public static function variant(Media $media, string $variant): StreamedResponse
    {
        if ($media->status !== 'ready' || ! isset($media->variants[$variant])) {
            throw new ApiProblem(404, 'NOT_FOUND');
        }
        try {
            $disk = self::disk();
            $key = $media->variantKey($variant);
            if (! $disk->exists($key)) {
                $stream = null;
            } else {
                $stream = $disk->readStream($key);
                if (! is_resource($stream)) {
                    throw new ApiProblem(503, 'SERVICE_UNAVAILABLE');
                }
            }
        } catch (Throwable) {
            throw new ApiProblem(503, 'SERVICE_UNAVAILABLE');
        }
        if (! is_resource($stream)) {
            throw new ApiProblem(404, 'NOT_FOUND');
        }

        return response()->stream(static function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, 200, ['Content-Type' => 'image/webp', 'X-Content-Type-Options' => 'nosniff']);
    }
}
