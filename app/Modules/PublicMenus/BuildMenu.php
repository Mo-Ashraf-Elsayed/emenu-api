<?php

namespace App\Modules\PublicMenus;

use App\Modules\Media\Media;
use App\Modules\Products\Price;
use App\Modules\Products\Product;
use App\Modules\Restaurants\Currencies;
use App\Modules\Restaurants\Restaurant;
use App\Shared\Http\ApiProblem;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @phpstan-type Text array{en:?string,ar:?string}
 * @phpstan-type VariantSet array<string,array{width:int,height:int}>
 * @phpstan-type Item array{id:string,name:Text,description:Text,priceAmount:string,isAvailable:bool,mediaIds:list<string>}
 * @phpstan-type Section array{id:string,name:Text,description:Text,products:list<Item>}
 * @phpstan-type Snapshot array{currency:string,defaultLocale:string,displayName:Text,description:Text,themeColor:string,logo:?string,cover:?string,categories:list<Section>,assets:array<string,VariantSet>}
 */
final class BuildMenu
{
    /** @return Snapshot */
    public function build(Restaurant $restaurant, string $locale): array
    {
        $branding = $restaurant->branding()->firstOrFail();
        $assetIds = array_filter([$branding->logo_media_id, $branding->cover_media_id]);
        $scale = Currencies::MINOR_UNITS[$restaurant->currency];
        $categories = [];
        $draft = $restaurant->categories()->where('is_visible', true)->orderBy('sort_order')->orderBy('id')
            ->with(['products' => fn (HasMany $products) => $products->where('restaurant_id', $restaurant->id)
                ->where('is_visible', true)->orderBy('sort_order')->orderBy('id')->with('media')])->get();

        foreach ($draft as $category) {
            if ($category->products->isEmpty()) {
                continue;
            }
            $products = $category->products->map(function (Product $product) use ($scale, &$assetIds): array {
                $ids = array_values($product->media->map(fn (Media $image): string => $image->id)->all());
                array_push($assetIds, ...$ids);

                return [
                    'id' => $product->id,
                    'name' => ['en' => $product->name_en, 'ar' => $product->name_ar],
                    'description' => ['en' => $product->description_en, 'ar' => $product->description_ar],
                    'priceAmount' => Price::format($product->price_amount, $scale),
                    'isAvailable' => $product->is_available,
                    'mediaIds' => $ids,
                ];
            })->all();
            $categories[] = [
                'id' => $category->id,
                'name' => ['en' => $category->name_en, 'ar' => $category->name_ar],
                'description' => ['en' => $category->description_en, 'ar' => $category->description_ar],
                'products' => array_values($products),
            ];
        }
        if ($categories === []) {
            throw new ApiProblem(422, 'MENU_NOT_PUBLISHABLE');
        }

        $assets = [];
        $selected = array_unique($assetIds);
        $media = $restaurant->media()->whereIn('id', $selected)->where('status', 'ready')->get();
        foreach ($media as $image) {
            if ($image->variants === null || count(array_intersect_key($image->variants, Media::VARIANTS)) !== count(Media::VARIANTS)) {
                throw new ApiProblem(422, 'MENU_NOT_PUBLISHABLE');
            }
            $assets[$image->id] = $image->variants;
        }
        if (count($assets) !== count($selected)) {
            throw new ApiProblem(422, 'MENU_NOT_PUBLISHABLE');
        }

        $snapshot = [
            'currency' => $restaurant->currency,
            'defaultLocale' => $locale,
            'displayName' => ['en' => $branding->display_name_en, 'ar' => $branding->display_name_ar],
            'description' => ['en' => $branding->description_en, 'ar' => $branding->description_ar],
            'themeColor' => $branding->theme_color,
            'logo' => $branding->logo_media_id,
            'cover' => $branding->cover_media_id,
            'categories' => $categories,
            'assets' => $assets,
        ];
        if (strlen(json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) > 1024 * 1024) {
            throw new ApiProblem(409, 'CAPACITY_REACHED');
        }

        return $snapshot;
    }
}
