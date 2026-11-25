<?php

namespace App\Modules\Media;

use App\Modules\Restaurants\Restaurant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property array<string,array{width:int,height:int}>|null $variants
 */
final class Media extends Model
{
    use HasUuids;

    public const VARIANTS = ['thumbnail' => 320, 'display' => 1280];

    protected $table = 'media';

    protected $fillable = ['storage_id', 'status', 'mime_type', 'byte_size', 'width', 'height'];

    protected $hidden = ['storage_id'];

    protected function casts(): array
    {
        return ['variants' => 'array', 'source_cleaned_at' => 'immutable_datetime', 'purged_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Restaurant,$this> */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function sourceKey(): string
    {
        return 'menu/quarantine/'.$this->restaurant_id.'/'.$this->storage_id;
    }

    public function variantKey(string $variant): string
    {
        return 'menu/variants/'.$this->restaurant_id.'/'.$this->storage_id.'/'.$variant.'.webp';
    }

    /**
     * @param  array<string,array{width:int,height:int}>  $variants
     * @return list<array{variant:string,mimeType:string,width:int,height:int,url:string}>
     */
    public static function describe(array $variants, string $baseUrl): array
    {
        $items = [];
        foreach (array_keys(self::VARIANTS) as $name) {
            if (isset($variants[$name])) {
                $items[] = [
                    'variant' => $name,
                    'mimeType' => 'image/webp',
                    'width' => $variants[$name]['width'],
                    'height' => $variants[$name]['height'],
                    'url' => $baseUrl.'/'.$name,
                ];
            }
        }

        return $items;
    }
}
