<?php

namespace App\Modules\Branding;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string|null $logo_media_id
 * @property string|null $cover_media_id
 */
final class Branding extends Model
{
    protected $table = 'restaurant_branding';

    protected $primaryKey = 'restaurant_id';

    public $incrementing = false;

    protected $fillable = [
        'display_name_en', 'display_name_ar', 'description_en', 'description_ar',
        'theme_color', 'logo_media_id', 'cover_media_id',
    ];
}
