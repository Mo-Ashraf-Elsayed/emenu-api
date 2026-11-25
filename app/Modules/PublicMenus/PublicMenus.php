<?php

namespace App\Modules\PublicMenus;

use App\Modules\Media\Media;
use App\Modules\Restaurants\Restaurant;
use App\Shared\Http\ApiProblem;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** @phpstan-import-type Snapshot from BuildMenu */
final class PublicMenus
{
    /** @return array<string,mixed> */
    public function read(string $slug, ?string $lang): array
    {
        $restaurant = $this->restaurant($slug);
        $marker = $restaurant->menuSettings()->whereNotNull('published_at')
            ->select(['restaurant_id', 'publication_revision'])
            ->selectRaw("snapshot->>'defaultLocale' AS public_locale")->firstOrFail();

        $locale = $lang ?? $marker->public_locale;
        $key = 'menu:v1:'.$restaurant->id.':'.$marker->publication_revision.':'.$locale;
        try {
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (Throwable) {
            // The committed publication remains readable while the cache is unavailable.
        }
        $settings = $restaurant->menuSettings()->where('publication_revision', $marker->publication_revision)
            ->whereNotNull('published_at')->firstOrFail();
        if ($settings->snapshot === null || $settings->published_at === null) {
            throw new ApiProblem(404, 'NOT_FOUND');
        }
        $menu = $this->project($slug, $locale, $settings->published_at->format('Y-m-d\TH:i:s.u\Z'), $settings->snapshot);
        try {
            Cache::put($key, $menu, 600);
        } catch (Throwable) {
            // A subsequent request can read the same revision from PostgreSQL.
        }

        return $menu;
    }

    public function media(string $slug, string $id, string $variant): Media
    {
        $restaurant = $this->restaurant($slug);
        $id = strtolower($id);
        if (! $restaurant->menuSettings()->whereNotNull('published_at')
            ->whereRaw('jsonb_exists(jsonb_extract_path(snapshot, ?, ?), ?)', ['assets', $id, $variant])->exists()) {
            throw new ApiProblem(404, 'NOT_FOUND');
        }

        return $restaurant->media()->where('status', 'ready')->findOrFail($id);
    }

    private function restaurant(string $slug): Restaurant
    {
        return Restaurant::query()->where('slug', $slug)->where('status', 'active')->firstOrFail();
    }

    /** @param Snapshot $snapshot
     * @return array<string,mixed>
     */
    private function project(string $slug, string $locale, string $publishedAt, array $snapshot): array
    {
        $images = static function (?string $id) use ($slug, $snapshot): array {
            return $id === null ? [] : Media::describe(
                $snapshot['assets'][$id] ?? [], '/api/v1/public/menus/'.$slug.'/media/'.$id,
            );
        };
        $categories = [];
        foreach ($snapshot['categories'] as $category) {
            $products = [];
            foreach ($category['products'] as $product) {
                $products[] = [
                    'id' => $product['id'],
                    'name' => $product['name'][$locale],
                    'description' => $product['description'][$locale],
                    'priceAmount' => $product['priceAmount'],
                    'isAvailable' => $product['isAvailable'],
                    'images' => array_map($images, $product['mediaIds']),
                ];
            }
            $categories[] = [
                'id' => $category['id'],
                'name' => $category['name'][$locale],
                'description' => $category['description'][$locale],
                'products' => $products,
            ];
        }

        return [
            'slug' => $slug,
            'currency' => $snapshot['currency'],
            'locale' => $locale,
            'defaultLocale' => $snapshot['defaultLocale'],
            'displayName' => $snapshot['displayName'][$locale],
            'description' => $snapshot['description'][$locale],
            'themeColor' => $snapshot['themeColor'],
            'logo' => $images($snapshot['logo']),
            'cover' => $images($snapshot['cover']),
            'categories' => $categories,
            'publishedAt' => $publishedAt,
        ];
    }
}
