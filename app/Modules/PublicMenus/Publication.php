<?php

namespace App\Modules\PublicMenus;

use App\Modules\Restaurants\Restaurant;
use App\Shared\Http\ApiProblem;

final class Publication
{
    public function __construct(private readonly BuildMenu $builder) {}

    /** The caller holds the restaurant lock. */
    public function publish(Restaurant $restaurant, string $expected): MenuSettings
    {
        $settings = $restaurant->menuSettings()->firstOrFail();
        if ((string) $settings->draft_revision !== $expected) {
            throw new ApiProblem(409, 'DRAFT_CHANGED');
        }
        if ($settings->published_source_revision === $settings->draft_revision) {
            return $settings;
        }

        $settings->forceFill([
            'snapshot' => $this->builder->build($restaurant, $settings->default_locale),
            'published_at' => now(),
            'published_source_revision' => $settings->draft_revision,
            'publication_revision' => $settings->publication_revision + 1,
        ])->save();

        return $settings;
    }
}
