<?php

namespace App\Modules\PublicMenus;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MenuSettings */
final class MenuSettingsResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return [
            'defaultLocale' => $this->default_locale,
            'draftRevision' => (string) $this->draft_revision,
            'publicationRevision' => (string) $this->publication_revision,
            'publishedSourceRevision' => $this->published_source_revision === null ? null : (string) $this->published_source_revision,
            'publishedAt' => $this->published_at?->toISOString(),
        ];
    }
}
