<?php

namespace App\Modules\Categories;

use App\Modules\Products\Product;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Category extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = ['name_en', 'name_ar', 'description_en', 'description_ar', 'sort_order', 'is_visible'];

    /** @var array<string,mixed> */
    protected $attributes = ['sort_order' => 0, 'is_visible' => false];

    /** @return HasMany<Product,$this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
