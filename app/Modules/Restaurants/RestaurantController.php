<?php

namespace App\Modules\Restaurants;

use App\Modules\Identity\DashboardToken;
use App\Modules\Tenancy\ResolveTenant;
use App\Modules\Tenancy\Tenant;
use App\Shared\Http\Audit;
use App\Shared\Http\ListRequest;
use App\Shared\Http\ScopedCursor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class RestaurantController
{
    public function index(ListRequest $request): JsonResponse
    {
        $actor = DashboardToken::user($request);
        $cursor = new ScopedCursor($request, $actor->id);
        $query = Restaurant::query()->where('status', 'active')->withWhereHas(
            'memberships',
            fn ($memberships) => $memberships->where('user_id', $actor->id)->where('status', 'active'),
        );
        self::search($query, $request->filter('q'));
        $page = $query->orderBy('created_at', $request->direction())->orderBy('id', $request->direction())
            ->cursorPaginate($request->perPage(), cursor: $cursor->current());

        return response()->json([
            'data' => $page->getCollection()->map(fn (Restaurant $restaurant) => new RestaurantResource(
                $restaurant, $restaurant->memberships->firstOrFail()->role,
            )),
            'meta' => $cursor->metadata($page),
        ]);
    }

    public function store(RestaurantRequest $request, RestaurantLifecycle $lifecycle): JsonResponse
    {
        $actor = DashboardToken::user($request);
        $restaurant = $lifecycle->create($actor, $request->value('name'), $request->value('slug'), $request->value('currency'));
        Audit::record($request, $actor->id, 'restaurant.created', $restaurant->id);

        return (new RestaurantResource($restaurant, 'owner'))->response()->setStatusCode(201)
            ->header('Location', route('restaurants.show', ['restaurant' => $restaurant->id], absolute: false));
    }

    public function show(Request $request): RestaurantResource
    {
        $tenant = ResolveTenant::current($request);

        return new RestaurantResource($tenant->restaurant, $tenant->membership->role);
    }

    public function update(RestaurantRequest $request): RestaurantResource
    {
        $tenant = ResolveTenant::write($request, function (Tenant $tenant) use ($request): Tenant {
            Gate::authorize('tenant-owner', $tenant);
            $tenant->restaurant->update(['name' => $request->value('name')]);

            return $tenant;
        });
        Audit::record($request, $tenant->actor->id, 'restaurant.updated', $tenant->restaurant->id);

        return new RestaurantResource($tenant->restaurant, $tenant->membership->role);
    }

    public function archive(Request $request, RestaurantLifecycle $lifecycle): Response
    {
        $tenant = ResolveTenant::write($request, function (Tenant $tenant) use ($lifecycle): Tenant {
            Gate::authorize('tenant-owner', $tenant);
            $lifecycle->clearPublication($tenant->restaurant);
            $tenant->restaurant->delete();

            return $tenant;
        });
        Audit::record($request, $tenant->actor->id, 'restaurant.archived', $tenant->restaurant->id);

        return response()->noContent();
    }

    public function platformIndex(ListRequest $request): JsonResponse
    {
        $actor = DashboardToken::user($request);
        $cursor = new ScopedCursor($request, $actor->id);
        $query = Restaurant::query();
        self::search($query, $request->filter('q'));
        if (($status = $request->filter('status')) !== null) {
            $query->where('status', $status);
        }
        $page = $query->orderBy('created_at', $request->direction())->orderBy('id', $request->direction())
            ->cursorPaginate($request->perPage(), cursor: $cursor->current());

        return response()->json([
            'data' => PlatformRestaurantResource::collection($page->getCollection()),
            'meta' => $cursor->metadata($page),
        ]);
    }

    public function platformShow(string $restaurant): PlatformRestaurantResource
    {
        return new PlatformRestaurantResource(Restaurant::query()->findOrFail($restaurant));
    }

    public function platformStatus(RestaurantRequest $request, string $restaurant, RestaurantLifecycle $lifecycle): PlatformRestaurantResource
    {
        $actor = DashboardToken::user($request);
        $status = $request->value('status');
        $model = DB::transaction(function () use ($actor, $restaurant, $status, $lifecycle): Restaurant {
            $model = Restaurant::query()->lockForUpdate()->findOrFail($restaurant);
            Gate::forUser($actor->fresh())->authorize('platform-admin');
            if ($status === 'suspended') {
                $lifecycle->clearPublication($model);
            }
            $model->update(['status' => $status]);

            return $model;
        });
        Audit::record($request, $actor->id, 'platform.restaurant_'.$status, $model->id);

        return new PlatformRestaurantResource($model);
    }

    /** @param Builder<Restaurant> $query */
    private static function search(Builder $query, ?string $term): void
    {
        if ($term === null) {
            return;
        }

        $pattern = '%'.strtr(mb_strtolower($term), ['!' => '!!', '%' => '!%', '_' => '!_']).'%';
        $query->where(fn (Builder $nested) => $nested->whereRaw("lower(name) LIKE ? ESCAPE '!'", [$pattern])
            ->orWhereRaw("slug LIKE ? ESCAPE '!'", [$pattern]));
    }
}
