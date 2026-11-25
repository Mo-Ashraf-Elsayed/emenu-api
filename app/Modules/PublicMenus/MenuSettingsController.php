<?php

namespace App\Modules\PublicMenus;

use App\Modules\Restaurants\RestaurantLifecycle;
use App\Modules\Tenancy\ResolveTenant;
use App\Modules\Tenancy\Tenant;
use App\Shared\Http\Audit;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

final class MenuSettingsController
{
    public function show(Request $request): MenuSettingsResource
    {
        $tenant = ResolveTenant::current($request);

        return new MenuSettingsResource($tenant->restaurant->menuSettings()->firstOrFail());
    }

    public function update(MenuSettingsRequest $request): MenuSettingsResource
    {
        $settings = ResolveTenant::write($request, function (Tenant $tenant) use ($request): MenuSettings {
            Gate::authorize('menu-edit', $tenant);
            $settings = $tenant->restaurant->menuSettings()->firstOrFail();
            $settings->forceFill(['default_locale' => $request->value('defaultLocale')]);
            if ($settings->isDirty()) {
                $settings->draft_revision++;
                $settings->save();
            }

            return $settings;
        });

        return new MenuSettingsResource($settings);
    }

    public function publish(MenuSettingsRequest $request, Publication $publication): MenuSettingsResource
    {
        $settings = ResolveTenant::write($request, function (Tenant $tenant) use ($request, $publication): MenuSettings {
            Gate::authorize('menu-edit', $tenant);

            return $publication->publish($tenant->restaurant, $request->value('expectedDraftRevision'));
        });
        $tenant = ResolveTenant::current($request);
        Audit::record($request, $tenant->actor->id, 'menu.published', $tenant->restaurant->id);

        return new MenuSettingsResource($settings);
    }

    public function unpublish(Request $request, RestaurantLifecycle $lifecycle): Response
    {
        $tenant = ResolveTenant::write($request, function (Tenant $tenant) use ($lifecycle): Tenant {
            Gate::authorize('menu-edit', $tenant);
            $lifecycle->clearPublication($tenant->restaurant);

            return $tenant;
        });
        Audit::record($request, $tenant->actor->id, 'menu.unpublished', $tenant->restaurant->id);

        return response()->noContent();
    }

    public function qr(Request $request): Response
    {
        $tenant = ResolveTenant::current($request);
        Gate::authorize('menu-edit', $tenant);
        $tenant->restaurant->menuSettings()->whereNotNull('published_at')->firstOrFail();

        $slug = $tenant->restaurant->slug;
        $url = rtrim((string) config('app.url'), '/').'/menu/'.$slug;
        $png = (new Writer(new GDLibRenderer(400, 4)))->writeString($url);

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'attachment; filename="'.$slug.'-menu.png"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
