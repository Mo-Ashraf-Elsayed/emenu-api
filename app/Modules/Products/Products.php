<?php

namespace App\Modules\Products;

use App\Modules\Media\ReadyMedia;
use App\Modules\PublicMenus\MenuSettings;
use App\Modules\Restaurants\Restaurant;
use App\Shared\Http\ApiProblem;
use App\Shared\Http\LocalizedFields;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class Products
{
    /** @param array<string,mixed> $data */
    public function save(Restaurant $restaurant, Product $product, array $data): Product
    {
        if (! $product->exists && $restaurant->products()->count() >= 500) {
            throw new ApiProblem(409, 'CAPACITY_REACHED');
        }

        $attributes = LocalizedFields::attributes($data);
        foreach (['sortOrder' => 'sort_order', 'isVisible' => 'is_visible', 'isAvailable' => 'is_available'] as $field => $column) {
            if (array_key_exists($field, $data)) {
                $attributes[$column] = $data[$field];
            }
        }
        if (is_string($data['categoryId'] ?? null)) {
            $attributes['category_id'] = self::categoryId($restaurant, $data['categoryId']);
        }
        if (is_string($data['priceAmount'] ?? null)) {
            $attributes['price_amount'] = Price::validate($data['priceAmount'], $restaurant->currency);
        }

        $product->fill($attributes);
        $changed = ! $product->exists || $product->isDirty();
        if ($changed) {
            $product->save();
        }

        if (is_array($data['mediaIds'] ?? null)) {
            $ids = array_values(array_map('strtolower', $data['mediaIds']));
            ReadyMedia::require($restaurant, $ids, 'mediaIds');
            if ($ids !== $product->media()->pluck('media.id')->all()) {
                $this->replaceMedia($product, $ids);
                $changed = true;
            }
        }
        if ($changed) {
            MenuSettings::advance($restaurant);
        }

        return $product->load('media');
    }

    public static function categoryId(Restaurant $restaurant, string $id): string
    {
        $category = $restaurant->categories()->find($id);
        if ($category === null) {
            throw ValidationException::withMessages(['categoryId' => 'Select an available category.']);
        }

        return $category->id;
    }

    public function remove(Restaurant $restaurant, Product $product): void
    {
        $this->replaceMedia($product, []);
        $product->delete();
        MenuSettings::advance($restaurant);
    }

    /** @param list<string> $ids */
    private function replaceMedia(Product $product, array $ids): void
    {
        DB::table('product_media')->where('restaurant_id', $product->restaurant_id)->where('product_id', $product->id)->delete();
        if ($ids === []) {
            return;
        }

        $rows = [];
        foreach ($ids as $position => $id) {
            $rows[] = ['restaurant_id' => $product->restaurant_id, 'product_id' => $product->id, 'media_id' => $id, 'sort_order' => $position];
        }
        DB::table('product_media')->insert($rows);
    }
}
