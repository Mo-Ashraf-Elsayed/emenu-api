<?php

namespace App\Modules\Branding;

use App\Modules\Media\ReadyMedia;
use App\Modules\PublicMenus\MenuSettings;
use App\Modules\Tenancy\ResolveTenant;
use App\Modules\Tenancy\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class BrandingController
{
    public function show(Request $request): BrandingResource
    {
        $tenant = ResolveTenant::current($request);
        Gate::authorize('menu-edit', $tenant);

        return new BrandingResource($tenant->restaurant->branding()->firstOrFail());
    }

    public function update(BrandingRequest $request): BrandingResource
    {
        $model = ResolveTenant::write($request, function (Tenant $tenant) use ($request): Branding {
            Gate::authorize('menu-edit', $tenant);
            $draft = $tenant->restaurant->branding()->firstOrFail();
            $attributes = $request->attributesForDraft();
            foreach (['logoMediaId' => 'logo_media_id', 'coverMediaId' => 'cover_media_id'] as $field => $column) {
                if (is_string($attributes[$column] ?? null)) {
                    ReadyMedia::require($tenant->restaurant, [$attributes[$column]], $field);
                }
            }
            $draft->fill($attributes);
            if ($draft->isDirty()) {
                $draft->save();
                MenuSettings::advance($tenant->restaurant);
            }

            return $draft;
        });

        return new BrandingResource($model);
    }
}
