<?php

namespace App\Modules\PublicMenus;

use App\Modules\Restaurants\Restaurant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @phpstan-import-type Snapshot from BuildMenu
 *
 * @property Snapshot|null $snapshot
 * @property string $public_locale
 * @property CarbonImmutable|null $published_at
 */
final class MenuSettings extends Model
{
    protected $table = 'public_menu_settings';

    protected $primaryKey = 'restaurant_id';

    public $incrementing = false;

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'published_at' => 'immutable_datetime'];
    }

    /** The caller holds the restaurant lock. */
    public static function advance(Restaurant $restaurant): void
    {
        $restaurant->menuSettings()->increment('draft_revision');
    }
}
