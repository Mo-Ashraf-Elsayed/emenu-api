<?php

namespace App\Modules\Products;

use App\Modules\Restaurants\Currencies;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
final class ProductResource extends JsonResource
{
    public function __construct(Product $product, private readonly string $currency)
    {
        parent::__construct($product);
    }

    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => ['en' => $this->name_en, 'ar' => $this->name_ar],
            'description' => ['en' => $this->description_en, 'ar' => $this->description_ar],
            'sortOrder' => $this->sort_order,
            'isVisible' => $this->is_visible,
            'categoryId' => $this->category_id,
            'priceAmount' => Price::format($this->price_amount, Currencies::MINOR_UNITS[$this->currency]),
            'currency' => $this->currency,
            'isAvailable' => $this->is_available,
            'mediaIds' => $this->media->pluck('id')->all(),
        ];
    }
}
