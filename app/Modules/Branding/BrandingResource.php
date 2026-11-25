<?php

namespace App\Modules\Branding;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Branding */
final class BrandingResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'displayName' => ['en' => $this->display_name_en, 'ar' => $this->display_name_ar],
            'description' => ['en' => $this->description_en, 'ar' => $this->description_ar],
            'themeColor' => $this->theme_color,
            'logoMediaId' => $this->logo_media_id,
            'coverMediaId' => $this->cover_media_id,
        ];
    }
}
