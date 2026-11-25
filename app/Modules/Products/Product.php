<?php

namespace App\Modules\Products;

use App\Modules\Media\Media;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** @property string $price_amount */
final class Product extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'category_id', 'name_en', 'name_ar', 'description_en', 'description_ar',
        'sort_order', 'is_visible', 'is_available', 'price_amount',
    ];

    /** @var array<string,mixed> */
    protected $attributes = ['sort_order' => 0, 'is_visible' => false, 'is_available' => true];

    protected function casts(): array
    {
        return ['price_amount' => 'decimal:4'];
    }

    /** @return BelongsToMany<Media,$this> */
    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'product_media')->orderByPivot('sort_order');
    }
}
