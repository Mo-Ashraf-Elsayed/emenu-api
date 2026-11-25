<?php

namespace App\Modules\Restaurants;

use App\Modules\Branding\Branding;
use App\Modules\Categories\Category;
use App\Modules\Media\Media;
use App\Modules\Memberships\Invitation;
use App\Modules\Memberships\Membership;
use App\Modules\Products\Product;
use App\Modules\PublicMenus\MenuSettings;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Restaurant extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = ['name', 'slug', 'currency', 'status'];

    /** @return HasMany<Membership,$this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /** @return HasMany<Invitation,$this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /** @return HasMany<Category,$this> */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /** @return HasMany<Product,$this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** @return HasMany<Media,$this> */
    public function media(): HasMany
    {
        return $this->hasMany(Media::class);
    }

    /** @return HasOne<Branding,$this> */
    public function branding(): HasOne
    {
        return $this->hasOne(Branding::class);
    }

    /** @return HasOne<MenuSettings,$this> */
    public function menuSettings(): HasOne
    {
        return $this->hasOne(MenuSettings::class);
    }
}
